<?php
/**
 * 接口：DeepSeek AI 智能问答 · 流式版（SSE）
 * 请求地址：POST /api/ai/chat_stream.php
 * 入参JSON：{"message":"推荐几道辣菜","history":[]}
 * 返回：text/event-stream，逐段推送
 *
 * ============================================
 * 【为什么小程序要用这条链路，而不是原生 EventSource】
 *   微信小程序没有 EventSource，wx.request 也不支持真正的 SSE 解析。
 *   可行做法是 wx.request({ enableChunked: true }) + onChunkReceived
 *   —— 服务端仍然按 SSE 格式（text/event-stream）输出，客户端自己按
 *   「空行分隔事件」的规则解析。所以这个接口就是标准的 SSE，
 *   用浏览器 / curl 也能直接调试。
 * ============================================
 *
 * ============================================
 * 【事件格式】每个事件都是 data: 一行 JSON，以空行结束
 *
 *   data: {"type":"delta","text":"宫保"}      ← 增量文本，会来很多次
 *   data: {"type":"done","reply":"...","recommend_dishes":[...],"has_recommend":true}
 *   data: {"type":"error","msg":"..."}        ← 出错时只发这个
 *   data: [DONE]                              ← 结束标记（与 OpenAI 风格一致）
 *
 *   delta 只负责「显示得快」，推荐卡片要等全文出来才能确定
 *   （卡片是从完整回复里识别菜品名得到的，见 core/AiReply.php），
 *   所以卡片统一放在最后的 done 事件里。
 * ============================================
 *
 * ============================================
 * 【让输出真的是"流"的：三层缓冲都要关】
 *   1. PHP 自身的输出缓冲（php.ini 的 output_buffering）：用 ob_end_flush() 结束掉
 *   2. gzip 压缩（zlib.output_compression）：压缩算法天然要攒数据，必须关闭
 *   3. 反向代理的缓冲（nginx 的 proxy_buffering）：用 X-Accel-Buffering: no 关闭
 *   少关任何一层，用户都会看到「等好几秒，然后整段一次性蹦出来」，
 *   白做了流式。
 * ============================================
 *
 * ============================================
 * 【限流】与非流式接口共用同一套额度（core/AiRateLimit.php）——
 *        它们烧的是同一份 API 额度，分开计数等于给了双倍配额。
 *        注意：限流检查必须在切换到 SSE 输出之前完成，
 *        否则响应头已经发出去了，没法再返回 429 JSON。
 * ============================================
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requirePost();

$message = Request::str('message');
$history = Request::arr('history');

if (empty($message)) {
    Response::error('参数错误：message必填');
}

// 限流：必须在开始输出 SSE 之前，否则没法再返回 429 JSON
try {
    AiRateLimit::enforce();
} catch (AiRateLimitException $e) {
    AiRateLimit::rejectResponse($e);
}

// ======================================================================
// 切换到 SSE 输出模式
// ======================================================================

// gzip 必须关掉：压缩要攒够数据才输出，会彻底破坏流式
@ini_set('zlib.output_compression', '0');

// 结束 PHP 自己的输出缓冲（php.ini 里 output_buffering 通常是 4096）
while (ob_get_level() > 0) {
    @ob_end_flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');   // nginx 下关闭反代缓冲

// 让 echo 之后立刻真正写出去，而不是等脚本结束
ob_implicit_flush(true);

/**
 * 发送一个 SSE 事件并立即冲刷
 */
function sse_send(array $event)
{
    echo 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

// 先发一行注释，让客户端尽快拿到响应头、把「正在输入」的状态显示出来。
// 以冒号开头的行在 SSE 里是注释，客户端会忽略。
echo ": connected\n\n";
flush();

// ======================================================================
// 正式开始流式对话
// ======================================================================
try {
    $deepseek = new DeepSeek();

    $result = $deepseek->chatStream($message, $history, function ($delta) {
        sse_send(['type' => 'delta', 'text' => $delta]);
    });

    if (isset($result['error'])) {
        sse_send(['type' => 'error', 'msg' => 'AI服务异常：' . $result['error']]);
        echo "data: [DONE]\n\n";
        flush();
        exit;
    }

    $reply = $result['text'];

    // ==================================================================
    // 全文到手后才构建推荐卡片
    //
    // 卡片是从**完整回复**里识别菜名得到的，增量阶段拿不到全文，
    // 所以 delta 只负责「显示得快」，卡片统一放在 done 事件里。
    //
    // 这里与非流式接口调用的是同一份实现（core/AiCards.php）——
    // 原来两边各写了一份逐字重复的代码，很容易改一处忘一处。
    // ==================================================================
    $recommendDishes = AiCards::fromReply($reply, $message, $deepseek);

    sse_send([
        'type'             => 'done',
        'reply'            => $reply,
        'recommend_dishes' => $recommendDishes,
        'has_recommend'    => !empty($recommendDishes),
    ]);

    echo "data: [DONE]\n\n";
    flush();

} catch (Exception $e) {
    // 已经进入流式输出，不能再返回 JSON 错误码，只能用 error 事件告知。
    // 细节只在本机调试时回传，避免把内部信息（SQL 报错、文件路径）发给客户端 ——
    // 与 Response::serverError() 用的是同一套判断。
    Response::log('AI 流式对话失败', $e);

    sse_send([
        'type' => 'error',
        'msg'  => Response::isLocalRequest()
            ? '服务异常：' . $e->getMessage()
            : '服务异常，请稍后重试',
    ]);

    echo "data: [DONE]\n\n";
    flush();
}

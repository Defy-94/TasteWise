<?php
/**
 * 接口：DeepSeek AI 智能问答 · 流式版（SSE）
 *
 * 入参JSON：{"message":"推荐几道辣菜","history":[]}
 * 返回：text/event-stream，逐段推送
 * 请求地址：POST /api/ai/chat_stream.php
 * 它们烧的是同一份 API 额度，分开计数等于给了双倍配额。
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

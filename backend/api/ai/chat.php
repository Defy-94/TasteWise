<?php
/**
 * 接口：DeepSeek AI 智能问答（点餐助手对话）
 *
 * 入参JSON：{"message":"推荐几道辣菜","history":[]}
 * 返回：{"code":200,"data":{"reply":"...","recommend_dishes":[{...}],"has_recommend":true}}
 * 请求地址：POST /api/ai/chat.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requirePost();

$message = Request::str('message');
$history = Request::arr('history');

if (empty($message)) {
    Response::error('参数错误：message必填');
}

// 当前用户（可能未登录：AI 对话本身免登录，登录了才有历史可用）
$user = Auth::currentUser();
$userId = ($user && !empty($user['uid'])) ? (int) $user['uid'] : 0;

// 限流检查放在参数校验之后、调用 AI 之前：
// 参数错的请求不该消耗额度，但一旦要花 AI 的额度就必须先过闸。
try {
    AiRateLimit::enforce();
} catch (AiRateLimitException $e) {
    AiRateLimit::rejectResponse($e);
}

try {
    // ============================================
    // 1. 调用 DeepSeek 获取 AI 回复
    // ============================================
    $deepseek = new DeepSeek();

    // 顾客点餐历史（可选）：登录了才有，让「跟上次一样」这类提问能被理解
    $userContext = $userId > 0 ? UserOrderHistory::promptContext($userId) : '';
    $result      = $deepseek->chat($message, $history, $userContext);

    if (isset($result['error'])) {
        Response::error('AI服务异常：' . $result['error'], 500);
    }

    $reply = isset($result['choices'][0]['message']['content'])
        ? $result['choices'][0]['message']['content']
        : '抱歉，我暂时无法回答这个问题。';

    // ============================================
    // 2. 从回复文本里构建推荐卡片
    //
    // 这一步（取在售菜单 → 识别提到的菜品 → 必要时兜底做一次结构化推荐）
    // 与流式接口共用同一份实现。原来两边各写了一份逐字重复的代码，
    // 只要改一处忘一处，就会出现「非流式有卡片、流式没卡片」这种难查的不一致。
    // 实现见 core/AiCards.php（「在售」的判定在 core/DishRepo.php）。
    // ============================================
    $recommendDishes = AiCards::fromReply($reply, $message, $deepseek);

    // 「跟上次一样 / 我的常点」：菜品由后端按历史查出，AI 只负责说话
    $repeatOrder = null;
    $histIntent  = OrderHistoryCards::detectIntent($message);
    if ($userId > 0 && $histIntent !== '') {
        if ($histIntent === 'regular') {
            // 我的常点不是固定搭配：取点单次数前 4 道，用推荐卡片让顾客自己挑
            $cards = OrderHistoryCards::regularCards($userId);
            if (!empty($cards)) {
                $recommendDishes = $cards;
            }
        } else {
            // 跟上次一样：整单预览 + 一键下单，此时不再叠加推荐卡片
            $repeatOrder = OrderHistoryCards::build($userId, 'last');
            if ($repeatOrder !== null) {
                $recommendDishes = [];
            }
        }
    }

    // ============================================
    // 3. 返回统一格式
    // ============================================
    Response::success([
        'reply'            => $reply,
        'recommend_dishes' => $recommendDishes,          // 推荐菜品列表（可能为空）
        'has_recommend'    => !empty($recommendDishes),
        'repeat_order'     => $repeatOrder,              // 确认点餐卡片数据（可能为 null）
    ]);

} catch (Exception $e) {
    Response::serverError('AI请求失败', $e);
}

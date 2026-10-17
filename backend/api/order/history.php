<?php
/**
 * 接口：查询当前用户的点餐历史（用于「跟上次一样」「我的常点」）
 *
 * 入参JSON：{"intent":"last"}   last = 上次的订单；regular = 常点的菜
 * 返回：{"code":200,"data":{"card":{...} | null,"reply":"..."}}
 * 请求地址：POST /api/order/history.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requirePost();

// 历史属于个人数据，必须登录
$userId = Auth::requireLogin();

$intent = Request::str('intent', 'last');
if (!in_array($intent, ['last', 'regular'], true)) {
    Response::error('参数错误：intent 只能是 last 或 regular');
}

try {
    $card = OrderHistoryCards::build($userId, $intent);

    Response::success([
        'card'  => $card,                                  // null 表示没有可用历史
        'reply' => OrderHistoryCards::fallbackReply($intent, $card),
    ]);
} catch (Exception $e) {
    Response::serverError('查询点餐历史失败', $e);
}

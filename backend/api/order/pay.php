<?php
/**
 * 接口：订单支付
 *
 * 请求头：token: <登录令牌>
 * 入参JSON：{"order_id":10}
 * 请求地址：POST /api/order/pay.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requirePost();

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

$orderId = Request::int('order_id');

try {
    // 传 $userId 表示只能操作本人订单
    $result = OrderStatus::transition($orderId, OrderStatus::PAID, $userId);

    Response::success($result, '支付成功');

} catch (OrderStatusException $e) {
    // 业务错误：404 订单不存在或无权操作 / 409 状态冲突
    Response::error($e->getMessage(), $e->getHttpCode());

} catch (Exception $e) {
    Response::serverError('支付失败', $e);
}

<?php
/**
 * 接口：确认订单完成（取餐/收货完成）
 * 请求地址：POST /api/order/complete.php
 * 请求头：token: <登录令牌>
 * 入参JSON：{"order_id":10}
 * 返回：{"order_id":10,"status":2,"status_text":"已完成"}
 * 对应前端：我的订单页"确认完成"按钮
 *
 * 【状态流转】1（已支付） → 2（已完成）
 *            未支付的订单不可直接完成
 *            规则实现在 core/OrderStatus.php，本文件只负责鉴权、取参与出参。
 *
 * 【鉴权说明】操作时同时校验 order_id 与当前登录用户（防越权）。
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requirePost();

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

$orderId = Request::int('order_id');

try {
    // 传 $userId 表示只能操作本人订单
    $result = OrderStatus::transition($orderId, OrderStatus::DONE, $userId);

    Response::success($result, '订单已完成');

} catch (OrderStatusException $e) {
    // 业务错误：404 订单不存在或无权操作 / 409 状态冲突
    Response::error($e->getMessage(), $e->getHttpCode());

} catch (Exception $e) {
    Response::serverError('操作失败', $e);
}

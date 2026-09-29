<?php
/**
 * 接口：取消订单
 * 请求地址：POST /api/order/cancel.php
 * 请求头：token: <登录令牌>
 * 入参JSON：{"order_id":10}
 * 返回：{"order_id":10,"status":3,"status_text":"已取消","restored_items":2}
 * 对应前端：我的订单页"取消订单"按钮
 *
 * 【状态流转】0（待支付）或 1（已支付） → 3（已取消）
 *            已完成（2）的订单不可取消
 *            规则实现在 core/OrderStatus.php，本文件只负责鉴权、取参与出参。
 *
 * 【库存回滚】下单时已扣减库存，取消时按订单明细逐项恢复库存，
 *            整个过程在事务内完成，任一步失败则整体回滚。
 *
 * 【鉴权说明】取消时同时校验 order_id 与当前登录用户（防越权）。
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requirePost();

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

$orderId = Request::int('order_id');

try {
    // 传 $userId 表示只能操作本人订单
    $result = OrderStatus::transition($orderId, OrderStatus::CANCELED, $userId);

    Response::success($result, '订单已取消');

} catch (OrderStatusException $e) {
    // 业务错误：404 订单不存在或无权操作 / 409 状态冲突
    Response::error($e->getMessage(), $e->getHttpCode());

} catch (Exception $e) {
    Response::serverError('取消失败', $e);
}

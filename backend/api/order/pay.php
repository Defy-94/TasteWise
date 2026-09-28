<?php
/**
 * 接口：订单支付
 * 请求地址：POST /api/order/pay.php
 * 请求头：token: <登录令牌>
 * 入参JSON：{"order_id":10}
 * 返回：{"order_id":10,"order_no":"...","status":1,"status_text":"已支付"}
 * 对应前端：我的订单页"去支付"按钮
 *
 * 【状态流转】0（待支付） → 1（已支付）
 *            规则实现在 core/OrderStatus.php，本文件只负责鉴权、取参与出参。
 *
 * 【鉴权说明】操作时同时校验 order_id 与当前登录用户，
 *            因此无法通过猜测 order_id 支付/查看他人订单（防越权）。
 *
 * 【重要说明】当前为「模拟支付」：直接把订单标记为已支付，便于本地跑通业务流程。
 *            正式上线必须替换为微信支付：
 *            1. 本接口只负责调用微信统一下单，返回 prepay_id 与支付参数；
 *            2. 订单状态改为「已支付」必须由微信支付结果通知（回调）驱动；
 *            3. 回调中需验证签名，并做幂等处理（同一订单重复通知只生效一次）。
 *            切勿像现在这样由客户端直接请求即可标记支付成功。
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/OrderStatus.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

$input   = json_decode(file_get_contents('php://input'), true);
$orderId = isset($input['order_id']) ? intval($input['order_id']) : 0;

try {
    // 传 $userId 表示只能操作本人订单
    $result = OrderStatus::transition($orderId, OrderStatus::PAID, $userId);

    Response::success($result, '支付成功');

} catch (OrderStatusException $e) {
    // 业务错误：404 订单不存在或无权操作 / 409 状态冲突
    Response::error($e->getMessage(), $e->getHttpCode());

} catch (Exception $e) {
    Response::error('支付失败：' . $e->getMessage(), 500);
}

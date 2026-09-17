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
 * 
 * 【鉴权说明】操作时同时校验 order_id 与当前登录用户（防越权）。
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/Auth.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

$input   = json_decode(file_get_contents('php://input'), true);
$orderId = isset($input['order_id']) ? intval($input['order_id']) : 0;

if ($orderId <= 0) {
    Response::error('参数错误：order_id必填');
}

$statusTextMap = [0 => '待支付', 1 => '已支付', 2 => '已完成', 3 => '已取消'];

try {
    $db = DB::getInstance();
    
    // 1. 查询订单，且必须属于当前登录用户
    $order = $db->selectOne(
        'SELECT id, order_no, status FROM orders WHERE id = ? AND user_id = ?',
        [$orderId, $userId]
    );
    
    if (!$order) {
        Response::error('订单不存在或无权操作', 404);
    }
    
    // 2. 状态校验：只有已支付订单可以完成
    if ((int) $order['status'] !== 1) {
        $current = isset($statusTextMap[(int) $order['status']])
            ? $statusTextMap[(int) $order['status']]
            : '未知';
        Response::error('订单当前状态不可完成（当前：' . $current . '）', 409);
    }
    
    // 3. 原子更新：WHERE 中带上 status = 1，避免并发重复完成
    $affected = $db->execute(
        'UPDATE orders SET status = 2 WHERE id = ? AND user_id = ? AND status = 1',
        [$orderId, $userId]
    );
    
    if ($affected <= 0) {
        Response::error('订单状态已变更，请刷新后重试', 409);
    }
    
    Response::success([
        'order_id'    => $orderId,
        'order_no'    => $order['order_no'],
        'status'      => 2,
        'status_text' => '已完成',
    ], '订单已完成');
    
} catch (Exception $e) {
    Response::error('操作失败：' . $e->getMessage(), 500);
}

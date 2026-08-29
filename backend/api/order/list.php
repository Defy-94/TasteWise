<?php
/**
 * 接口：查询我的订单（支持按状态筛选）
 * 请求地址：GET /api/order/list.php?user_id=1&status=completed
 * 对应前端：用户主页订单卡片
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('仅支持GET请求');
}

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$status = isset($_GET['status']) ? trim($_GET['status']) : '';

if ($userId <= 0) {
    Response::error('参数错误：user_id必填');
}

try {
    $db = DB::getInstance();
    
    // 构建查询条件
    $where = 'WHERE user_id = ?';
    $params = [$userId];
    
    if (!empty($status)) {
        $where .= ' AND status = ?';
        $params[] = $status;
    }
    
    // 查询订单主表
    $orders = $db->select(
        "SELECT id, order_no, total_price, status, remark, created_at 
         FROM orders 
         {$where} 
         ORDER BY created_at DESC",
        $params
    );
    
    // 查询每个订单的明细（含菜品图片）
    foreach ($orders as &$order) {
        $items = $db->select(
            'SELECT oi.dish_name, oi.dish_price, oi.quantity, d.image_url
             FROM order_items oi
             LEFT JOIN dishes d ON oi.dish_id = d.id
             WHERE oi.order_id = ?',
            [$order['id']]
        );
        $order['items'] = $items;
    }
    
    Response::success($orders);
} catch (Exception $e) {
    Response::error('获取订单失败：' . $e->getMessage(), 500);
}
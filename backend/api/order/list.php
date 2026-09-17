<?php
/**
 * 接口：查询我的订单（支持按状态筛选）
 * 请求地址：GET /api/order/list.php
 * 请求地址：GET /api/order/list.php?status=2
 * 请求头：token: <登录令牌>
 * 对应前端：我的订单页、用户中心"已完成订单"
 * 
 * 【鉴权说明】只能查询自己的订单，客户端传入的 user_id 一律忽略。
 * 
 * 【状态参数】status 支持两种写法：
 *   - 数字：0=待支付 1=已支付 2=已完成 3=已取消（推荐）
 *   - 兼容写法：pending / paid / completed / cancelled
 * 不传 status 返回全部订单；传了非法值直接返回参数错误（不再静默查错数据）。
 * 
 * 【修复记录】原实现把 status 原样拼进 SQL，而前端曾传 "completed" 字符串，
 *            MySQL 会将其隐式转为 0，导致"查已完成订单"实际查出待支付订单。
 *            现已在入口做白名单归一化，并统一返回 status_text。
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/Auth.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('仅支持GET请求');
}

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

// 订单状态字典
$statusTextMap = [
    0 => '待支付',
    1 => '已支付',
    2 => '已完成',
    3 => '已取消',
];

// 兼容历史字符串写法
$statusAlias = [
    'pending'   => 0,
    'paid'      => 1,
    'completed' => 2,
    'cancelled' => 3,
];

// ===== 状态参数归一化与白名单校验 =====
$statusRaw = isset($_GET['status']) ? trim($_GET['status']) : '';
$status    = null;   // null 表示不筛选

if ($statusRaw !== '') {
    if (array_key_exists($statusRaw, $statusAlias)) {
        $status = $statusAlias[$statusRaw];
    } elseif (ctype_digit($statusRaw) && array_key_exists((int) $statusRaw, $statusTextMap)) {
        $status = (int) $statusRaw;
    } else {
        Response::error('参数错误：status 只能为 0-3 或 pending/paid/completed/cancelled');
    }
}

try {
    $db = DB::getInstance();
    
    // 构建查询条件（user_id 来自令牌，确保只能查自己的订单）
    $where  = 'WHERE user_id = ?';
    $params = [$userId];
    
    if ($status !== null) {
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
        
        // 统一转为整数，保证前端可用数字严格比较（如 wx:if="{{item.status === 0}}"）
        foreach ($items as &$item) {
            $item['quantity'] = (int) $item['quantity'];
        }
        unset($item);
        
        $order['items'] = $items;
        $order['id']     = (int) $order['id'];
        $order['status'] = (int) $order['status'];
        
        // 顺便返回状态文本，避免前端各自维护一份映射导致不一致
        $order['status_text'] = isset($statusTextMap[$order['status']])
            ? $statusTextMap[$order['status']]
            : '未知';
    }
    unset($order);   // 断开引用，避免后续误用
    
    Response::success($orders);
} catch (Exception $e) {
    Response::error('获取订单失败：' . $e->getMessage(), 500);
}

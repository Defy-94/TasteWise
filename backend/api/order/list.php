<?php
/**
 * 接口：查询我的订单（支持按状态筛选）
 *
 * 请求头：token: <登录令牌>
 * 对应前端：我的订单页、用户中心"已完成订单"
 * 请求地址：GET /api/order/list.php
 * 请求地址：GET /api/order/list.php?status=2
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requireGet();

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

// ============================================
// 顺手清理超时未支付的订单（懒清理，见 core/OrderExpiry.php）
//
// 放在这里是因为：顾客打开「我的订单」时，最该看到的就是真实状态 ——
// 而不是一笔明明早就超时、却还挂着「待支付」的订单。
// 顺便把被它占着的库存还回去。
// 只清理本人的订单（走 user_id 索引，开销很小）。
// ============================================
OrderExpiry::expireStale($userId);

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
$statusRaw = Request::queryStr('status');
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
    
    // ============================================
    // 分页
    //
    // 【为什么必须分页】订单是只增不减的数据：老用户迟早会积累成百上千单，
    // 一次性全量返回会让响应体越来越大、小程序渲染越来越卡，
    // 而且每单还要再查一次明细（N+1），开销是随订单数线性增长的。
    //
    // 【返回结构保持不变】仍然是数组，只是加了 page / per_page 入参。
    // 原因：前端与文档都已按数组约定，改成 {list,total} 会波及一片。
    // 判断「还有下一页」的方式：返回条数 == per_page 就说明可能还有，
    // 前端据此决定要不要继续上拉加载。
    //
    // 【排序为什么要带 id】只按 created_at DESC 排的话，
    // 同一秒创建的两笔订单顺序不固定，翻页时可能出现「上一页出现过、
    // 下一页又出现一次」或整条被跳过。加上 id DESC 作为tiebreaker 才是稳定排序。
    // ============================================
    $page    = Request::queryInt('page', 1, 1);
    $perPage = Request::queryInt('per_page', 10, 1, 50);
    $offset  = ($page - 1) * $perPage;

    // LIMIT/OFFSET 直接拼整数：占位符在原生预处理模式下传参容易踩到
    // 「MySQL 把 LIMIT 的参数当字符串」的坑，而这两个值已经过 queryInt
    // 夹取并强转成 int，不存在注入风险。
    $orders = $db->select(
        "SELECT id, order_no, total_price, status, remark, created_at
         FROM orders
         {$where}
         ORDER BY created_at DESC, id DESC
         LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
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
        // 状态归一化：兼容数字字符串（'0'-'3'）与旧版字符串写法（pending/paid/completed/cancelled）
        // 说明：orders.status 列类型为 varchar(20)，早期设计使用的是字符串状态名，
        //      这里统一归一化，避免旧数据（如 'completed'）被 (int) 转成 0 误显示为"待支付"。
        $rawStatus = (string) $order['status'];
        $order['status'] = array_key_exists($rawStatus, $statusAlias)
            ? $statusAlias[$rawStatus]
            : (int) $rawStatus;
        
        // 顺便返回状态文本，避免前端各自维护一份映射导致不一致
        $order['status_text'] = isset($statusTextMap[$order['status']])
            ? $statusTextMap[$order['status']]
            : '未知';
    }
    unset($order);   // 断开引用，避免后续误用
    
    Response::success($orders);
} catch (Exception $e) {
    Response::serverError('获取订单失败', $e);
}

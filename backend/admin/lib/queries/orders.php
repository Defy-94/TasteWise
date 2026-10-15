<?php
/**
 * 订单列表 · 查询层
 *
 * 原本 admin/index.php 里既有「解析筛选参数」「拼 SQL」「查库」，
 * 又有 180 行的 HTML 渲染。一个文件 339 行，改查询逻辑要在 HTML 中间找位置。
 */

if (!defined('TW_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 把 URL 上的筛选参数解析成 SQL 条件
 *
 * @param array $get 一般是 $_GET
 * @return array ['where' => string, 'params' => array, 'status' => string, 'kw' => string, 'from' => string, 'to' => string]
 *               where 为空串表示不筛选；后四个是回显到表单里的原值
 */
function admin_order_filters(array $get)
{
    $statusFilter = str_param($get, 'status', '', 5);
    $keyword      = str_param($get, 'kw', '', 50);
    $dateFrom     = str_param($get, 'from', '', 10);
    $dateTo       = str_param($get, 'to', '', 10);

    // 非法日期直接丢弃，避免拼出无意义的查询
    if (!is_date($dateFrom)) {
        $dateFrom = '';
    }
    if (!is_date($dateTo)) {
        $dateTo = '';
    }

    $where  = [];
    $params = [];

    $statusTextMap = OrderStatus::allText();

    if ($statusFilter !== '' && isset($statusTextMap[(int) $statusFilter])) {
        // status 列是 varchar，这里按字符串比较，避免依赖 MySQL 的隐式类型转换
        $where[]  = 'o.status = ?';
        $params[] = (string) (int) $statusFilter;
    }

    if ($keyword !== '') {
        // 订单号 / 备注 / 用户昵称 / 用户名 / 手机号 都参与匹配
        $like     = '%' . DB::likeEscape($keyword) . '%';
        $where[]  = '(o.order_no LIKE ? OR o.remark LIKE ? OR u.nickname LIKE ? OR u.username LIKE ? OR u.phone LIKE ?)';
        // 五个占位符，同一个 like 值绑五次（顺序必须与 SQL 里 ? 的出现顺序一致）
        for ($i = 0; $i < 5; $i++) {
            $params[] = $like;
        }
    }

    if ($dateFrom !== '') {
        $where[]  = 'o.created_at >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }

    if ($dateTo !== '') {
        $where[]  = 'o.created_at <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }

    return [
        'where'  => empty($where) ? '' : ('WHERE ' . implode(' AND ', $where)),
        'params' => $params,
        'status' => $statusFilter,
        'kw'     => $keyword,
        'from'   => $dateFrom,
        'to'     => $dateTo,
    ];
}

/**
 * 符合条件的订单总数（决定分页）
 */
function admin_order_count($whereSql, array $params)
{
    $row = DB::getInstance()->selectOne(
        "SELECT COUNT(*) AS n FROM orders o LEFT JOIN users u ON u.id = o.user_id $whereSql",
        $params
    );

    return (int) $row['n'];
}

/**
 * 查询一页订单（含用户信息与菜品件数）
 *
 * LIMIT/OFFSET 直接把整数拼进 SQL：占位符在原生预处理模式下传参
 * 容易被 MySQL 当成字符串而对 LIMIT 报错，而这两个值已经过 int_param
 * 夹取并强转成 int，不存在注入风险。
 */
function admin_order_page($whereSql, array $params, $page, $perPage)
{
    $offset = ((int) $page - 1) * (int) $perPage;

    return DB::getInstance()->select(
        "SELECT o.id, o.order_no, o.user_id, o.total_price, o.status, o.remark, o.created_at,
                u.nickname, u.username, u.phone,
                (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
         FROM orders o
         LEFT JOIN users u ON u.id = o.user_id
         $whereSql
         ORDER BY o.id DESC
         LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
        $params
    );
}

/**
 * 顶部总览统计（**不受筛选影响**，始终看全量）
 *
 * 营业额刻意排除已取消的订单（status = '3'）——
 * 取消的订单不该算进收入，否则数字会虚高。
 */
function admin_order_stats()
{
    return DB::getInstance()->selectOne(
        "SELECT COUNT(*) AS total,
                SUM(status = '0') AS pending,
                SUM(status = '1') AS paid,
                SUM(status = '2') AS done,
                SUM(status = '3') AS canceled,
                COALESCE(SUM(CASE WHEN status <> '3' THEN total_price ELSE 0 END), 0) AS revenue
         FROM orders"
    );
}

/** 今日新增订单数 */
function admin_order_today_count()
{
    $row = DB::getInstance()->selectOne(
        'SELECT COUNT(*) AS n FROM orders WHERE created_at >= CURDATE()'
    );

    return (int) $row['n'];
}

/**
 * 单个订单（含用户信息）
 * @return array|false
 */
function admin_order_find($orderId)
{
    return DB::getInstance()->selectOne(
        'SELECT o.id, o.order_no, o.user_id, o.total_price, o.status, o.remark,
                o.created_at, o.updated_at,
                u.nickname, u.username, u.phone, u.address, u.openid
         FROM orders o
         LEFT JOIN users u ON u.id = o.user_id
         WHERE o.id = ?',
        [(int) $orderId]
    );
}

/**
 * 订单明细（含菜品**当前**的上架与库存情况）
 *
 * order_items 里存的是下单当时的菜名与单价快照，
 * 所以菜品后来改名、改价甚至被删除，历史订单的金额都不会变。
 */
function admin_order_items($orderId)
{
    return DB::getInstance()->select(
        'SELECT oi.id, oi.dish_id, oi.dish_name, oi.dish_price, oi.quantity,
                d.id AS dish_exists, d.is_on_sale, d.stock AS current_stock
         FROM order_items oi
         LEFT JOIN dishes d ON d.id = oi.dish_id
         WHERE oi.order_id = ?
         ORDER BY oi.id',
        [(int) $orderId]
    );
}

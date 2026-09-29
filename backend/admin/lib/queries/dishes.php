<?php
/**
 * 菜品列表 · 查询层
 *
 * 与 admin/lib/queries/orders.php 同样的理由：把 SQL 从页面里挪出来，
 * 让页面只负责「取参数 → 调查询 → 渲染」。
 */

if (!defined('TW_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 把 URL 上的筛选参数解析成 SQL 条件
 *
 * @param array $get 一般是 $_GET
 * @return array ['where'=>, 'params'=>, 'category_id'=>, 'on_sale'=>, 'kw'=>]
 */
function admin_dish_filters(array $get)
{
    $categoryFilter = int_param($get, 'category_id', 0, 0);
    $saleFilter     = str_param($get, 'on_sale', '', 5);
    $keyword        = str_param($get, 'kw', '', 50);

    $where  = [];
    $params = [];

    if ($categoryFilter > 0) {
        $where[]  = 'd.category_id = ?';
        $params[] = $categoryFilter;
    }

    // 只接受 '0' / '1'，其它值当"全部"
    if ($saleFilter === '0' || $saleFilter === '1') {
        $where[]  = 'd.is_on_sale = ?';
        $params[] = $saleFilter;
    }

    if ($keyword !== '') {
        $like     = '%' . DB::likeEscape($keyword) . '%';
        $where[]  = '(d.name LIKE ? OR d.description LIKE ?)';
        $params[] = $like;
        $params[] = $like;
    }

    return [
        'where'       => empty($where) ? '' : ('WHERE ' . implode(' AND ', $where)),
        'params'      => $params,
        'category_id' => $categoryFilter,
        'on_sale'     => $saleFilter,
        'kw'          => $keyword,
    ];
}

/**
 * 菜品列表
 *
 * 菜品是「菜单级」数据（几十条），一次性全部列出即可，不做分页。
 */
function admin_dish_list($whereSql, array $params)
{
    return DB::getInstance()->select(
        "SELECT d.id, d.category_id, d.name, d.price, d.image_url, d.description,
                d.spiciness, d.is_recommend, d.stock, d.is_on_sale,
                c.name AS category_name
         FROM dishes d
         LEFT JOIN categories c ON c.id = d.category_id
         $whereSql
         ORDER BY d.category_id, d.id",
        $params
    );
}

/**
 * 顶部总览统计（不受筛选影响，始终看全量）
 */
function admin_dish_stats()
{
    return DB::getInstance()->selectOne(
        'SELECT COUNT(*) AS total,
                SUM(is_on_sale = 1) AS on_sale,
                SUM(is_on_sale = 0) AS off_sale,
                SUM(stock <= 0)     AS out_of_stock,
                SUM(is_recommend = 1) AS recommend
         FROM dishes'
    );
}

/** 分类下拉列表 */
function admin_category_options()
{
    return DB::getInstance()->select('SELECT id, name FROM categories ORDER BY sort_order DESC, id');
}

/** 单个菜品（含不存在的判断由调用方做） */
function admin_dish_find($dishId)
{
    return DB::getInstance()->selectOne('SELECT * FROM dishes WHERE id = ?', [(int) $dishId]);
}

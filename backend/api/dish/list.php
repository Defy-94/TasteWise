<?php
/**
 * 接口：获取菜品列表（支持按分类筛选）
 * 请求地址：GET /api/dish/list.php
 * 请求地址：GET /api/dish/list.php?category_id=1
 * 对应前端：首页菜品展示、分类页切换菜品
 *
 * 【只返回上架菜品】菜品是否上架由管理后台控制（dishes.is_on_sale），
 *                  下架菜品对小程序端完全不可见，因此这里必须过滤，
 *                  否则后台点了下架、小程序里还在卖。
 *                  库存为 0 的菜品仍然返回，前端会显示「已售完」，
 *                  这样顾客能看到这道菜存在、只是暂时没有。
 *
 * 【关于 is_on_sale 字段】它属于运营概念，只在管理后台出现，
 *                       不写进接口返回值，避免小程序端依赖它做判断。
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('仅支持GET请求');
}

$categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

try {
    $db = DB::getInstance();

    $sql = 'SELECT id, category_id, name, price, image_url, description, spiciness, is_recommend, stock
            FROM dishes
            WHERE is_on_sale = 1';

    $params = [];
    if ($categoryId > 0) {
        $sql .= ' AND category_id = ?';
        $params[] = $categoryId;
    }

    $sql .= ' ORDER BY id';

    $dishes = $db->select($sql, $params);

    Response::success($dishes);
} catch (Exception $e) {
    Response::error('获取菜品失败：' . $e->getMessage(), 500);
}

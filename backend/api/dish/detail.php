<?php
/**
 * 接口：获取单个菜品详情
 * 请求地址：GET /api/dish/detail.php?id=3
 * 返回：{菜品字段..., category_name, sold}
 * 对应前端：菜品详情页 pages/dishDetail
 *
 * 【鉴权】与菜品列表一致，无需登录即可浏览。
 *
 * 【下架处理】已下架菜品一律按「不存在」处理并返回 404。
 *            原因是顾客可能通过分享链接、收藏记录等途径直接打开旧链接，
 *            此时不应该还能看到并下单。运营概念 is_on_sale 不出现在返回值里。
 *
 * 【sold 字段】累计售出份数 = 该菜品在所有「未取消」订单中的数量之和。
 *            已取消订单不计入（取消时库存已加回，算作没卖出去）。
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requireGet();

$dishId = Request::queryInt('id');

if ($dishId <= 0) {
    Response::error('参数错误：id必填');
}

try {
    $db = DB::getInstance();

    // 1. 菜品本体 + 所属分类名（只取上架菜品；规则统一定义在 core/DishRepo.php）
    $dish = DishRepo::findVisibleWithCategory($dishId);

    if (!$dish) {
        Response::error('菜品不存在或已下架', 404);
    }

    // 2. 累计售出份数（订单状态 3 = 已取消，不计入）
    $soldRow = $db->selectOne(
        'SELECT COALESCE(SUM(oi.quantity), 0) AS sold
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE oi.dish_id = ? AND o.status <> ?',
        [$dishId, '3']
    );

    $dish['stock'] = (int) $dish['stock'];
    $dish['sold']  = (int) $soldRow['sold'];
    $dish['is_recommend'] = (int) $dish['is_recommend'];
    $dish['spiciness']    = (int) $dish['spiciness'];
    $dish['price']        = (float) $dish['price'];

    Response::success($dish);

} catch (Exception $e) {
    Response::serverError('获取菜品详情失败', $e);
}

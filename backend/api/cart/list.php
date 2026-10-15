<?php
/**
 * 接口：获取购物车列表
 *
 * 请求头：token: <登录令牌>
 * 对应前端：购物车页面
 * 请求地址：GET /api/cart/list.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requireGet();

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

try {
    $db = DB::getInstance();
    $cartList = $db->select(
        'SELECT c.id as cart_id, c.quantity, d.id as dish_id, d.name, d.price, d.image_url, d.description
         FROM cart c
         JOIN dishes d ON c.dish_id = d.id
         WHERE c.user_id = ?
         ORDER BY c.created_at DESC',
        [$userId]
    );
    
    Response::success($cartList);
} catch (Exception $e) {
    Response::serverError('获取购物车失败', $e);
}

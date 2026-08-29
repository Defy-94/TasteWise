<?php
/**
 * 接口：获取购物车列表
 * 请求地址：GET /api/cart/list.php?user_id=1
 * 对应前端：购物车页面
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('仅支持GET请求');
}

$userId = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($userId <= 0) {
    Response::error('参数错误：user_id必填');
}

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
    Response::error('获取购物车失败：' . $e->getMessage(), 500);
}
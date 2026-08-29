<?php
/**
 * 接口：移除购物车商品
 * 请求地址：POST /api/cart/remove.php
 * 入参JSON：{"cart_id":5}
 * 对应前端：购物车页面"删除"按钮
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

$input  = json_decode(file_get_contents('php://input'), true);
$cartId = isset($input['cart_id']) ? intval($input['cart_id']) : 0;

if ($cartId <= 0) {
    Response::error('参数错误：cart_id必填');
}

try {
    $db = DB::getInstance();
    $db->execute('DELETE FROM cart WHERE id = ?', [$cartId]);
    Response::success(null, '已移除');
} catch (Exception $e) {
    Response::error('操作失败：' . $e->getMessage(), 500);
}
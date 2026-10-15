<?php
/**
 * 接口：移除购物车商品
 *
 * 请求头：token: <登录令牌>
 * 入参JSON：{"cart_id":5}
 * 请求地址：POST /api/cart/remove.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requirePost();

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

$cartId = Request::int('cart_id');

if ($cartId <= 0) {
    Response::error('参数错误：cart_id必填');
}

try {
    $db = DB::getInstance();
    
    // 关键：WHERE 同时限定 id 与 user_id，确保只能删除自己的记录
    $affected = $db->execute(
        'DELETE FROM cart WHERE id = ? AND user_id = ?',
        [$cartId, $userId]
    );
    
    if ($affected <= 0) {
        Response::error('购物车记录不存在或无权操作', 404);
    }
    
    Response::success(null, '已移除');
} catch (Exception $e) {
    Response::serverError('操作失败', $e);
}

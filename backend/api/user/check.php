<?php
/**
 * 接口：校验用户是否有效（同时用于获取当前登录用户信息）
 *
 * 请求头：token: <登录令牌>
 * 对应前端：用户主页 onShow 时校验登录态是否仍有效
 * 请求地址：GET /api/user/check.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requireGet();

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

try {
    $db = DB::getInstance();
    
    $user = $db->selectOne(
        'SELECT id, openid, username, phone, nickname, avatar_url, address, created_at 
         FROM users 
         WHERE id = ?',
        [$userId]
    );
    
    if (!$user) {
        Response::error('用户不存在', 404);
    }
    
    Response::success($user);
} catch (Exception $e) {
    Response::serverError('校验失败', $e);
}

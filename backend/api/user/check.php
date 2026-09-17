<?php
/**
 * 接口：校验用户是否有效（同时用于获取当前登录用户信息）
 * 请求地址：GET /api/user/check.php
 * 请求头：token: <登录令牌>
 * 对应前端：用户主页 onShow 时校验登录态是否仍有效
 * 
 * 【鉴权说明】用户身份完全取自令牌，忽略客户端传入的任何 user_id，
 *            因此无法通过修改参数查看他人资料。
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/Auth.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('仅支持GET请求');
}

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
    Response::error('校验失败：' . $e->getMessage(), 500);
}

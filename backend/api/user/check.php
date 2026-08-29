<?php
/**
 * 接口：校验用户是否有效
 * 请求地址：GET /api/user/check.php?user_id=10001
 * 对应前端：用户主页 onShow 时校验缓存中的用户是否仍存在
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
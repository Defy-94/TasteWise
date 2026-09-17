<?php
/**
 * 接口：手机号登录
 * 请求地址：POST /api/user/login_phone.php
 * 入参JSON：{"phone":"13800138000"}
 * 返回：用户信息 + token（后续接口需在请求头携带 token 表明身份）
 * 对应前端：登录页面"登录"按钮
 * 
 * 【鉴权说明】本接口为登录入口，无需携带 token。
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/Auth.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

$input = json_decode(file_get_contents('php://input'), true);
$phone = isset($input['phone']) ? trim($input['phone']) : '';

if (empty($phone)) {
    Response::error('手机号不能为空');
}

if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
    Response::error('手机号格式不正确');
}

try {
    $db = DB::getInstance();
    
    // 查询该手机号是否已注册
    $user = $db->selectOne(
        'SELECT id, openid, username, phone, nickname, avatar_url, address, created_at 
         FROM users 
         WHERE phone = ?',
        [$phone]
    );
    
    if (!$user) {
        // 未注册，返回特殊错误码
        Response::error('该手机号未注册，请前往注册', 404);
    }
    
    // 签发登录令牌
    $user['token'] = Auth::issue($user['id'], $user['openid']);
    
    // 登录成功，返回用户信息
    Response::success($user, '登录成功');
    
} catch (Exception $e) {
    Response::error('登录失败：' . $e->getMessage(), 500);
}

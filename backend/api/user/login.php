<?php
/**
 * 接口：用户登录/注册（通过微信code换取openid，此处简化演示）
 * 请求地址：POST /api/user/login.php
 * 入参JSON：{"openid":"模拟的openid","nickname":"用户昵称","avatar_url":"头像URL"}
 * 对应前端：用户中心页面初始化、全局登录态
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

$input     = json_decode(file_get_contents('php://input'), true);
$openid    = isset($input['openid']) ? trim($input['openid']) : '';
$nickname  = isset($input['nickname']) ? trim($input['nickname']) : '微信用户';
$avatarUrl = isset($input['avatar_url']) ? trim($input['avatar_url']) : '';

if (empty($openid)) {
    Response::error('参数错误：openid必填');
}

try {
    $db = DB::getInstance();
    
    // 查询用户是否存在
    $user = $db->selectOne('SELECT * FROM users WHERE openid = ?', [$openid]);
    
    if ($user) {
        // 已存在则更新信息
        $db->execute(
            'UPDATE users SET nickname = ?, avatar_url = ? WHERE openid = ?',
            [$nickname, $avatarUrl, $openid]
        );
        $user['nickname']   = $nickname;
        $user['avatar_url'] = $avatarUrl;
    } else {
        // 不存在则创建
        $userId = $db->insert(
            'INSERT INTO users (openid, nickname, avatar_url) VALUES (?, ?, ?)',
            [$openid, $nickname, $avatarUrl]
        );
        $user = $db->selectOne('SELECT * FROM users WHERE id = ?', [$userId]);
    }
    
    Response::success($user, '登录成功');
} catch (Exception $e) {
    Response::error('登录失败：' . $e->getMessage(), 500);
}
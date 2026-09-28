<?php
/**
 * 管理后台退出登录
 *
 * 只接受 POST + CSRF 令牌。
 * 如果允许 GET，别人只要在任意页面放一张 <img src=".../logout.php">，
 * 管理员一浏览就被踢下线（CSRF 注销攻击）。
 */
define('TW_ADMIN', true);
require_once __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('退出登录需要通过 POST 请求');
}

AdminAuth::requireCsrf();

AdminAuth::logout();

flash_set('success', '已安全退出登录');

redirect('login.php');

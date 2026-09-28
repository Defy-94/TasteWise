<?php
/**
 * 管理后台统一引导文件
 *
 * 每个后台页面（login.php / index.php / dishes.php ...）开头这样写：
 *
 *     define('TW_ADMIN', true);
 *     require_once __DIR__ . '/lib/bootstrap.php';
 *
 * 先 define 再引入，是为了让 lib/ 下的文件能识别「我是被入口页面引入的」，
 * 从而在被人直接访问 URL 时拒绝执行。
 */

// 未通过入口页面引入（即被人直接访问本文件）时拒绝
if (!defined('TW_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/OrderStatus.php';
require_once __DIR__ . '/AdminAuth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/order_actions.php';

// 启动后台会话（幂等）
AdminAuth::startSession();

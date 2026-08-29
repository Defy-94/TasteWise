<?php
/**
 * 接口：获取菜品分类列表
 * 请求地址：GET /api/category/list.php
 * 对应前端：首页分类导航
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('仅支持GET请求');
}

try {
    $db = DB::getInstance();
    $categories = $db->select(
        'SELECT id, name, sort_order, icon_url FROM categories ORDER BY sort_order DESC'
    );
    Response::success($categories);
} catch (Exception $e) {
    Response::error('获取分类失败：' . $e->getMessage(), 500);
}
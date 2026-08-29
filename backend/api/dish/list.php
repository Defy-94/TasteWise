<?php
/**
 * 接口：获取菜品列表（支持按分类筛选）
 * 请求地址：GET /api/dish/list.php
 * 请求地址：GET /api/dish/list.php?category_id=1
 * 对应前端：首页菜品展示、分类页切换菜品
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('仅支持GET请求');
}

$categoryId = isset($_GET['category_id']) ? intval($_GET['category_id']) : 0;

try {
    $db = DB::getInstance();
    
    if ($categoryId > 0) {
        // 按分类筛选
        $dishes = $db->select(
            'SELECT id, category_id, name, price, image_url, description, spiciness, is_recommend, stock 
             FROM dishes 
             WHERE category_id = ?',
            [$categoryId]
        );
    } else {
        // 获取全部菜品
        $dishes = $db->select(
            'SELECT id, category_id, name, price, image_url, description, spiciness, is_recommend, stock 
             FROM dishes'
        );
    }
    
    Response::success($dishes);
} catch (Exception $e) {
    Response::error('获取菜品失败：' . $e->getMessage(), 500);
}
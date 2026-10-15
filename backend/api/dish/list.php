<?php
/**
 * 接口：获取菜品列表（支持按分类筛选、关键词搜索）
 *
 * 对应前端：首页菜品展示、分类切换、菜品搜索
 * 下架菜品对小程序端完全不可见。
 * 请求地址：GET /api/dish/list.php
 * 请求地址：GET /api/dish/list.php?category_id=1
 * 请求地址：GET /api/dish/list.php?keyword=汤
 * 请求地址：GET /api/dish/list.php?category_id=1&keyword=鸡     ← 两个条件同时生效
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requireGet();

$categoryId = Request::queryInt('category_id');
$keyword    = Request::queryStr('keyword');

// 关键词长度设个上限：既防止有人塞超长字符串做无意义的模糊匹配，
// 也避免展示层收到根本没人会输入的内容
if (mb_strlen($keyword, 'UTF-8') > 20) {
    Response::error('搜索关键词过长（最多 20 个字）');
}

try {
    $dishes = DishRepo::visibleList([
        'category_id' => $categoryId,
        'keyword'     => $keyword,
    ]);

    Response::success($dishes);
} catch (Exception $e) {
    Response::serverError('获取菜品失败', $e);
}

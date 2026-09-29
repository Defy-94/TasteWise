<?php
/**
 * 接口：获取菜品列表（支持按分类筛选、关键词搜索）
 * 请求地址：GET /api/dish/list.php
 * 请求地址：GET /api/dish/list.php?category_id=1
 * 请求地址：GET /api/dish/list.php?keyword=汤
 * 请求地址：GET /api/dish/list.php?category_id=1&keyword=鸡     ← 两个条件同时生效
 * 对应前端：首页菜品展示、分类切换、菜品搜索
 *
 * 【只返回上架菜品】菜品是否上架由管理后台控制（dishes.is_on_sale），
 *                  下架菜品对小程序端完全不可见。
 *                  库存为 0 的菜品仍然返回，前端会显示「已售完」——
 *                  整道菜突然消失会让顾客以为店里没这道菜。
 *                  这条规则（以及「推荐场景要额外排除售完」那条）统一在
 *                  core/DishRepo.php 里定义，避免各处抄一份然后走偏。
 *
 * 【关于 is_on_sale 字段】它属于运营概念，只在管理后台出现，
 *                       不写进接口返回值，避免小程序端依赖它做判断。
 *
 * 【搜索行为】keyword 同时匹配菜名与描述，且**菜名命中的排在前面**：
 *            只搜菜名会漏掉「辣」这类只在描述里出现的词（菜品名里没有「辣」字，
 *            但好几道菜的描述是「麻辣鲜香」）。
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

<?php
/**
 * 接口：DeepSeek AI智能推荐菜品
 * 请求地址：POST /api/dish/recommend.php
 * 入参JSON：{"preference":"想吃辣一点的菜"}
 * 对应前端：首页"AI推荐"按钮
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requirePost();

$preference = Request::str('preference');

// 限流：本接口每次调用都会真实消耗 DeepSeek 额度（见 core/AiRateLimit.php）
try {
    AiRateLimit::enforce();
} catch (AiRateLimitException $e) {
    AiRateLimit::rejectResponse($e);
}

try {
    // 只拿「上架 且 有库存」的菜。
    // ⚠️ 这里原来只判了上架、漏了库存 —— 结果会把已经售完的菜推荐给顾客，
    //    点进去才发现点不了。现在条件统一定义在 core/DishRepo.php 里。
    $allDishes = DishRepo::sellableList();

    // 调用DeepSeek推荐
    $deepseek = new DeepSeek();
    $result = $deepseek->recommend($allDishes, $preference);

    // AI 只返回菜品ID与理由，其余字段从菜单里补齐；
    // ID 对不上菜单的一律丢弃（AI 编造菜品的最后一道闸）
    $recommendations = AiCards::matchRecommendations(
        isset($result['recommendations']) ? $result['recommendations'] : [],
        $allDishes
    );

    Response::success([
        'recommendations' => $recommendations,
        'total'           => count($recommendations),
    ]);
} catch (Exception $e) {
    Response::serverError('AI推荐失败', $e);
}
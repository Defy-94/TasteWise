<?php
/**
 * 接口：DeepSeek AI智能推荐菜品
 * 请求地址：POST /api/dish/recommend.php
 * 入参JSON：{"preference":"想吃辣一点的菜"}
 * 对应前端：首页"AI推荐"按钮
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/DeepSeek.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

$input = json_decode(file_get_contents('php://input'), true);
$preference = isset($input['preference']) ? trim($input['preference']) : '';

try {
    $db = DB::getInstance();
    
    // 获取所有菜品数据
    $allDishes = $db->select(
        'SELECT id, category_id, name, price, description, spiciness, image_url FROM dishes'
    );
    
    // 调用DeepSeek推荐
    $deepseek = new DeepSeek();
    $result = $deepseek->recommend($allDishes, $preference);
    
    // 根据推荐ID匹配完整菜品信息
    $recommendations = [];
    if (!empty($result['recommendations'])) {
        foreach ($result['recommendations'] as $rec) {
            foreach ($allDishes as $dish) {
                if ($dish['id'] == $rec['id']) {
                    $dish['ai_reason'] = $rec['reason'];
                    $recommendations[] = $dish;
                    break;
                }
            }
        }
    }
    
    Response::success([
        'recommendations' => $recommendations,
        'total'           => count($recommendations),
    ]);
} catch (Exception $e) {
    Response::error('AI推荐失败：' . $e->getMessage(), 500);
}
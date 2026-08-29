<?php
/**
 * 接口：DeepSeek AI智能问答（客服对话）
 * 请求地址：POST /api/ai/chat.php
 * 入参JSON：{"message":"推荐几道辣菜","history":[]}
 * 返回：{"code":200,"data":{"reply":"为您推荐以下菜品","recommend_dishes":[{...}]}}
 */
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/DeepSeek.php';
require_once __DIR__ . '/../../core/DB.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

$input   = json_decode(file_get_contents('php://input'), true);
$message = isset($input['message']) ? trim($input['message']) : '';
$history = isset($input['history']) ? $input['history'] : [];

if (empty($message)) {
    Response::error('参数错误：message必填');
}

try {
    // ============================================
    // 1. 先调用 DeepSeek 获取AI回复
    // ============================================
    $deepseek = new DeepSeek();
    $result = $deepseek->chat($message, $history);
    
    if (isset($result['error'])) {
        Response::error('AI服务异常：' . $result['error'], 500);
    }
    
    $reply = $result['choices'][0]['message']['content'] ?? '抱歉，我暂时无法回答这个问题。';
    
    // ============================================
    // 2. 判断是否包含推荐意图，尝试提取推荐菜品
    // ============================================
    $recommendDishes = [];
    
    // 推荐意图关键词
    $recommendKeywords = ['推荐', '建议', '吃什么', '点餐', '菜', '好吃', '辣', '清淡', '招牌'];
    $hasRecommendIntent = false;
    
    foreach ($recommendKeywords as $keyword) {
        if (mb_strpos($message, $keyword) !== false) {
            $hasRecommendIntent = true;
            break;
        }
    }
    
    if ($hasRecommendIntent) {
        // 调用 DeepSeek 推荐接口获取结构化推荐
        $db = DB::getInstance();
        $allDishes = $db->select(
            'SELECT id, category_id, name, price, image_url, description, spiciness 
             FROM dishes 
             WHERE stock > 0'
        );
        
        $recommendResult = $deepseek->recommend($allDishes, $message);
        
        // 匹配完整菜品信息
        if (!empty($recommendResult['recommendations'])) {
            foreach ($recommendResult['recommendations'] as $rec) {
                foreach ($allDishes as $dish) {
                    if ($dish['id'] == $rec['id']) {
                        $dish['ai_reason'] = $rec['reason'];
                        $recommendDishes[] = $dish;
                        break;
                    }
                }
            }
        }
    }
    
    // ============================================
    // 3. 返回统一格式
    // ============================================
    Response::success([
        'reply'            => $reply,
        'recommend_dishes' => $recommendDishes,   // 推荐菜品列表（可能为空）
        'has_recommend'    => !empty($recommendDishes),
    ]);
    
} catch (Exception $e) {
    Response::error('AI请求失败：' . $e->getMessage(), 500);
}
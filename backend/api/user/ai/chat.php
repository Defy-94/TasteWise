<?php
/**
 * 接口：DeepSeek AI智能问答（客服对话）
 * 请求地址：POST /api/ai/chat.php
 * 入参JSON：{"message":"你好，有什么推荐的菜吗？","history":[]}
 * 对应前端：AI助手页面"发送"按钮
 */
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/DeepSeek.php';

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
    $deepseek = new DeepSeek();
    $result = $deepseek->chat($message, $history);
    
    if (isset($result['error'])) {
        Response::error('AI服务异常：' . $result['error'], 500);
    }
    
    $reply = $result['choices'][0]['message']['content'] ?? '抱歉，我暂时无法回答这个问题。';
    Response::success(['reply' => $reply]);
} catch (Exception $e) {
    Response::error('AI请求失败：' . $e->getMessage(), 500);
}
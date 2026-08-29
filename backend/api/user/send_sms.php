<?php
/**
 * 接口：发送短信验证码（预留接口）
 * 请求地址：POST /api/user/send_sms.php
 * 入参JSON：{"phone":"13800138000"}
 */
require_once __DIR__ . '/../../core/Response.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

$input = json_decode(file_get_contents('php://input'), true);
$phone = isset($input['phone']) ? trim($input['phone']) : '';

if (empty($phone)) {
    Response::error('手机号不能为空');
}

// ============================================
// 【短信验证码发送逻辑 - 预留位置】
// 后续接入腾讯云短信或第三方服务时，在此处添加：
// 
// 1. 生成6位随机验证码
//    $code = rand(100000, 999999);
// 
// 2. 将验证码存入 Redis/数据库缓存（设置5分钟过期）
//    $redis->setex("sms_code_{$phone}", 300, $code);
// 
// 3. 调用短信服务商API发送验证码
//    腾讯云短信示例：
//    $sms = new TencentSms($secretId, $secretKey);
//    $sms->send($phone, $code);
// 
// 4. 发送成功返回，失败返回错误信息
// ============================================

// 临时返回提示
Response::error('验证码功能暂未开放', 501);
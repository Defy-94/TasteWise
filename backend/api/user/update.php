<?php
/**
 * 接口：修改个人信息
 * 请求地址：POST /api/user/update.php
 * 入参JSON：{"user_id":1,"nickname":"新昵称","phone":"138xxxx","address":"收货地址"}
 * 对应前端：用户中心页面"保存"按钮
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

$input   = json_decode(file_get_contents('php://input'), true);
$userId  = isset($input['user_id']) ? intval($input['user_id']) : 0;
$nickname = isset($input['nickname']) ? trim($input['nickname']) : '';
$phone   = isset($input['phone']) ? trim($input['phone']) : '';
$address = isset($input['address']) ? trim($input['address']) : '';

if ($userId <= 0) {
    Response::error('参数错误：user_id必填');
}

try {
    $db = DB::getInstance();
    
    $fields = [];
    $params = [];
    
    if (!empty($nickname)) {
        $fields[] = 'nickname = ?';
        $params[] = $nickname;
    }
    if ($phone !== '') {
        $fields[] = 'phone = ?';
        $params[] = $phone;
    }
    if ($address !== '') {
        $fields[] = 'address = ?';
        $params[] = $address;
    }
    
    if (empty($fields)) {
        Response::error('没有需要修改的字段');
    }
    
    $params[] = $userId;
    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $db->execute($sql, $params);
    
    // 返回更新后的用户信息
    $user = $db->selectOne('SELECT * FROM users WHERE id = ?', [$userId]);
    Response::success($user, '修改成功');
} catch (Exception $e) {
    Response::error('修改失败：' . $e->getMessage(), 500);
}
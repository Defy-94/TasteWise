<?php
/**
 * 接口：修改个人信息（仅能修改自己的信息）
 * 请求地址：POST /api/user/update.php
 * 请求头：token: <登录令牌>
 * 入参JSON：{"nickname":"新昵称","phone":"138xxxx","address":"收货地址"}
 * 对应前端：用户中心页面"保存"按钮
 * 
 * 【鉴权说明】修改目标用户取自令牌，客户端传入的 user_id 一律忽略，
 *            因此无法通过修改参数篡改他人资料（防越权）。
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requirePost();

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

$nickname = Request::str('nickname');
$phone    = Request::str('phone');
$address  = Request::str('address');

try {
    $db = DB::getInstance();
    
    $fields = [];
    $params = [];
    
    if (!empty($nickname)) {
        $fields[] = 'nickname = ?';
        $params[] = $nickname;
    }
    if ($phone !== '') {
        // 手机号格式校验（与注册/登录保持一致）
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            Response::error('手机号格式不正确');
        }
        // 手机号唯一性校验，避免与他人冲突
        $exists = $db->selectOne(
            'SELECT id FROM users WHERE phone = ? AND id <> ?',
            [$phone, $userId]
        );
        if ($exists) {
            Response::error('该手机号已被其他账号使用', 409);
        }
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
    Response::serverError('修改失败', $e);
}

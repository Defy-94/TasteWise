<?php
/**
 * 接口：用户注册
 * 请求地址：POST /api/user/register.php
 * 入参JSON：{"username":"张三","phone":"13800138000","code":"123456"}
 * 对应前端：注册页面"注册"按钮
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/Auth.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

$input    = json_decode(file_get_contents('php://input'), true);
$username = isset($input['username']) ? trim($input['username']) : '';
$phone    = isset($input['phone']) ? trim($input['phone']) : '';
$code     = isset($input['code']) ? trim($input['code']) : '';  // 验证码（预留）

// 基本参数校验
if (empty($username)) {
    Response::error('用户名不能为空');
}
if (empty($phone)) {
    Response::error('手机号不能为空');
}

// 手机号格式校验
if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
    Response::error('手机号格式不正确');
}

// 用户名长度校验
if (mb_strlen($username) < 2 || mb_strlen($username) > 20) {
    Response::error('用户名长度需为2-20个字符');
}

// ============================================
// 【短信验证码校验 - 预留位置】
// 接入短信服务后，在此处添加以下逻辑：
// 1. 从缓存/数据库读取该手机号对应的验证码
// 2. 比对用户提交的 code 是否一致
// 3. 检查验证码是否过期（通常5分钟）
// 4. 校验通过继续注册流程，失败返回错误
// ============================================

try {
    $db = DB::getInstance();
    
    // 检查用户名是否已存在
    $existUser = $db->selectOne('SELECT id FROM users WHERE username = ?', [$username]);
    if ($existUser) {
        Response::error('用户名已存在，请更换');
    }
    
    // 检查手机号是否已存在
    $existPhone = $db->selectOne('SELECT id FROM users WHERE phone = ?', [$phone]);
    if ($existPhone) {
        // 返回特殊错误码，前端弹窗询问是否跳转登录
        Response::error('该手机号已注册，是否前往登录？', 409);
    }
    
    // 开启事务：获取自增ID + 创建用户
    $db->beginTransaction();
    
    try {
        // 1. 读取当前最大用户ID（加行锁防止并发）
        $counter = $db->selectOne(
            'SELECT current_value FROM user_id_counter WHERE counter_name = ? FOR UPDATE',
            ['user_id']
        );
        
        if (!$counter) {
            // 初始化计数器
            $db->insert(
                'INSERT INTO user_id_counter (counter_name, current_value) VALUES (?, 10000)',
                ['user_id']
            );
            $newUserId = 10001;
        } else {
            $newUserId = intval($counter['current_value']) + 1;
        }
        
        // 2. 更新计数器
        $db->execute(
            'UPDATE user_id_counter SET current_value = ? WHERE counter_name = ?',
            [$newUserId, 'user_id']
        );
        
        // 3. 生成一个模拟的 openid（正式环境通过微信登录获取）
        $openid = 'openid_' . $newUserId . '_' . md5($phone . time());
        
        // 4. 插入新用户
        $db->insert(
            'INSERT INTO users (id, openid, username, phone, nickname) VALUES (?, ?, ?, ?, ?)',
            [$newUserId, $openid, $username, $phone, $username]
        );
        
        // 5. 提交事务
        $db->commit();
        
        // 返回用户信息
        $userInfo = [
            'id'       => $newUserId,
            'openid'   => $openid,
            'username' => $username,
            'phone'    => $phone,
            'nickname' => $username,
            'avatar_url' => '',
            'address'  => '',
            'created_at' => date('Y-m-d H:i:s'),
        ];
        
        // 签发登录令牌：注册成功后即为登录状态
        $userInfo['token'] = Auth::issue($newUserId, $openid);
        
        Response::success($userInfo, '注册成功');
        
    } catch (Exception $e) {
        $db->rollback();
        Response::error('注册失败，请重试：' . $e->getMessage(), 500);
    }
    
} catch (Exception $e) {
    Response::error('注册失败：' . $e->getMessage(), 500);
}
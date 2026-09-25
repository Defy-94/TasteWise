<?php
/**
 * 接口：微信一键登录（用 wx.login 的 code 换取 openid）
 * 请求地址：POST /api/user/login.php
 * 入参JSON：{"code":"wx.login() 返回的 code","nickname":"昵称(可选)","avatar_url":"头像(可选)"}
 * 返回：用户信息 + token（后续接口需在请求头携带 token 表明身份）
 * 对应前端：登录页「微信一键登录」按钮
 * 
 * 【登录流程】
 *   1. 小程序调用 wx.login() 得到临时 code
 *   2. 本接口用 code 调用微信 code2Session 换取 openid
 *   3. 按 openid 查找用户，不存在则自动创建（登录即注册，无需单独注册流程）
 *   4. 签发登录令牌并返回
 * 
 * 【为什么不接受客户端传来的 openid】
 *   openid 是账号的唯一标识。若直接信任客户端传来的 openid，
 *   任何人只要知道别人的 openid 就能登录成对方账号（账号接管漏洞）。
 *   只有微信服务器签发的 openid 才可信，因此正式流程只接受 code。
 * 
 * 【本地开发调试通道】需同时满足以下两个条件才生效（默认关闭）：
 *   1. config/security.php 中 allow_dev_openid_login = true
 *   2. 请求来自本机回环地址（127.0.0.1 / ::1）
 *   线上服务器不会被回环地址访问，因此即便误开开关也不会留下后门。
 * 
 * 【鉴权说明】本接口为登录入口，无需携带 token。
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/Auth.php';
require_once __DIR__ . '/../../core/WeChat.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

$input     = json_decode(file_get_contents('php://input'), true);
$code      = isset($input['code']) ? trim($input['code']) : '';
$nickname  = isset($input['nickname']) ? trim($input['nickname']) : '微信用户';
$avatarUrl = isset($input['avatar_url']) ? trim($input['avatar_url']) : '';
$devOpenid = isset($input['openid']) ? trim($input['openid']) : '';

$openid = '';

try {
    if ($code !== '') {
        // ===== 正式流程：用微信 code 换取 openid =====
        try {
            $wechat = new WeChat();
        } catch (RuntimeException $e) {
            // 503：明确告知「微信登录未配置」，前端可据此走本地调试通道
            Response::error('微信登录未配置：' . $e->getMessage(), 503);
        }

        $result = $wechat->code2Session($code);
        if (isset($result['error'])) {
            Response::error('微信登录失败：' . $result['error'], 401);
        }

        $openid = $result['openid'];

    } elseif ($devOpenid !== '' && devOpenidLoginAllowed()) {
        // ===== 本地开发调试通道（默认关闭，且必须来自本机） =====
        $openid = $devOpenid;

    } else {
        Response::error('参数错误：code 必填（请在小程序端调用 wx.login 获取）');
    }

    $db = DB::getInstance();

    // 查询用户是否存在
    $user = $db->selectOne('SELECT * FROM users WHERE openid = ?', [$openid]);

    if ($user) {
        // 已存在则更新信息
        $db->execute(
            'UPDATE users SET nickname = ?, avatar_url = ? WHERE openid = ?',
            [$nickname, $avatarUrl, $openid]
        );
        $user['nickname']   = $nickname;
        $user['avatar_url'] = $avatarUrl;
    } else {
        // 不存在则自动创建（登录即注册）
        // ⚠️ users 表的 username / phone 上都建了唯一索引、且列默认值为空字符串，
        //    若 INSERT 时不给这两列赋值，第二个新用户就会因
        //    「Duplicate entry '' for key 'idx_phone'」而插入失败（接口返回 500）。
        //    因此：username 由 openid 派生（openid 唯一，故派生值不重复），
        //    phone 显式写 NULL（MySQL 唯一索引允许多个 NULL，不会互相冲突）。
        $username = 'wx_' . substr(md5($openid), 0, 8);
        $userId = $db->insert(
            'INSERT INTO users (openid, nickname, avatar_url, username, phone) VALUES (?, ?, ?, ?, NULL)',
            [$openid, $nickname, $avatarUrl, $username]
        );
        $user = $db->selectOne('SELECT * FROM users WHERE id = ?', [$userId]);
    }

    // 签发登录令牌：后续接口凭此令牌识别用户身份，不再信任客户端传入的 user_id
    $user['token'] = Auth::issue($user['id'], $user['openid']);

    Response::success($user, '登录成功');

} catch (Exception $e) {
    Response::error('登录失败：' . $e->getMessage(), 500);
}

/**
 * 是否允许「本地开发用 openid 直接登录」
 * 需同时满足：① config/security.php 开关为 true；② 请求来自本机回环地址
 * 
 * @return bool
 */
function devOpenidLoginAllowed()
{
    $file = __DIR__ . '/../../config/security.php';
    if (!is_file($file)) {
        return false;
    }

    $config = require $file;
    if (empty($config['allow_dev_openid_login'])) {
        return false;
    }

    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    return in_array($ip, ['127.0.0.1', '::1', 'localhost'], true);
}

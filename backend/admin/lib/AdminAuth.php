<?php
/**
 * 管理后台认证类（会话登录 + CSRF + 登录失败锁定）
 *
 * ============================================
 * 【设计要点】
 *
 * 1. 会话隔离
 *    独立的 Cookie 名（TWSESSID），且 Cookie 只作用于 /admin 路径，
 *    避免小程序接口那边万一用到 session 时互相干扰。
 *
 * 2. 防会话固定攻击（Session Fixation）
 *    登录成功、退出登录时都调用 session_regenerate_id(true) 换新的会话ID。
 *    否则攻击者可以先诱导受害者使用一个已知的会话ID，等受害者登录后
 *    直接拿这个ID冒充管理员。
 *    同时开启 use_strict_mode，拒绝使用服务端没发放过的会话ID。
 *
 * 3. 密码校验用 password_verify
 *    配置里存的是 bcrypt 哈希而不是明文，即使配置文件被读取也无法直接得到密码。
 *    用户名与密码都会被完整计算一遍再做与运算，不做短路求值，避免通过响应时间
 *    区分「用户名错」和「密码错」。
 *
 * 4. CSRF 防护
 *    所有「会改变数据」的请求一律用 POST，并校验一次性令牌。
 *    令牌用 hash_equals 做恒定时间比较。
 *
 * 5. 登录失败锁定
 *    计数按 IP 存到临时目录的文件里，而不是只存在 $_SESSION —— 只存会话的话，
 *    攻击者每失败一次就清掉 Cookie 重新来，计数永远是 1，等于没有防护。
 *    临时目录不可写时自动退化为会话计数（功能不中断，只是保护变弱）。
 * ============================================
 *
 * 【为什么这样写而不是用框架】
 *   本项目后端没有引入任何框架，后台也保持同样风格：能读懂的纯 PHP。
 * ============================================
 */

// 直接访问本文件（而不是被入口页面引入）时拒绝执行，防止源码/行为被探测。
// 这种保护不依赖 Apache 配置，因此在 nginx 下同样有效。
if (!defined('TW_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}

class AdminAuth
{
    /** 后台专用会话 Cookie 名 */
    const SESSION_NAME = 'TWSESSID';

    /** @var array|null 配置缓存 */
    private static $config = null;

    // ------------------------------------------------------------------
    // 配置
    // ------------------------------------------------------------------

    /**
     * 读取 config/admin.php
     * @throws RuntimeException 配置缺失
     */
    public static function config()
    {
        if (self::$config !== null) {
            return self::$config;
        }

        $file = __DIR__ . '/../../config/admin.php';
        if (!is_file($file)) {
            throw new RuntimeException('缺少配置文件 config/admin.php，请复制 admin.example.php 后填写管理员账号');
        }

        $config = require $file;
        if (empty($config['username']) || empty($config['password_hash'])) {
            throw new RuntimeException('config/admin.php 中的 username / password_hash 未配置');
        }

        // 补齐可选项，调用方不必到处写 isset
        $config += [
            'session_idle_timeout' => 7200,
            'max_login_attempts'   => 5,
            'lock_seconds'         => 300,
        ];

        self::$config = $config;
        return self::$config;
    }

    // ------------------------------------------------------------------
    // 会话
    // ------------------------------------------------------------------

    /**
     * 启动后台会话（幂等，重复调用无副作用）
     * 必须在任何输出之前调用
     */
    public static function startSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // 用严格的会话模式：只接受服务端发放过的会话ID，拒绝客户端自造的ID
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_name(self::SESSION_NAME);

        // PHP 7.3+ 支持数组形式的参数
        session_set_cookie_params([
            // path 限定到 /admin，这样小程序接口那边不会带上这个 Cookie
            'path'     => '/admin',
            'httponly' => true,                                       // 禁止 JS 读取，降低 XSS 窃取风险
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'samesite' => 'Lax',                                      // 缓解 CSRF
        ]);

        session_start();
    }

    /**
     * 当前是否已登录
     *
     * 注意：本方法带副作用 —— 每次调用会刷新「最后活动时间」，
     *      用于实现空闲超时。不要在纯判断场景反复调用造成误解。
     */
    public static function isLoggedIn()
    {
        if (empty($_SESSION['admin_logged_in'])) {
            return false;
        }

        $cfg  = self::config();
        $last = isset($_SESSION['admin_last_active']) ? (int) $_SESSION['admin_last_active'] : 0;

        // 空闲超时：超过指定时间没有任何操作则要求重新登录
        if ($last > 0 && (time() - $last) > (int) $cfg['session_idle_timeout']) {
            self::logout();
            return false;
        }

        $_SESSION['admin_last_active'] = time();
        return true;
    }

    /** 当前登录的管理员名 */
    public static function username()
    {
        return isset($_SESSION['admin_user']) ? $_SESSION['admin_user'] : '';
    }

    // ------------------------------------------------------------------
    // 登录 / 退出
    // ------------------------------------------------------------------

    /**
     * 尝试登录
     *
     * @param string $username
     * @param string $password
     * @return true|string 成功返回 true，失败返回给用户看的错误文本
     */
    public static function login($username, $password)
    {
        $cfg = self::config();

        // 1. 先看是否处于锁定中
        $remain = self::lockRemaining();
        if ($remain > 0) {
            return '登录失败次数过多，请 ' . ceil($remain / 60) . ' 分钟后再试';
        }

        // 锁定已过期则清零，给一组全新的尝试机会
        if (self::failCount() >= (int) $cfg['max_login_attempts']) {
            self::writeFailCount(0);
        }

        // 2. 校验（两项都完整计算，不短路，避免时间侧信道）
        $userOk = hash_equals((string) $cfg['username'], (string) $username);
        $passOk = password_verify((string) $password, (string) $cfg['password_hash']);

        if (!$userOk || !$passOk) {
            self::writeFailCount(self::failCount() + 1);
            // 稍微延迟，进一步降低脚本高速爆破的速率
            usleep(700000);   // 0.7 秒
            // 不区分「用户名错」还是「密码错」，避免帮攻击者确认用户名
            return '用户名或密码错误';
        }

        // 3. 登录成功
        self::writeFailCount(0);

        // 换会话ID，防会话固定攻击
        session_regenerate_id(true);

        $_SESSION['admin_logged_in']   = true;
        $_SESSION['admin_user']        = $cfg['username'];
        $_SESSION['admin_login_at']    = time();
        $_SESSION['admin_last_active'] = time();
        $_SESSION['csrf']              = bin2hex(random_bytes(32));

        return true;
    }

    /**
     * 退出登录
     * 保留会话本身（这样还能显示一条「已退出」提示），只清掉登录状态并换会话ID
     */
    public static function logout()
    {
        unset(
            $_SESSION['admin_logged_in'],
            $_SESSION['admin_user'],
            $_SESSION['admin_login_at'],
            $_SESSION['admin_last_active'],
            $_SESSION['csrf']
        );

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * 强制登录：未登录则跳转登录页
     * 会记住原始目标地址，登录后自动跳回去
     */
    public static function requireLogin()
    {
        if (self::isLoggedIn()) {
            return;
        }

        if (!isset($_SESSION['after_login'])) {
            $target = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
            $_SESSION['after_login'] = self::safeTarget($target);
        }

        header('Location: login.php');
        exit;
    }

    /**
     * 登录成功后应该跳回的地址
     */
    public static function consumeAfterLogin()
    {
        $target = isset($_SESSION['after_login']) ? $_SESSION['after_login'] : '';
        unset($_SESSION['after_login']);
        return self::safeTarget($target);
    }

    /**
     * 校验跳转目标是否安全
     *
     * 只接受本站 /admin/ 下的路径。
     * 特别要挡住 "//evil.com" 这种协议相对地址 —— 它会被浏览器当成
     * 另一个域名，形成开放重定向漏洞。
     */
    private static function safeTarget($target)
    {
        if (!is_string($target) || $target === '') {
            return 'index.php';
        }
        if (strpos($target, '//') === 0 || strpos($target, '/admin/') !== 0) {
            return 'index.php';
        }
        return $target;
    }

    // ------------------------------------------------------------------
    // CSRF
    // ------------------------------------------------------------------

    /** 取得（必要时生成）当前会话的 CSRF 令牌 */
    public static function csrfToken()
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    /** 生成表单里的隐藏域，直接 echo 到 HTML 中 */
    public static function csrfField()
    {
        return '<input type="hidden" name="_csrf" value="' . htmlspecialchars(self::csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * 校验 CSRF 令牌
     * @param string|null $token 不传则从 $_POST['_csrf'] 取
     */
    public static function verifyCsrf($token = null)
    {
        if ($token === null) {
            $token = isset($_POST['_csrf']) ? $_POST['_csrf'] : '';
        }

        if (empty($_SESSION['csrf']) || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($_SESSION['csrf'], $token);
    }

    /**
     * 强制校验 CSRF：不通过直接中断请求
     * 所有处理 POST 的入口都应先调用它
     */
    public static function requireCsrf()
    {
        if (!self::verifyCsrf()) {
            http_response_code(403);
            exit('CSRF 校验失败：请返回后台重新操作（若浏览器禁用了 Cookie，也会出现此提示）');
        }
    }

    // ------------------------------------------------------------------
    // 登录失败计数（按 IP，存临时目录）
    // ------------------------------------------------------------------

    /** 计数文件路径（按 IP 哈希分文件，避免 IP 直接出现在文件名里） */
    private static function failFile()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tw_admin_fail_' . md5($ip);
    }

    /** 读取失败次数（临时目录不可用时退化为会话计数） */
    private static function failCount()
    {
        $file = self::failFile();
        $raw  = @file_get_contents($file);

        if ($raw === false) {
            // 读不到文件：可能是第一次（正常）也可能是不允许写（退化）
            return isset($_SESSION['admin_fail_count']) ? (int) $_SESSION['admin_fail_count'] : 0;
        }

        $data = json_decode($raw, true);
        return is_array($data) && isset($data['count']) ? (int) $data['count'] : 0;
    }

    /** 写入失败次数 */
    private static function writeFailCount($count)
    {
        $ok = @file_put_contents(
            self::failFile(),
            json_encode(['count' => (int) $count, 'at' => time()]),
            LOCK_EX
        );

        if ($ok === false) {
            // 文件写不了就退回会话计数（保护变弱，但不影响正常使用）
            $_SESSION['admin_fail_count'] = (int) $count;
        }
    }

    /** 距离解锁还剩多少秒，0 表示未锁定 */
    private static function lockRemaining()
    {
        $cfg  = self::config();
        $file = self::failFile();
        $raw  = @file_get_contents($file);

        if ($raw === false) {
            return 0;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['count'], $data['at'])) {
            return 0;
        }

        if ((int) $data['count'] < (int) $cfg['max_login_attempts']) {
            return 0;
        }

        $remain = (int) $cfg['lock_seconds'] - (time() - (int) $data['at']);
        return $remain > 0 ? $remain : 0;
    }
}

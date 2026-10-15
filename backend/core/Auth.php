<?php
/**
 * 登录令牌的签发与校验（HMAC-SHA256 签名的自包含 Token，服务端免存储）
 * 接口用 Auth::requireLogin() 取用户 ID，杜绝「客户端传任意 user_id 操作他人数据」
 * 密钥：config/security.php 的 token_secret
 *
 * 令牌格式：base64url(payload) . base64url(hmac_sha256(payload, secret))
 * payload ：{"uid","openid","iat","exp"}
 * 携带方式：token: <令牌>（推荐）或 Authorization: Bearer <令牌>
 */
class Auth
{
    /** 令牌有效期（秒），默认 7 天 */
    const TOKEN_TTL = 604800;

    /** 缓存的签名密钥 */
    private static $secret = null;

    /** 读取签名密钥（配置缺失或未填写时抛异常） */
    private static function secret()
    {
        if (self::$secret !== null) {
            return self::$secret;
        }

        $file = __DIR__ . '/../config/security.php';
        if (!is_file($file)) {
            throw new RuntimeException('缺少配置文件 config/security.php，请复制 security.example.php 后填写 token_secret');
        }

        $config = require $file;
        if (empty($config['token_secret'])) {
            throw new RuntimeException('config/security.php 中的 token_secret 未配置');
        }

        self::$secret = $config['token_secret'];
        return self::$secret;
    }

    /**
     * base64url 编码（URL 安全，去掉填充符）
     */
    private static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * base64url 解码
     */
    private static function base64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /** 签发登录令牌 */
    public static function issue($userId, $openid = '')
    {
        $payload = [
            'uid'    => (int) $userId,
            'openid' => (string) $openid,
            'iat'    => time(),
            'exp'    => time() + self::TOKEN_TTL,
        ];

        $body = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
        $sign = self::base64UrlEncode(hash_hmac('sha256', $body, self::secret(), true));

        return $body . '.' . $sign;
    }

    /** 校验令牌：通过返回 payload，失败返回 null */
    public static function verify($token)
    {
        if (empty($token) || !is_string($token)) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        list($body, $sign) = $parts;

        // 使用 hash_equals 做恒定时间比较，防时序攻击
        $expect = self::base64UrlEncode(hash_hmac('sha256', $body, self::secret(), true));
        if (!hash_equals($expect, $sign)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($body), true);
        if (!is_array($payload) || empty($payload['uid'])) {
            return null;
        }

        // 过期校验
        if (empty($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    /** 从请求头提取令牌（支持 token 头与 Authorization: Bearer），找不到返回空串 */
    public static function tokenFromRequest()
    {
        // 方式一：token 请求头
        if (!empty($_SERVER['HTTP_TOKEN'])) {
            return trim($_SERVER['HTTP_TOKEN']);
        }

        // 方式二：Authorization: Bearer
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            return self::stripBearer($_SERVER['HTTP_AUTHORIZATION']);
        }

        // 部分环境（CGI/FastCGI）需要从 getallheaders 中读取
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                foreach ($headers as $key => $value) {
                    $lower = strtolower($key);
                    if ($lower === 'token') {
                        return trim($value);
                    }
                    if ($lower === 'authorization') {
                        return self::stripBearer($value);
                    }
                }
            }
        }

        return '';
    }

    /**
     * 去掉 Bearer 前缀
     */
    private static function stripBearer($value)
    {
        $value = trim($value);
        if (stripos($value, 'Bearer ') === 0) {
            return trim(substr($value, 7));
        }
        return $value;
    }

    /** 强制登录：校验令牌并返回用户 ID，无效时输出 401 并终止请求 */
    public static function requireLogin()
    {

        $token   = self::tokenFromRequest();
        $payload = self::verify($token);

        if (!$payload) {
            Response::error('未登录或登录已过期，请重新登录', 401);
        }

        return (int) $payload['uid'];
    }

    /** 获取当前登录用户信息（校验令牌但允许失败） */
    public static function currentUser()
    {
        $payload = self::verify(self::tokenFromRequest());
        return $payload ? $payload : null;
    }
}

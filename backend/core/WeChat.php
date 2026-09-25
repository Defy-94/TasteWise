<?php
/**
 * 微信小程序服务端接口封装
 * 
 * ============================================
 * 【当前实现】code2Session：用 wx.login 的 code 换取 openid
 * ============================================
 * 
 * 【为什么必须走这个接口】
 *   openid 是账号的唯一标识。如果直接信任客户端传来的 openid，
 *   任何人只要知道别人的 openid 就能登录成对方账号（账号接管漏洞）。
 *   通过 code2Session 换取的 openid 由微信服务器签发，客户端无法伪造。
 * 
 * 【安全提示】
 *   本类需要 AppSecret（小程序密钥），该值只能保存在服务端：
 *   - 配置文件：config/wechat.php（已被 .gitignore 忽略，不会上传）
 *   - 绝对不要写进小程序前端代码，也不要提交到 Git 仓库
 */
class WeChat
{
    /** code2Session 接口地址 */
    const CODE2SESSION_URL = 'https://api.weixin.qq.com/sns/jscode2session';

    private $appid;
    private $appsecret;

    /**
     * 构造函数：从配置文件加载 AppID / AppSecret
     * @throws RuntimeException 配置文件缺失或未填写
     */
    public function __construct()
    {
        $file = __DIR__ . '/../config/wechat.php';

        if (!is_file($file)) {
            throw new RuntimeException('缺少配置文件 config/wechat.php，请复制 wechat.example.php 后填写 appid 与 appsecret');
        }

        $config = require $file;

        if (empty($config['appid'])) {
            throw new RuntimeException('config/wechat.php 中的 appid 未配置');
        }
        if (empty($config['appsecret'])) {
            throw new RuntimeException('config/wechat.php 中的 appsecret 未配置（在小程序后台「开发管理 → 开发设置」中获取）');
        }

        $this->appid     = $config['appid'];
        $this->appsecret = $config['appsecret'];
    }

    /**
     * 用 wx.login 返回的临时 code 换取用户身份标识
     * 
     * @param string $code wx.login() 返回的 code（5 分钟有效，且只能使用一次）
     * @return array 成功：['openid'=>..,'session_key'=>..,'unionid'=>..]
     *               失败：['error'=>'错误说明']
     */
    public function code2Session($code)
    {
        $query = http_build_query([
            'appid'      => $this->appid,
            'secret'     => $this->appsecret,
            'js_code'    => $code,
            'grant_type' => 'authorization_code',
        ]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::CODE2SESSION_URL . '?' . $query,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,   // 本地调试关闭证书校验；正式上线请改为 true
        ]);

        $response = curl_exec($ch);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return ['error' => 'cURL请求失败：' . $curlErr];
        }

        $data = json_decode($response, true);

        if (!is_array($data)) {
            return ['error' => '微信接口返回内容无法解析'];
        }

        // 微信以 errcode 返回错误（如 40029 无效的 code、40163 code 已被使用）
        if (!empty($data['errcode'])) {
            $errmsg = isset($data['errmsg']) ? $data['errmsg'] : '未知错误';
            return ['error' => '微信接口返回 ' . $data['errcode'] . '：' . $errmsg];
        }

        if (empty($data['openid'])) {
            return ['error' => '微信接口未返回 openid'];
        }

        return $data;
    }
}

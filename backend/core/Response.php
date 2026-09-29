<?php
/**
 * 统一API返回格式类
 * 所有接口返回数据统一使用此类封装
 */
class Response
{
    /**
     * 成功返回
     * @param mixed $data    返回数据
     * @param string $msg    提示信息
     */
    public static function success($data = null, $msg = 'ok')
    {
        self::json([
            'code' => 200,
            'msg'  => $msg,
            'data' => $data,
        ]);
    }

    /**
     * 失败返回
     * @param string $msg    错误信息
     * @param int $code      错误码
     */
    public static function error($msg = 'error', $code = 400)
    {
        self::json([
            'code' => $code,
            'msg'  => $msg,
            'data' => null,
        ]);
    }

    /**
     * 服务器内部错误（500）的统一响应
     *
     * ============================================
     * 【为什么不能直接把 $e->getMessage() 返回给客户端】
     *   原来的写法是每个接口自己写一句：
     *       Response::error('下单失败：' . $e->getMessage(), 500);
     *   而异常信息里经常带有内部细节，例如：
     *       SQLSTATE[42S22]: Column not found: 1054 Unknown column 'xxx' in 'where clause'
     *   这等于把**表结构、列名、甚至约束名**告诉调用方，
     *   既是信息泄露，也帮攻击者省了探测成本。
     *
     *   现在的做法：
     *     - 完整细节**始终**写进服务器错误日志（排查靠它）
     *     - 对外只在「请求来自本机回环地址」时才带上细节
     *       （和 security.php 里 allow_dev_openid_login 的本地调试通道同一套思路：
     *        不新增配置项，但默认对外部请求不泄露内部信息）
     * ============================================
     *
     * @param string    $context 面向用户的场景说明，例如「下单失败」
     * @param Exception $e
     */
    public static function serverError($context, $e)
    {
        self::log($context, $e);

        $message = $context;

        if (self::isLocalRequest()) {
            $message .= '：' . $e->getMessage();
        }

        self::error($message, 500);
    }

    /**
     * 写一条服务器错误日志
     *
     * 用 error_log() 而不自己写文件：不带目标时它会进入 SAPI 的日志
     * （Apache 下就是 Apache 的 error.log），零配置、不会因为目录不可写而失败。
     * phpstudy 下日志位置：Extensions\Apache2.4.39\logs\error.log
     *
     * 注意：需要 php.ini 里 log_errors = On（phpstudy 默认是开的）。
     *
     * @param string         $context
     * @param Exception|null $e
     */
    public static function log($context, $e = null)
    {
        if ($e === null) {
            error_log('[TasteWise] ' . $context);
            return;
        }

        error_log(sprintf(
            '[TasteWise] %s | %s: %s @ %s:%d | %s',
            $context,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '-'
        ));
    }

    /**
     * 请求是否来自本机回环地址
     *
     * 用来决定要不要把内部错误细节返回给调用方（本地调试需要，上线不需要）。
     *
     * 公开出来是因为流式接口（core/../api/ai/chat_stream.php）没法用
     * serverError() —— 它已经进入 SSE 输出，只能用 error 事件告知，
     * 但同样需要按这个判断决定要不要带上细节。
     */
    public static function isLocalRequest()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        return $ip === '127.0.0.1' || $ip === '::1';
    }

    /**
     * 是否让 HTTP 状态码与业务码保持一致
     *
     * ============================================
     * 【为什么敢这么做】微信小程序的 wx.request 对非 2xx 响应**仍然走 success 回调**
     *   （fail 只用于网络层失败：超时、域名不合法、连不上等），
     *   所以前端「读 body 里的 code」的逻辑完全不受影响。
     *   浏览器端同理：fetch/axios 之外的裸 XMLHttpRequest 也能拿到 4xx 的响应体。
     *
     * 【退路】万一在某个客户端上发现业务错误被误判成「网络异常」，
     *   把这里改成 false 就会恢复成「一律返回 200 + body 里带 code」的老行为，
     *   前端不需要任何改动。
     * ============================================
     */
    public static $mirrorHttpStatus = true;

    /**
     * 输出JSON并终止
     */
    private static function json($data)
    {
        // 让 HTTP 状态码与业务码一致。
        //
        // 原来一律返回 200，代价是 HTTP 这一层的信息完全失效：
        //   - 监控/告警按状态码统计错误时永远是 0
        //   - 反向代理、CDN、网关无法按状态码做缓存与重试策略
        //   - 服务器访问日志里全是 200，出了问题看不出哪些请求失败了
        // body 结构保持不变（仍是 {code,msg,data}），所以前端无需改动。
        if (self::$mirrorHttpStatus && isset($data['code'])) {
            $status = (int) $data['code'];

            // 只接受合法状态码范围；本项目用到的都是标准码
            // （200/400/401/404/409/410/429/500/503）
            if ($status >= 100 && $status <= 599) {
                http_response_code($status);
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');           // 允许跨域
        header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type,token,Authorization');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 处理OPTIONS预检请求
     */
    public static function handleOptions()
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET,POST,OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type,token,Authorization');
            exit;
        }
    }
}
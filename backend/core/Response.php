<?php
/** 统一返回格式：{code, msg, data} */
class Response
{
    /** 成功返回 */
    public static function success($data = null, $msg = 'ok')
    {
        self::json([
            'code' => 200,
            'msg'  => $msg,
            'data' => $data,
        ]);
    }

    /** 失败返回 */
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
     * 不直接返回 $e->getMessage()：异常里常含 SQL 报错、列名、约束名，等于泄露表结构。
     * 完整细节始终写错误日志；只在请求来自本机回环地址时才回传细节
     *
     * @param string    $context 面向用户的场景说明，如「下单失败」
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
     * 用 error_log()（不带目标即进 Apache 的 error.log），零配置且不会因目录不可写失败；
     * 需要 php.ini 里 log_errors = On
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
     * 请求是否来自本机回环地址（决定要不要返回内部错误细节）
     * 公开是因为流式接口已进入 SSE 输出、用不了 serverError()，也需要按它判断
     */
    public static function isLocalRequest()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

        return $ip === '127.0.0.1' || $ip === '::1';
    }

    /**
     * 是否让 HTTP 状态码与业务码保持一致
     * wx.request 对非 2xx 仍走 success 回调，所以前端读 body.code 的逻辑不受影响；
     * 若某个客户端把业务错误误判为网络异常，改成 false 即恢复「一律 200」的老行为
     */
    public static $mirrorHttpStatus = true;

    /**
     * 输出JSON并终止
     */
    private static function json($data)
    {
        // HTTP 状态码与业务码一致：否则监控按状态码统计永远是 0、代理无法按状态码重试、
        // 访问日志里全是 200 看不出哪些请求失败。body 结构不变，前端无需改动。
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
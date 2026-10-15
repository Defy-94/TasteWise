<?php
/**
 * 请求入参读取
 *
 * 原先每个接口各自写 json_decode + trim + intval + 请求方法判断，全项目 20+ 处，
 * 而且行为不一致（有的 trim 有的不 trim、有的夹取范围有的不夹），
 * 同一个入参在不同接口里的表现可能不同。收进这里后取参行为统一：
 *   字符串一律 trim、整数一律夹到指定范围、非法的值一律退回默认值。
 * ============================================
 *
 * 【安全前提】这里只负责「把外来的值变成可用的值」，
 *           不做业务校验（比如「message 必填」仍然由接口自己判断，
 *           因为那句错误提示是面向用户的、有业务含义）。
 *           SQL 注入由 PDO 预处理负责，这里不做转义 —— 转义是输出层的事。
 */

class Request
{
    /** JSON 请求体缓存：多次取值只解析一次 */
    private static $jsonBody = null;

    // 请求体（POST JSON）

    /**
     * 解析并缓存 JSON 请求体
     * php://input 只能读一次，缓存避免多次读取读空、也少解析几次
     * @return array 解析失败或非 JSON 时返回空数组（不报错）
     */
    public static function json()
    {
        if (self::$jsonBody === null) {
            $raw     = file_get_contents('php://input');
            $decoded = json_decode((string) $raw, true);

            self::$jsonBody = is_array($decoded) ? $decoded : [];
        }

        return self::$jsonBody;
    }

    /**
     * 从请求体取字符串
     * @param int|null $maxLen 超长截断（防止塞入超大文本）
     */
    public static function str($key, $default = '', $maxLen = null)
    {
        $body  = self::json();
        $value = isset($body[$key]) && is_scalar($body[$key]) ? (string) $body[$key] : $default;

        return self::cleanString($value, $maxLen);
    }

    /** 从请求体取整数（非数字退回默认值，可夹取上下限） */
    public static function int($key, $default = 0, $min = null, $max = null)
    {
        $body = self::json();

        return self::toInt(isset($body[$key]) ? $body[$key] : null, $default, $min, $max);
    }

    /**
     * 取原始值，不做任何转换；不存在时返回 null
     * 用于必须区分「没传」和「传了 0」的接口：购物车改数量时没传 quantity 应报参数错误，
     * 而 quantity=0 是移除记录。用 int() 会把 "abc" 静默当成 0，等于误删用户的购物车记录
     */
    public static function raw($key)
    {
        $body = self::json();

        return isset($body[$key]) ? $body[$key] : null;
    }

    /** 参数是否存在（JSON 里显式写 null 也视为不存在，与 isset() 语义一致） */
    public static function has($key)
    {
        $body = self::json();

        return isset($body[$key]);
    }

    /** 从请求体取数组（如 AI 对话的 history），非数组返回空数组 */
    public static function arr($key)
    {
        $body  = self::json();
        $value = isset($body[$key]) ? $body[$key] : null;

        return is_array($value) ? $value : [];
    }

    // 查询串（GET）

    /** 从查询串取字符串 */
    public static function queryStr($key, $default = '', $maxLen = null)
    {
        $value = isset($_GET[$key]) && is_scalar($_GET[$key]) ? (string) $_GET[$key] : $default;

        return self::cleanString($value, $maxLen);
    }

    /** 从查询串取整数 */
    public static function queryInt($key, $default = 0, $min = null, $max = null)
    {
        return self::toInt(isset($_GET[$key]) ? $_GET[$key] : null, $default, $min, $max);
    }

    // 请求方法

    public static function isPost()
    {
        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    public static function isGet()
    {
        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET';
    }

    /** 要求 POST，否则返回错误并终止 */
    public static function requirePost()
    {
        if (!self::isPost()) {
            Response::error('仅支持POST请求');
        }
    }

    /** 要求 GET，否则直接返回错误并终止 */
    public static function requireGet()
    {
        if (!self::isGet()) {
            Response::error('仅支持GET请求');
        }
    }

    // 内部工具

    /** trim + 可选截断 */
    private static function cleanString($value, $maxLen)
    {
        $value = trim((string) $value);

        if ($maxLen !== null && mb_strlen($value, 'UTF-8') > $maxLen) {
            $value = mb_substr($value, 0, $maxLen, 'UTF-8');
        }

        return $value;
    }

    /** 转整数并按范围夹取；非数字退回默认值 */
    private static function toInt($raw, $default, $min, $max)
    {
        if ($raw === null || !is_numeric($raw)) {
            $value = (int) $default;
        } else {
            $value = (int) $raw;
        }

        if ($min !== null && $value < $min) {
            $value = $min;
        }
        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return $value;
    }
}

<?php
/**
 * 请求入参读取
 *
 * ============================================
 * 【为什么要它】
 *   在它出现之前，每个接口都自己写这几句：
 *
 *       $input = json_decode(file_get_contents('php://input'), true);
 *       $message = isset($input['message']) ? trim($input['message']) : '';
 *       $page    = isset($_GET['page']) ? intval($_GET['page']) : 0;
 *       if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
 *           Response::error('仅支持POST请求');
 *       }
 *
 *   全项目这样的样板有 20+ 处。问题不只是啰嗦：
 *     - 有的地方 trim 了、有的没 trim；有的做了范围夹取、有的没做
 *     - 有人写 intval()、有人写 (int)，负数不校验
 *     - 「仅支持GET请求」这类判断分散在 20 个文件里，措辞还不完全一致
 *   换句话说，**同一个入参在不同接口里的行为可能不一样**，
 *   而这种不一致没有任何地方会提醒你。
 *
 *   收进这里之后，所有接口的取参行为就统一了：
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

    // ------------------------------------------------------------------
    // 请求体（POST JSON）
    // ------------------------------------------------------------------

    /**
     * 解析并缓存 JSON 请求体
     *
     * 缓存是有意义的：一个接口里往往要取好几个字段，
     * 每次都 file_get_contents('php://input') 会把 php://input 读空
     * （它是只读一次的数据流），也白白多解析几次。
     *
     * @return array 解析失败或不是 JSON 时返回空数组（不报错）
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
     *
     * @param string   $key
     * @param string   $default
     * @param int|null $maxLen 超长时截断（防止有人塞一兆字节的文本进去）
     * @return string
     */
    public static function str($key, $default = '', $maxLen = null)
    {
        $body  = self::json();
        $value = isset($body[$key]) && is_scalar($body[$key]) ? (string) $body[$key] : $default;

        return self::cleanString($value, $maxLen);
    }

    /**
     * 从请求体取整数（非数字一律退回默认值）
     *
     * @param string   $key
     * @param int      $default
     * @param int|null $min 下限，超出则夹到下限
     * @param int|null $max 上限，超出则夹到上限
     * @return int
     */
    public static function int($key, $default = 0, $min = null, $max = null)
    {
        $body = self::json();

        return self::toInt(isset($body[$key]) ? $body[$key] : null, $default, $min, $max);
    }

    /**
     * 取**原始值**，不做任何转换；参数不存在时返回 null
     *
     * 什么时候需要它：有些接口必须区分「没传这个参数」和「传了 0」。
     * 例如购物车改数量：没传 quantity 应当报参数错误，
     * 而 quantity=0 是「移除这条记录」——两者含义完全不同。
     * 如果直接用 Request::int()，非数字的 "abc" 会被静默当成 0，
     * 结果就是**一次手滑的请求把用户的购物车记录删掉**。
     * 这种场景请用 raw() 自己判断，不要图省事。
     */
    public static function raw($key)
    {
        $body = self::json();

        return isset($body[$key]) ? $body[$key] : null;
    }

    /**
     * 参数是否存在
     *
     * 注意：JSON 里显式写的 null 也视为「不存在」——
     * 这与 PHP 的 isset() 语义一致，也是调用方最不容易误解的行为。
     */
    public static function has($key)
    {
        $body = self::json();

        return isset($body[$key]);
    }

    /**
     * 从请求体取数组（例如 AI 对话的 history）
     *
     * @return array 不是数组时返回空数组
     */
    public static function arr($key)
    {
        $body  = self::json();
        $value = isset($body[$key]) ? $body[$key] : null;

        return is_array($value) ? $value : [];
    }

    // ------------------------------------------------------------------
    // 查询串（GET）
    // ------------------------------------------------------------------

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

    // ------------------------------------------------------------------
    // 请求方法
    // ------------------------------------------------------------------

    public static function isPost()
    {
        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';
    }

    public static function isGet()
    {
        return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET';
    }

    /**
     * 要求 POST，否则直接返回错误并终止
     * （替换原来散在 20 个文件里的三行判断）
     */
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

    // ------------------------------------------------------------------
    // 内部工具
    // ------------------------------------------------------------------

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

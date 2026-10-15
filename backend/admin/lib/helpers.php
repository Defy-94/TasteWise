<?php
/**
 * 管理后台公共小工具
 *
 * 这里只放「到处都要用、又短到不值得单独建类」的函数：
 * 输出转义、一次性提示、跳转、取参、格式化。
 */

if (!defined('TW_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}


/**
 * HTML 转义
 *
 * 后台页面里所有来自数据库或用户输入的内容都必须经过它再输出，
 * 否则菜品名、订单备注里塞一段 <script> 就能在管理员浏览器里执行（存储型 XSS）。
 * 转义在「输出时」做，而不是「入库时」做 —— 入库时转义会导致数据被污染，
 * 而且一旦在别处（比如小程序接口）输出就会双重编码。
 *
 * @param mixed $value
 * @return string
 */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * 把 URL 里的查询参数拼成安全的形式（用于分页/筛选链接）
 */
function url_with(array $overrides)
{
    $params = $_GET;
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    $query = http_build_query($params);
    return basename($_SERVER['SCRIPT_NAME']) . ($query !== '' ? '?' . $query : '');
}


/**
 * 写入一条提示，显示一次后自动消失
 * @param string $type success | error | warn | info
 */
function flash_set($type, $message)
{
    if (!isset($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** 取出全部提示并清空 */
function flash_take()
{
    $list = isset($_SESSION['flash']) && is_array($_SESSION['flash']) ? $_SESSION['flash'] : [];
    unset($_SESSION['flash']);
    return $list;
}

/**
 * 302 跳转并终止
 * 注意：调用前不能有任何输出，否则 header 会失败
 */
function redirect($url)
{
    header('Location: ' . $url);
    exit;
}


/** 取 GET/POST 里的整数参数并夹到指定范围 */
function int_param($source, $key, $default = 0, $min = null, $max = null)
{
    $raw = isset($source[$key]) ? $source[$key] : null;

    if ($raw === null || $raw === '' || !is_numeric($raw)) {
        $value = $default;
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

/** 取字符串参数并去掉首尾空白，可选截断长度 */
function str_param($source, $key, $default = '', $maxLen = null)
{
    $raw = isset($source[$key]) && is_scalar($source[$key]) ? (string) $source[$key] : $default;
    $raw = trim($raw);

    if ($maxLen !== null && mb_strlen($raw, 'UTF-8') > $maxLen) {
        $raw = mb_substr($raw, 0, $maxLen, 'UTF-8');
    }

    return $raw;
}


/** 金额显示 */
function money($value)
{
    return '￥' . number_format((float) $value, 2);
}

/**
 * 转义 SQL LIKE 里的通配符
 *
 * 实现已经挪到 DB::likeEscape()：那是纯粹的数据库语义，
 * 小程序接口层（菜品搜索）和管理后台都要用，放在 core 里就只有一份实现 ——
 * 之前只有后台有一份，接口层要用就得再抄一遍，抄出来的第二份早晚会和第一份走偏。
 *
 * 这里保留一层同名包装，是为了不动已有的调用点；
 * 新代码建议直接用 DB::likeEscape()。
 */
function like_escape($value)
{
    return DB::likeEscape($value);
}

/** 校验是否为 YYYY-MM-DD 格式的日期 */
function is_date($value)
{
    return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
}

/**
 * 订单状态对应的徽章样式名（与 admin.css 里的 .badge-xxx 对应）
 */
function status_badge_class($status)
{
    switch (OrderStatus::normalize($status)) {
        case OrderStatus::PENDING:
            return 'badge-warn';      // 待支付：需要关注
        case OrderStatus::PAID:
            return 'badge-info';      // 已支付：待处理
        case OrderStatus::DONE:
            return 'badge-ok';        // 已完成
        case OrderStatus::CANCELED:
        default:
            return 'badge-muted';     // 已取消
    }
}

/**
 * 把 datetime 字符串显示得更紧凑
 */
function short_time($value)
{
    if (empty($value)) {
        return '-';
    }
    // 同年只显示 月-日 时:分
    $year = date('Y');
    if (strpos((string) $value, $year . '-') === 0) {
        return substr((string) $value, 5, 11);
    }
    return (string) $value;
}

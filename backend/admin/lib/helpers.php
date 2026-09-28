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

// ----------------------------------------------------------------------
// 输出安全
// ----------------------------------------------------------------------

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

// ----------------------------------------------------------------------
// 一次性提示（PRG 模式：POST 处理 → 跳转 → 页面读取提示）
// ----------------------------------------------------------------------

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

// ----------------------------------------------------------------------
// 取参
// ----------------------------------------------------------------------

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

// ----------------------------------------------------------------------
// 格式化
// ----------------------------------------------------------------------

/** 金额显示 */
function money($value)
{
    return '￥' . number_format((float) $value, 2);
}

/**
 * 转义 SQL LIKE 里的通配符
 *
 * 用户在搜索框里输入 % 或 _ 时，如果不转义就会被当成通配符，
 * 搜 "100%" 会匹配到一堆无关订单，看起来像搜索坏了。
 * 反斜杠必须最先替换，否则会把后面新加的反斜杠又转义一遍。
 */
function like_escape($value)
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);
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

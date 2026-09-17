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
     * 输出JSON并终止
     */
    private static function json($data)
    {
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
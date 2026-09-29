<?php
/**
 * 数据库操作公共类 - PDO单例封装
 * 所有业务API通过此类操作数据库，不直接写SQL连接
 */
class DB
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        $config = require __DIR__ . '/../config/database.php';
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset={$config['charset']}";
        $this->pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    // 获取单例实例
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // 获取PDO对象（供外部使用原生查询）
    public function getPdo()
    {
        return $this->pdo;
    }

    // 通用查询 - 返回多条记录
    public function select($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // 通用查询 - 返回单条记录
    public function selectOne($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    // 通用插入 - 返回插入ID
    public function insert($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->pdo->lastInsertId();
    }

    // 通用更新/删除 - 返回影响行数
    public function execute($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    // 开启事务
    public function beginTransaction()
    {
        $this->pdo->beginTransaction();
    }

    // 提交事务
    public function commit()
    {
        $this->pdo->commit();
    }

    // 回滚事务
    public function rollback()
    {
        $this->pdo->rollBack();
    }

    /**
     * 转义 SQL LIKE 里的通配符
     *
     * 【为什么需要它】用户在搜索框里输入 % 或 _ 时，如果不转义就会被当成通配符：
     * 搜 "100%" 会匹配到一堆无关记录，看起来像「搜索坏了」。
     * 反斜杠必须**最先**替换，否则会把后面新加的反斜杠又转义一遍。
     *
     * 【为什么放在 DB 类里，而不是各处的 helpers】
     * 这是纯粹的数据库语义，小程序接口和管理后台都要用。
     * 放在这里就只有一个实现 —— 之前只有管理后台有一份 like_escape()，
     * 接口层要用就得再抄一遍，而抄出来的第二份早晚会和第一份走偏。
     *
     * 用法：
     *     $like = '%' . DB::likeEscape($keyword) . '%';
     *     $db->select('... WHERE name LIKE ?', [$like]);
     *
     * @param mixed $value
     * @return string
     */
    public static function likeEscape($value)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);
    }

    // 禁止克隆
    private function __clone() {}
}
<?php
/**
 * 管理后台配置（示例模板）
 *
 * 使用方式：复制本文件为 admin.php 并填入管理员账号
 * cp admin.example.php admin.php
 */
return [
    // 管理员登录名
    'username' => 'admin',

    // 密码哈希（不是明文！生成方式见文件顶部说明）
    'password_hash' => '请替换为 password_hash() 生成的哈希字符串',

    // 后台会话空闲超时（秒）：超过这个时间没有任何操作就要重新登录，默认 2 小时
    'session_idle_timeout' => 7200,

    // 连续登录失败多少次后锁定（按会话统计，防止脚本暴力猜密码）
    'max_login_attempts' => 5,

    // 触发锁定后需要等待多少秒才能再次尝试，默认 5 分钟
    'lock_seconds' => 300,
];

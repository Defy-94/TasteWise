<?php
/**
 * 安全配置（示例模板）
 * 
 * 使用方式：复制本文件为 security.php，并填入随机密钥
 *   cp security.example.php security.php
 * 
 * 生成密钥（任选一种）：
 *   - PHP：   php -r "echo bin2hex(random_bytes(32));"
 *   - 在线工具生成 64 位随机十六进制字符串
 * 
 * 注意：security.php 已被 .gitignore 忽略，不会上传到 Git 仓库。
 *       更换密钥会使所有已签发的登录令牌立即失效（用户需重新登录）。
 */
return [
    // 令牌签名密钥（务必修改为自己的随机值，长度建议 64 位十六进制）
    'token_secret' => '请替换为64位随机十六进制字符串',
];

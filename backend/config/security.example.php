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

    /**
     * 本地开发调试开关：是否允许「用 openid 直接登录」
     * 
     * 用途：未配置微信 AppSecret 时，方便本地调试（微信开发者工具直连本机时可用）。
     * 
     * ✅ 线上必须保持 false！该开关会绕过微信 code2Session 校验，
     *    一旦开启且线上可访问，就等于给所有人留了一个「知道 openid 即可登录」的后门。
     * 
     * 另外该通道还要求请求来自本机回环地址（127.0.0.1 / ::1），双重保护。
     */
    'allow_dev_openid_login' => false,
];

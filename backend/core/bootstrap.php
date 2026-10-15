<?php
/**
 * 核心层自动加载
 *
 * 在它出现之前，全项目有 **93 处手工 require_once**，散落在 28 个文件里。
 * 这带来两个实际成本：
 */

spl_autoload_register(function ($class) {
    // 只处理全局类；带命名空间的（Foo\Bar）交给别的加载器
    if (strpos($class, '\\') !== false) {
        return;
    }

    $file = __DIR__ . DIRECTORY_SEPARATOR . $class . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

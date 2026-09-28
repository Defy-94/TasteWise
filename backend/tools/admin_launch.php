<?php
/**
 * 管理后台快速入口（命令行脚本）
 *
 * 由项目根目录的「启动管理后台.bat」调用：打印中文提示 → 探测服务 → 打开浏览器。
 *
 * ============================================
 * 【为什么这段中文不写在 .bat 里，而要放到 PHP 里】
 *
 *   cmd.exe 解析批处理有个老毛病：当文件里既有中文又有 chcp 时，
 *   它内部按「字符数」定位文件、实际按「字节数」读取，代码页一换就对不上，
 *   表现为**汉字被重复输出**（「后台地址」变成「后后台台地地址址」），
 *   甚至**把半行当成命令去执行**，报 "is not recognized as an internal or external command"。
 *   同样的写法改几个字就可能从正常变出错，属于随时会炸的隐患。
 *
 *   解决办法是把这个组合彻底拆开：
 *     .bat  →  只写 ASCII，只负责「切代码页 + 找 PHP + 调用本脚本」
 *     本文件 →  输出全部中文（UTF-8 字节，控制台已被 .bat 切成 UTF-8，显示正常）
 *   这样两边都不碰对方的雷区。
 * ============================================
 */

// 只允许命令行运行：本文件放在站点目录下，通过浏览器访问必须被拒绝
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('本脚本只能在命令行下运行，请勿通过浏览器访问。');
}

// --check：只探测服务、打印状态，不打开浏览器（用于快速自检，也方便自动化测试）
$checkOnly = in_array('--check', $argv, true);

$adminUrl = 'http://localhost/admin/';
$probeUrl = $adminUrl . 'login.php';

/**
 * 打开系统默认浏览器
 *
 * Windows 的 start 是 cmd 内建命令，所以要经由 cmd 调用；
 * 第一个空参数是窗口标题，占位用，不能省（否则 start 会把 URL 当成标题）。
 */
function open_browser($url)
{
    if (stripos(PHP_OS, 'WIN') === 0) {
        @pclose(@popen('cmd /c start "" ' . escapeshellarg($url), 'r'));
        return;
    }

    if (PHP_OS === 'Darwin') {
        @exec('open ' . escapeshellarg($url));
        return;
    }

    @exec('xdg-open ' . escapeshellarg($url));
}

echo "============================================================\n";
echo "  TasteWise 智味 - 管理后台快速入口\n";
echo "============================================================\n\n";

echo "  后台地址 : " . $adminUrl . "\n\n";
echo "  账号与密码 : 见 backend\\config\\admin.php\n";
echo "  修改密码   : 双击项目根目录的「修改后台密码.bat」\n";
echo "               （也可以打开后台的「账号与安全」页面看说明）\n\n";

echo "  正在检测后台服务 ...\n";

$context = stream_context_create([
    'http' => [
        'timeout'       => 6,
        'ignore_errors' => true,
    ],
]);

$response = @file_get_contents($probeUrl, false, $context);

if ($response !== false) {
    echo "  [OK] 服务正常";
    if ($checkOnly) {
        echo "（--check 模式，不打开浏览器）\n";
        exit(0);
    }
    echo "，正在打开浏览器 ...\n\n";
    open_browser($adminUrl);
    echo "  若浏览器没有自动打开，请手动访问：" . $adminUrl . "\n";
    exit(0);
}

// ---------- 服务没起来：明确告诉用户该做什么，不用打开浏览器让人看报错页 ----------
echo "  [X] 检测不到后台服务：" . $probeUrl . "\n\n";
echo "  请先启动 phpstudy 的 Apache 与 MySQL：\n";
echo "    1. 打开 phpstudy 面板\n";
echo "    2. 点「启动」，确认 Apache 与 MySQL 都显示为运行中\n";
echo "    3. 重新双击启动脚本\n\n";
echo "  如果是第一次在新电脑上运行，还需要先准备好配置文件（共 5 个），\n";
echo "  详见 docs\\deployment.md。\n\n";
echo "  想直接看浏览器报什么错，可以手动访问：" . $adminUrl . "\n";

exit(1);

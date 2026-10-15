<?php
/**
 * 修改管理后台管理员密码（命令行工具）
 *
 * 1. 可移植：以前文档里写的是
 * "E:\phpstudy_pro\Extensions\php\php7.3.4nts\php.exe" -r "echo password_hash(...)"
 * - 通过浏览器访问会被直接拒绝（见下面的 PHP_SAPI 判断）。
 */

// 万一被人从浏览器访问到，绝不能让它有机会改配置文件。
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('本脚本只能在命令行下运行，请勿通过浏览器访问。');
}


/**
 * 解析 --key=value 形式的参数
 * @return array ['username'=>..., 'hash'=>..., 'config'=>...]
 */
function parse_options(array $argv)
{
    $options = [
        'username'  => null,
        'hash'      => null,
        'config'    => null,
        'show'      => false,
        'no-backup' => false,
        'help'      => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--show') {
            $options['show'] = true;
        } elseif ($arg === '--no-backup') {
            $options['no-backup'] = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif (strpos($arg, '--username=') === 0) {
            $options['username'] = substr($arg, strlen('--username='));
        } elseif (strpos($arg, '--hash=') === 0) {
            $options['hash'] = substr($arg, strlen('--hash='));
        } elseif (strpos($arg, '--config=') === 0) {
            $options['config'] = substr($arg, strlen('--config='));
        } else {
            echo "未知参数：$arg（用 --help 查看用法）\n";
            exit(1);
        }
    }

    return $options;
}

function print_usage()
{
    echo <<<TXT

修改管理后台管理员密码

用法：
  php backend/tools/admin_passwd.php                     交互式改密码（推荐）
  php backend/tools/admin_passwd.php --show              只看当前账号信息
  php backend/tools/admin_passwd.php --username=新登录名  同时改登录名
  php backend/tools/admin_passwd.php --hash='\$2y\$10\$...'  直接写入现成的哈希
  php backend/tools/admin_passwd.php --no-backup         不生成备份文件
  php backend/tools/admin_passwd.php --config=路径        指定配置文件（一般不用）
  php backend/tools/admin_passwd.php --help              显示本说明

脚本化调用（管道喂入两次密码）：
  printf '新密码\n新密码\n' | php backend/tools/admin_passwd.php

TXT;
}


/**
 * 读取一行密码输入，尽量不回显
 *
 * 隐藏输入在 PHP CLI 里没有统一做法，所以分平台处理；
 * 实在隐藏不了就明确告诉用户「密码会显示在屏幕上」，而不是假装安全。
 *
 * ⚠️ 开头那个 stream_isatty 判断是必须的，不是优化：
 *    Windows 分支会派生一个 PowerShell 子进程去读控制台，
 *    当 stdin 是管道而不是终端时（`echo pw | php 脚本`、CI、定时任务），
 *    那个子进程永远等不到输入，会把整个脚本**挂死**。
 *    所以要先确认「真的有人在终端前面」，否则老老实实 fgets。
 */
function read_secret($prompt)
{
    echo $prompt;

    $interactive = function_exists('stream_isatty') && @stream_isatty(STDIN);

    // 非交互场景：直接读一行。没有屏幕给人看，也就不必操心回显。
    // fgets 在 EOF 时返回 false，转成空串后由调用方按「密码为空」拒绝，
    // 因此不会出现「读不到输入就无限等待」的情况。
    //
    // 顺手剥掉行首可能出现的 UTF-8 BOM（EF BB BF）：
    // PowerShell 5.1 往原生进程的 stdin 写内容时会先写一个 BOM，
    // 于是管道喂进来的第一个密码会静静多出一个看不见的字符，
    // 后果是「明明设了密码却怎么都登不上」，而且极难排查。
    // 人手动敲键盘不可能敲出 BOM，所以只在这个非交互分支处理。
    if (!$interactive) {
        $line = rtrim((string) fgets(STDIN), "\r\n");

        if (strpos($line, "\xEF\xBB\xBF") === 0) {
            $line = substr($line, 3);
        }

        return $line;
    }

    // ---------- 以下都是交互场景 ----------

    // Windows：没有 stty，借 PowerShell 的 SecureString 读取，不回显
    if (stripos(PHP_OS, 'WIN') === 0) {
        if (function_exists('shell_exec')) {
            $cmd = 'powershell -NoProfile -Command "'
                 . '$s = Read-Host -AsSecureString; '
                 . '[System.Runtime.InteropServices.Marshal]::PtrToStringAuto('
                 . '[System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))" 2>NUL';

            $out = @shell_exec($cmd);

            if ($out !== null && $out !== false && trim($out) !== '') {
                echo "\n";
                return rtrim($out, "\r\n");
            }
        }

        echo "\n   （本机无法隐藏输入，密码会显示在屏幕上）\n";
        return rtrim((string) fgets(STDIN), "\r\n");
    }

    // Linux / macOS：用 stty 关掉回显
    $hidden = false;
    if (function_exists('shell_exec')) {
        @shell_exec('stty -echo 2>/dev/null');
        $hidden = true;
    }

    $line = rtrim((string) fgets(STDIN), "\r\n");

    if ($hidden) {
        @shell_exec('stty echo 2>/dev/null');
        echo "\n";
    }

    return $line;
}

/** 校验是否是合法的 bcrypt 哈希，避免写坏配置文件 */
function is_valid_bcrypt_hash($hash)
{
    // $2y$10$ 后面跟 53 个 base64 字符，总长 60
    return is_string($hash) && preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash) === 1;
}

/** 校验登录名，只允许安全字符（避免带单引号把 PHP 配置写坏） */
function is_valid_username($name)
{
    return is_string($name) && preg_match('/^[A-Za-z0-9_.@-]{1,50}$/', $name) === 1;
}


/** 从配置文件里取出当前 username / password_hash */
function read_current($path)
{
    $config = require $path;
    return [
        'username' => isset($config['username']) ? $config['username'] : '',
        'hash'     => isset($config['password_hash']) ? $config['password_hash'] : '',
    ];
}

/**
 * 把新的 username / password_hash 写回配置文件
 *
 * 用正则只替换这两个值，文件里的注释、其他配置项原样保留。
 * 用 preg_replace_callback 而不是 preg_replace：
 * 哈希里含 $ 符号，直接当替换字符串会被当成反向引用 $1、$2 处理，导致写进去的哈希是错的。
 *
 * @return array [bool $ok, string $message]
 */
function write_config($path, $username, $hash, $plainPassword = null)
{
    $content = file_get_contents($path);
    if ($content === false) {
        return [false, '读取配置文件失败：' . $path];
    }

    $count = 0;

    // 1. 替换 password_hash（只替换第一处，即 return 数组里那一个）
    $content = preg_replace_callback(
        "/('password_hash'\s*=>\s*)'[^']*'/",
        function ($m) use ($hash) {
            return $m[1] . "'" . $hash . "'";
        },
        $content,
        1,
        $hashCount
    );

    // 2. 替换 username（可选）
    $userCount = 0;
    if ($username !== null) {
        $content = preg_replace_callback(
            "/('username'\s*=>\s*)'[^']*'/",
            function ($m) use ($username) {
                return $m[1] . "'" . $username . "'";
            },
            $content,
            1,
            $userCount
        );
    }

    if ($hashCount < 1) {
        return [false, '在配置文件里找不到 password_hash 这一行，请确认文件是本项目的 config/admin.php'];
    }
    if ($content === null) {
        return [false, '替换失败（正则错误）'];
    }

    // 3. 同步文件顶部的「本地凭据备忘」
    $memoNote = sync_credential_memo($content, $username, $plainPassword);

    // 备忘同步也可能因为正则不匹配 UTF-8 而返回 null，
    // 这里必须再挡一次：否则 file_put_contents(null) 会把配置文件清空。
    if ($content === null) {
        return [false, '同步凭据备忘时出错（正则错误），配置文件未被修改'];
    }

    if (file_put_contents($path, $content) === false) {
        return [false, '写入配置文件失败，请检查文件是否只读：' . $path];
    }

    $msg = 'password_hash 已更新';
    if ($username !== null) {
        $msg .= $userCount > 0 ? '，username 已更新' : '（未找到 username 行，跳过）';
    }
    if ($memoNote !== '') {
        $msg .= "\n  " . $memoNote;
    }

    return [true, $msg];
}

/**
 * 同步配置文件顶部的「本地凭据备忘」
 *
 * ============================================
 * 【为什么由脚本自动同步】
 *   这份备忘是使用者自己选择保留的（明文写着登录名和密码，方便查）。
 *   但改完密码后手动去改这行很容易被忘掉 —— 忘了的后果是
 *   「文件里写着旧密码、实际登录要用新密码」，比没有备忘更误导人。
 *   所以改密码时顺手把它改对。
 *
 * 【只更新，不新建】只有文件里本来就有「密码：xxx」这样的行才会去改它。
 *   使用者如果从来没写过备忘，脚本不会凭空往配置里写明文密码。
 *
 * 【用 --hash 时拿不到明文】那种情况下没法自动改，只能提示手动处理。
 * ============================================
 *
 * @param string      $content       配置文件内容（就地修改）
 * @param string|null $username      新登录名，null 表示没改
 * @param string|null $plainPassword 新密码明文，null 表示拿不到（--hash 模式）
 * @return string 给用户的说明，'' 表示无事发生
 */
function sync_credential_memo(&$content, $username, $plainPassword)
{
    $updated = [];

    // ⚠️ 下面几个正则必须带 u（UTF-8）修饰符，否则 PCRE 会按字节匹配：
    //    [：:] 在无 u 模式下等于「冒号这 3 个字节中的任意一个字节」的字符类，
    //    于是只吃掉全角冒号的 1 个字节、剩下的字节被后面的 \S.* 吞掉，
    //    结果就是往文件里写进一个残缺字节（看起来是 登录名<乱码>）。
    //    这是实测踩出来的 —— 一开始就是这么写坏的。

    // 登录名那一行
    if ($username !== null) {
        $content = preg_replace_callback(
            '/(?m)^(\s*\*\s*登录名\s*[：:]\s*)\S.*$/u',
            function ($m) use ($username) {
                return $m[1] . $username;
            },
            $content,
            1,
            $userCount
        );

        if ($userCount > 0) {
            $updated[] = '登录名';
        }
    }

    // 密码那一行
    $hasMemoLine = preg_match('/(?m)^\s*\*\s*密码\s*[：:]\s*\S/u', $content) === 1;

    if ($plainPassword !== null) {
        $content = preg_replace_callback(
            '/(?m)^(\s*\*\s*密码\s*[：:]\s*)\S.*$/u',
            function ($m) use ($plainPassword) {
                return $m[1] . $plainPassword;
            },
            $content,
            1,
            $passCount
        );

        if ($passCount > 0) {
            $updated[] = '密码';
        }
    } elseif ($hasMemoLine) {
        // 用的是 --hash，没有明文可写
        return '注意：配置文件顶部的「本地凭据备忘」里还写着旧密码，'
             . '但本次是用 --hash 传入的，脚本没有明文可写，请手动更新那一行。';
    }

    if (empty($updated)) {
        return '';
    }

    return '已同步更新文件顶部的「本地凭据备忘」（' . implode('、', $updated) . '）';
}


$options = parse_options($argv);

if ($options['help']) {
    print_usage();
    exit(0);
}

$configPath = $options['config'] !== null
    ? $options['config']
    : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'admin.php';

echo "============================================================\n";
echo "  TasteWise 管理后台 · 修改密码\n";
echo "============================================================\n\n";

if (!is_file($configPath)) {
    echo "找不到配置文件：$configPath\n\n";
    echo "第一次使用请先复制模板：\n";
    echo "  cp backend/config/admin.example.php backend/config/admin.php\n";
    exit(1);
}

$current     = read_current($configPath);
$currentUser = $current['username'];

echo "配置文件 ：$configPath\n";
echo "当前登录名：$currentUser\n";
echo "当前哈希 ：" . substr($current['hash'], 0, 7) . "…（" . strlen($current['hash']) . " 字符）\n\n";

// ---------- --show：只看不改 ----------
if ($options['show']) {
    $info = password_get_info($current['hash']);
    echo "哈希算法 ：" . $info['algoName']
        . (isset($info['options']['cost']) ? '（cost=' . $info['options']['cost'] . '）' : '') . "\n";
    echo "\n（--show 只查看，没有做任何修改）\n";
    exit(0);
}

// ---------- 确定新登录名 ----------
$newUsername = $currentUser;
if ($options['username'] !== null) {
    if (!is_valid_username($options['username'])) {
        echo "登录名不合法：只允许字母、数字与 _ . @ - ，长度 1-50\n";
        exit(1);
    }
    $newUsername = $options['username'];
}

// ---------- 确定新哈希 ----------
$newHash  = null;
$password = null;   // 只在交互式输入时保留，用于最后验证

if ($options['hash'] !== null) {
    if (!is_valid_bcrypt_hash($options['hash'])) {
        echo "传入的 --hash 不是合法的 bcrypt 哈希。\n";
        echo "它应该形如 \$2y\$10\$ 开头、总共 60 个字符。\n";
        echo "提示：在 shell 里 \$ 会被当成变量，请用单引号包住整个值，例如 --hash='\$2y\$10\$...'\n";
        exit(1);
    }
    $newHash = $options['hash'];
    echo "使用命令行传入的哈希。\n\n";
} else {
    // 交互式输入两次
    echo "请输入新密码（至少 6 位，输入时不显示）：\n";
    $password = read_secret('  新密码   ：');
    $confirm  = read_secret('  再输一遍 ：');

    if ($password === '') {
        echo "密码不能为空，已取消。\n";
        exit(1);
    }
    if (strlen($password) < 6) {
        echo "密码太短（至少 6 位），已取消。\n";
        exit(1);
    }
    if ($password !== $confirm) {
        echo "两次输入不一致，已取消。\n";
        exit(1);
    }
    if (strlen($password) < 8) {
        echo "\n提示：密码不足 8 位，建议再长一些。\n";
    }

    $newHash = password_hash($password, PASSWORD_DEFAULT);
    echo "\n";
}

// ---------- 备份 ----------
$backupPath = null;
if (!$options['no-backup']) {
    $backupPath = $configPath . '.bak-' . date('Ymd-His');
    if (!@copy($configPath, $backupPath)) {
        echo "备份失败（目录不可写？），为避免改坏配置已中止。\n";
        echo "确认不需要备份时可以加 --no-backup 跳过。\n";
        exit(1);
    }
    echo "已备份原配置到：$backupPath\n";
    echo "（该文件同样含旧哈希与凭据备忘，已被 .gitignore 忽略，用完可自行删除）\n";
}

// ---------- 写入 ----------
echo "\n正在写入…\n";
// 第四个参数是明文密码：交互式输入时才有值（用来同步文件顶部的「本地凭据备忘」），
// 用 --hash 时拿不到明文，传 null
list($ok, $message) = write_config(
    $configPath,
    $options['username'] !== null ? $newUsername : null,
    $newHash,
    $password
);

if (!$ok) {
    echo "写入失败：$message\n";
    if ($backupPath !== null) {
        echo "可以从备份恢复：copy \"$backupPath\" \"$configPath\"\n";
    }
    exit(1);
}
echo "  $message\n";

// ---------- 回读校验 ----------
// 这一步是关键：确认写进去的东西真的能被 password_verify 认可，
// 而不是「文件看起来改了、其实哈希抄错了一位」。
$verify = read_current($configPath);

if ($verify['hash'] !== $newHash) {
    echo "\n⚠️ 回读校验失败：文件里的哈希与预期不一致！\n";
    if ($backupPath !== null) {
        echo "请立刻恢复备份：copy \"$backupPath\" \"$configPath\"\n";
    }
    exit(1);
}

if ($password !== null && !password_verify($password, $verify['hash'])) {
    echo "\n⚠️ 回读校验失败：新密码无法通过校验！\n";
    if ($backupPath !== null) {
        echo "请立刻恢复备份：copy \"$backupPath\" \"$configPath\"\n";
    }
    exit(1);
}

// 语法检查，确保配置文件仍是合法 PHP（写坏了会导致后台彻底打不开）
$lintOutput = [];
$lintCode   = 0;
@exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($configPath) . ' 2>&1', $lintOutput, $lintCode);
$syntaxOk = ($lintCode === 0);

echo "  回读校验通过" . ($syntaxOk ? "，语法检查通过" : "，但语法检查失败") . "\n";

if (!$syntaxOk) {
    echo "\n⚠️ 配置文件语法检查未通过：\n" . implode("\n", $lintOutput) . "\n";
    if ($backupPath !== null) {
        echo "请恢复备份：copy \"$backupPath\" \"$configPath\"\n";
    }
    exit(1);
}

// ---------- 完成 ----------
echo "\n============================================================\n";
echo "  修改成功\n";
echo "============================================================\n\n";
echo "  登录名：$newUsername" . ($newUsername !== $currentUser ? "（原来：$currentUser）" : '') . "\n";
echo "  新密码：已更新（脚本不保存明文，忘了就再跑一次本脚本）\n";
echo "  入口  ：http://localhost/admin/\n\n";
echo "  改完立即生效，不需要重启 Apache。\n";
echo "  注意：已经登录的后台会话不会立刻失效（最长一个空闲超时周期，默认 2 小时）。\n";
echo "        要立刻全部踢下线，重启 Apache 或清掉 PHP session 目录下的会话文件。\n\n";
if ($backupPath !== null) {
    echo "  确认新密码能登录后，可以删掉备份：del \"$backupPath\"\n\n";
}

exit(0);

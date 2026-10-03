<?php
/**
 * 数据库备份工具
 *
 * 用法（命令行）：
 *     php backend/tools/backup_db.php              # 备份一次
 *     php backend/tools/backup_db.php --keep=30    # 备份并只保留最近 30 份
 *     php backend/tools/backup_db.php --list       # 只看现有备份
 *
 * 也可以直接双击项目根目录的「备份数据库.bat」。
 *
 * ============================================
 * 【为什么需要它】
 *   这个项目曾经因为一次误操作（在错误的库上执行了 init.sql 里的 DROP TABLE）
 *   丢掉了 users / orders / order_items 的数据，而且**无法恢复**：
 *   MySQL 没开 binlog，表空间文件随 DROP 一起被删，也没有任何历史备份。
 *   备份不是"锦上添花"，它是这类事故唯一的兜底。
 *
 * 【这份备份的两个安全设计】
 *   1. **不含 USE / CREATE DATABASE**
 *      调用 mysqldump 时**故意不加 --databases**，所以导出的文件里没有
 *      USE 语句。这意味着它导入到任意库都不会误伤别的库 ——
 *      而 init.sql 里是有 USE 的，这正是上次事故的根源。
 *      本工具导出后会**实际检查**这两样确实不存在，不满足就报错。
 *   2. **导出后立即自检**
 *      校验文件非空、CREATE TABLE 数量与库中表数一致。
 *      没验证过的备份不算备份（可能是个 0 字节的空文件）。
 *
 * 【怎么恢复】备份文件不含 USE，所以导入时要自己指定目标库：
 *     mysql -uroot -p wechat_order < backup\wechat_order_20261003_210815.sql
 *   恢复前建议先把当前库也备份一次。
 * ============================================
 */

// 只允许命令行运行：这个工具会执行 mysqldump，不该能被浏览器触发
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$root = dirname(dirname(__DIR__));           // 项目根目录
$defaultKeep = 20;

// ----------------------------------------------------------------------
// 参数
// ----------------------------------------------------------------------
$keep = $defaultKeep;
$listOnly = false;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--keep=(\d+)$/', $arg, $m)) {
        $keep = max(1, (int) $m[1]);
    } elseif ($arg === '--list') {
        $listOnly = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "用法: php backend/tools/backup_db.php [--keep=N] [--list]\n");
        exit(0);
    } else {
        fwrite(STDOUT, "未知参数: {$arg}（用 --help 看用法）\n");
        exit(1);
    }
}

$backupDir = $root . DIRECTORY_SEPARATOR . 'backup';

// ----------------------------------------------------------------------
// 小工具
// ----------------------------------------------------------------------
function say($msg = '')
{
    fwrite(STDOUT, $msg . PHP_EOL);
}

function humanSize($bytes)
{
    if ($bytes >= 1048576) { return round($bytes / 1048576, 1) . ' MB'; }
    if ($bytes >= 1024)    { return round($bytes / 1024, 1) . ' KB'; }
    return $bytes . ' B';
}

/**
 * 在常见位置寻找 mysqldump.exe
 * 与 php.exe 的查找思路一致（PATH → phpstudy → xampp/wamp）
 */
function findMysqldump()
{
    // 1. PATH
    $which = trim((string) @shell_exec('where mysqldump 2>nul'));
    if ($which !== '') {
        $first = strtok($which, "\r\n");
        if ($first !== false && is_file($first)) {
            return $first;
        }
    }

    // 2. phpstudy：Extensions\MySQL*\bin\mysqldump.exe
    foreach (range('C', 'H') as $drive) {
        $base = $drive . ':\\phpstudy_pro\\Extensions';
        if (!is_dir($base)) { continue; }
        foreach (glob($base . '\\MySQL*', GLOB_ONLYDIR) ?: [] as $verDir) {
            $exe = $verDir . '\\bin\\mysqldump.exe';
            if (is_file($exe)) { return $exe; }
        }
    }

    // 3. xampp / wamp
    foreach (range('C', 'H') as $drive) {
        foreach ([
            $drive . ':\\xampp\\mysql\\bin\\mysqldump.exe',
            $drive . ':\\wamp64\\bin\\mysql',
        ] as $candidate) {
            if (is_file($candidate)) { return $candidate; }
            if (is_dir($candidate)) {
                foreach (glob($candidate . '\\mysql*', GLOB_ONLYDIR) ?: [] as $verDir) {
                    $exe = $verDir . '\\bin\\mysqldump.exe';
                    if (is_file($exe)) { return $exe; }
                }
            }
        }
    }

    return null;
}

/** 列出已有备份（按时间倒序） */
function listBackups($dir)
{
    $files = glob($dir . DIRECTORY_SEPARATOR . 'wechat_order_*.sql') ?: [];
    usort($files, function ($a, $b) { return filemtime($b) - filemtime($a); });
    return $files;
}

// ----------------------------------------------------------------------
// 只看现有备份
// ----------------------------------------------------------------------
if ($listOnly) {
    $files = is_dir($backupDir) ? listBackups($backupDir) : [];
    say('已有备份（最新在前）：');
    if (empty($files)) {
        say('  （还没有任何备份）');
    } else {
        foreach ($files as $i => $f) {
            say(sprintf('  %2d. %s  %s  %s', $i + 1, basename($f),
                date('Y-m-d H:i:s', filemtime($f)), humanSize(filesize($f))));
        }
    }
    exit(0);
}

// ----------------------------------------------------------------------
// 开始备份
// ----------------------------------------------------------------------
say('==============================================');
say('  TasteWise 数据库备份');
say('==============================================');
say();

// 1. 配置
$configPath = $root . '/backend/config/database.php';
if (!is_file($configPath)) {
    say('[错误] 找不到配置文件：backend/config/database.php');
    exit(1);
}
$cfg = require $configPath;

$dbName = isset($cfg['dbname'])   ? $cfg['dbname']   : '';
$dbUser = isset($cfg['username']) ? $cfg['username'] : '';
$dbPass = isset($cfg['password']) ? $cfg['password'] : '';
$dbHost = isset($cfg['host'])     ? $cfg['host']     : '127.0.0.1';
$dbPort = isset($cfg['port'])     ? (int) $cfg['port'] : 3306;

if ($dbName === '' || $dbUser === '') {
    say('[错误] config/database.php 里的 dbname / username 不完整');
    exit(1);
}

// 2. mysqldump
$dump = findMysqldump();
if ($dump === null) {
    say('[错误] 找不到 mysqldump.exe');
    say();
    say('  它一般在这里（版本号可能不同）：');
    say('    ...\\phpstudy_pro\\Extensions\\MySQL5.7.26\\bin\\mysqldump.exe');
    say('  确认 mysql 已安装后重试。');
    exit(1);
}
say('  mysqldump : ' . $dump);
say('  目标数据库: ' . $dbName);
say();

// 3. 准备目录与文件名
if (!is_dir($backupDir) && !@mkdir($backupDir, 0777, true)) {
    say('[错误] 无法创建 backup 目录：' . $backupDir);
    exit(1);
}

$outFile = $backupDir . DIRECTORY_SEPARATOR . $dbName . '_' . date('Ymd_His') . '.sql';
if (is_file($outFile)) {
    // 同一秒内重复运行才会遇到；不覆盖，直接退出，避免写坏已有备份
    say('[中止] 文件已存在，不覆盖：' . basename($outFile));
    exit(1);
}

// 4. 执行导出
//    ⚠️ 刻意不加 --databases：加了就会在文件里写入 USE 语句，
//       那份备份导入到别的库时会误伤那个库。这是用血换来的教训。
$cmd = escapeshellarg($dump)
     . ' --host=' . escapeshellarg($dbHost)
     . ' --port=' . $dbPort
     . ' --user=' . escapeshellarg($dbUser)
     . ($dbPass === '' ? '' : ' --password=' . escapeshellarg($dbPass))
     . ' --default-character-set=utf8mb4'
     . ' --single-transaction'      // 不加锁地取一致性快照（InnoDB）
     . ' --routines --events --triggers'
     . ' --skip-comments'
     . ' ' . escapeshellarg($dbName)
     . ' > ' . escapeshellarg($outFile);

$t0 = microtime(true);
exec($cmd . ' 2>&1', $outLines, $code);
$elapsed = round((microtime(true) - $t0) * 1000);

// mysqldump 会把 "Using a password on the command line is insecure" 之类的警告
// 混进输出，那不算失败；只有退出码非 0 才是真失败
if ($code !== 0) {
    say('[失败] mysqldump 退出码 ' . $code);
    foreach (array_slice($outLines, 0, 5) as $l) {
        say('  ' . $l);
    }
    @unlink($outFile);
    exit(1);
}

// ----------------------------------------------------------------------
// 5. 自检：没验证过的备份不算备份
// ----------------------------------------------------------------------
say('  正在校验备份文件...');
say();

$problems = [];

if (!is_file($outFile)) {
    $problems[] = '文件没有生成';
} else {
    $size = filesize($outFile);
    if ($size < 1024) {
        $problems[] = '文件太小（' . humanSize($size) . '），可能是空备份';
    }

    $head = (string) file_get_contents($outFile, false, null, 0, 3);
    if ($head === "\xEF\xBB\xBF") {
        $problems[] = '文件带 UTF-8 BOM，导入时第一句会报错';
    }

    $content = (string) file_get_contents($outFile);

    // 最关键的一条：不能含 USE / CREATE DATABASE
    if (preg_match('/^\s*USE\s/im', $content)) {
        $problems[] = '文件里含 USE 语句 —— 导入到别的库会误伤那个库';
    }
    if (preg_match('/^\s*CREATE\s+DATABASE/im', $content)) {
        $problems[] = '文件里含 CREATE DATABASE';
    }
    if (preg_match('/^\s*DROP\s+DATABASE/im', $content)) {
        $problems[] = '文件里含 DROP DATABASE';
    }

    // CREATE TABLE 数量应等于库中表数
    $dumpTables = preg_match_all('/CREATE TABLE/i', $content);
    $dbTableCount = null;

    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
            $dbUser, $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $dbTableCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchColumn();
    } catch (Exception $e) {
        say('  （提示：无法连库做表数比对，跳过这一项）');
    }

    if ($dbTableCount !== null && $dumpTables !== $dbTableCount) {
        $problems[] = "表数量不符：备份里 {$dumpTables} 个，库里 {$dbTableCount} 个";
    }
}

if (!empty($problems)) {
    say('[失败] 备份自检未通过：');
    foreach ($problems as $p) {
        say('  - ' . $p);
    }
    say();
    say('  出于安全考虑，这个文件已被删除，请不要使用它。');
    @unlink($outFile);
    exit(1);
}

// ----------------------------------------------------------------------
// 6. 成功
// ----------------------------------------------------------------------
$size = filesize($outFile);

say('[成功] 备份完成并通过自检');
say();
say('  文件    : backup\\' . basename($outFile));
say('  大小    : ' . humanSize($size));
say('  表数量  : ' . preg_match_all('/CREATE TABLE/i', (string) file_get_contents($outFile)) . ' 个');
say('  耗时    : ' . $elapsed . ' ms');
say('  已确认  : 无 BOM、无 USE、无 CREATE/DROP DATABASE（可安全导入任意库）');
say();

// ----------------------------------------------------------------------
// 7. 清理旧备份
// ----------------------------------------------------------------------
$files = listBackups($backupDir);
if (count($files) > $keep) {
    $removed = 0;
    foreach (array_slice($files, $keep) as $old) {
        if (@unlink($old)) { $removed++; }
    }
    say('  已清理 ' . $removed . ' 份旧备份（保留最近 ' . $keep . ' 份）');
} else {
    say('  现有备份 ' . count($files) . ' 份（上限 ' . $keep . ' 份）');
}

say();
say('  恢复方法（备份不含 USE，所以要自己指定目标库）：');
say('    mysql -uroot -p ' . $dbName . ' < backup\\' . basename($outFile));
say('  恢复前建议先把当前库也备份一次。');
say();
say('==============================================');

<?php
/**
 * 管理后台 - 账号与安全
 *
 * 只读页：展示当前管理员信息与「改密码」的方法，不做任何写操作。
 * 管理员账号存在 config/admin.php 这个 PHP 文件里，
 */
define('TW_ADMIN', true);
require_once __DIR__ . '/lib/bootstrap.php';

AdminAuth::requireLogin();

$cfg      = AdminAuth::config();
$hashInfo = password_get_info($cfg['password_hash']);

$hashAlgo = isset($hashInfo['algoName']) ? $hashInfo['algoName'] : 'unknown';
$hashCost = isset($hashInfo['options']['cost']) ? $hashInfo['options']['cost'] : null;

$configPath = realpath(__DIR__ . '/../config/admin.php');
if ($configPath === false) {
    $configPath = 'backend/config/admin.php';
}

$idleHours = round((int) $cfg['session_idle_timeout'] / 3600, 1);

/**
 * 推导本机执行 PHP 的方式
 *
 * 不能直接用 PHP_BINARY：Apache 下 PHP 以 cgi-fcgi 运行，它指向 php-cgi.exe；
 * PHP_BINDIR 给出的又是编译默认值（本机是 C:\php，并不存在）。
 * 最可靠的线索是 php.ini 所在目录，按可靠性依次尝试。
 *
 * @return string 找不到返回空字符串，页面会退回展示成通用的 php
 */
function detect_php_exe()
{
    if (PHP_BINARY !== '' && strtolower(basename(PHP_BINARY)) === 'php.exe' && is_file(PHP_BINARY)) {
        return PHP_BINARY;
    }

    $ini = php_ini_loaded_file();
    if ($ini !== false && $ini !== '') {
        $candidate = dirname($ini) . DIRECTORY_SEPARATOR . 'php.exe';
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    $candidate = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php.exe';
    if (is_file($candidate)) {
        return $candidate;
    }

    return '';
}

$phpExe  = detect_php_exe();
$phpCall = $phpExe !== '' ? '"' . $phpExe . '"' : 'php';
$cmdTool = $phpCall . ' backend/tools/admin_passwd.php';

admin_header('账号与安全', 'account');
?>

<div class="card">
  <h2 class="card-title">当前管理员</h2>

  <div class="detail-grid">
    <div class="detail-item">
      <div class="k">登录名</div>
      <div class="v"><strong><?php echo e($cfg['username']); ?></strong></div>
    </div>
    <div class="detail-item">
      <div class="k">密码存储</div>
      <div class="v"><?php echo e($hashAlgo); ?><?php echo $hashCost !== null ? '（cost=' . (int) $hashCost . '）' : ''; ?></div>
    </div>
    <div class="detail-item">
      <div class="k">管理员数量</div>
      <div class="v">1 个</div>
    </div>
    <div class="detail-item">
      <div class="k">空闲超时</div>
      <div class="v"><?php echo e($idleHours); ?> 小时</div>
    </div>
    <div class="detail-item">
      <div class="k">失败锁定</div>
      <div class="v"><?php echo (int) $cfg['max_login_attempts']; ?> 次 /
        <?php echo e(round((int) $cfg['lock_seconds'] / 60, 1)); ?> 分钟</div>
    </div>
  </div>
</div>

<div class="card">
  <h2 class="card-title">修改密码</h2>

  <p style="margin-top:0">
    <b>方式一：双击项目根目录的 <code>修改后台密码.bat</code></b>，
    按提示输入两次新密码就行 —— 不用记命令，也不用管 PHP 装在哪里。
  </p>

  <p class="muted">
    它做的事情：找到本机的 PHP → 提示你输入两次新密码 → 直接写回配置文件 →
    自动备份 + 回读校验（确认新密码真的能用），并顺手把配置文件顶部的
    「本地凭据备忘」也改对。
  </p>

  <div class="field" style="margin-top:18px">
    <label>方式二：在项目根目录执行这条命令（Linux / macOS，或习惯用命令行时）</label>
    <input type="text" value="<?php echo e($cmdTool); ?>" readonly onclick="this.select()">
    <div class="form-hint">
      开头的 <code>php</code> 换成你机器上执行 PHP 的方式即可，两种方式效果完全一样。
    </div>
  </div>

  <div class="field" style="margin-top:16px">
    <label>被修改的配置文件</label>
    <input type="text" value="<?php echo e($configPath); ?>" readonly onclick="this.select()">
  </div>

  <div class="flash flash-info" style="margin-top:16px">
    <b>两点注意</b><br>
    1. 密码只存不可逆哈希，<b>忘了只能重设一个</b>，找不回来。<br>
    2. 改完立即生效、不用重启 Apache；但<b>已经登录的会话最长还会存活
       <?php echo e($idleHours); ?> 小时</b>，要立刻踢下线就重启 Apache。
  </div>
</div>

<p class="muted">
  当前为单管理员方案（账号存在配置文件里，不在数据库），因此不支持多管理员、角色权限与操作日志。
  需要这些功能时要改为 <code>admins</code> 表。
</p>

<?php admin_footer(); ?>

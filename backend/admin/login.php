<?php
/**
 * 管理后台登录页
 *
 * 安全要点：
 * 1. 会话级 CSRF 校验（防「登录 CSRF」：诱导他人登录成攻击者的账号）
 */
define('TW_ADMIN', true);
require_once __DIR__ . '/lib/bootstrap.php';

// 已登录就不用再看登录页
if (AdminAuth::isLoggedIn()) {
    redirect('index.php');
}

$error    = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = str_param($_POST, 'username', '', 50);

    // 密码不做 trim：首尾空格可能是密码的一部分，悄悄去掉会导致
    // 「明明输对了却登不上」这种极难排查的问题
    $password = isset($_POST['password']) ? (string) $_POST['password'] : '';

    if (!AdminAuth::verifyCsrf()) {
        // 大多是页面放太久（会话过期）或浏览器禁用了 Cookie，重新提交即可
        $error = '页面已过期，请重新输入后提交。';
    } elseif ($username === '' || $password === '') {
        $error = '请输入用户名和密码';
    } else {
        $result = AdminAuth::login($username, $password);

        if ($result === true) {
            $target = AdminAuth::consumeAfterLogin();
            flash_set('success', '登录成功，欢迎回来');
            redirect($target);
        }

        $error = $result;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录 · TasteWise 管理后台</title>
<?php /* 带文件修改时间做版本号：改完 CSS 浏览器不会再用旧缓存 */ ?>
<link rel="stylesheet" href="assets/admin.css?v=<?php echo @filemtime(__DIR__ . '/assets/admin.css') ?: 1; ?>">
</head>
<body class="login-page">

<div class="login-card">
  <div class="login-brand">
    <img class="login-avatar" src="assets/xiaowei-avatar.png" alt="智味">
    <h1>TasteWise 管理后台</h1>
    <p>智味 · AI 点餐系统运营入口</p>
  </div>

  <?php if ($error !== ''): ?>
    <div class="flash flash-error"><?php echo e($error); ?></div>
  <?php endif; ?>

  <form method="post" action="login.php" class="login-form" autocomplete="off">
    <?php echo AdminAuth::csrfField(); ?>

    <div class="field">
      <label for="username">管理员账号</label>
      <input type="text" id="username" name="username" value="<?php echo e($username); ?>"
             placeholder="请输入账号" autofocus>
    </div>

    <div class="field">
      <label for="password">密码</label>
      <input type="password" id="password" name="password" placeholder="请输入密码">
    </div>

    <button type="submit" class="btn btn-primary btn-block">登录</button>
  </form>

  <div class="login-tip">
    账号与密码在 <code>backend/config/admin.php</code> 中配置。<br>
    需要改密码时，用 <code>password_hash()</code> 生成新的哈希替换该文件的
    <code>password_hash</code> —— 具体命令见该文件顶部注释。
  </div>
</div>

</body>
</html>

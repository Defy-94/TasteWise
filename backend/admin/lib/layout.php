<?php
/**
 * 管理后台页面骨架（顶部导航 + 内容区 + 页脚）
 *
 * 没有引入任何模板引擎，直接用 PHP 函数输出 —— 后台页面只有几个，
 * 引入模板引擎反而增加理解成本。
 */

if (!defined('TW_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 输出页面头部
 *
 * @param string $title  浏览器标题与页面标题
 * @param string $active 当前导航项：orders | dishes
 * @param string $subtitle 页面标题右侧的说明文字（可选）
 */
function admin_header($title, $active = '', $subtitle = '')
{
    $siteName = 'TasteWise 管理后台';
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e($title); ?> · <?php echo e($siteName); ?></title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>

<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="index.php">
      <!-- 品牌形象：小味（头像副本放在 admin/assets/，后台目录之外访问不到小程序素材） -->
      <img class="brand-mark" src="assets/xiaowei-avatar.png" alt="智味">
      <span class="brand-text">TasteWise<em>管理后台</em></span>
    </a>

    <nav class="nav">
      <a href="index.php"  class="<?php echo $active === 'orders' ? 'active' : ''; ?>">订单管理</a>
      <a href="dishes.php" class="<?php echo $active === 'dishes' ? 'active' : ''; ?>">菜品管理</a>
      <!-- 账号与安全：只读页，展示当前管理员与改密码步骤 -->
      <a href="account.php" class="<?php echo $active === 'account' ? 'active' : ''; ?>">账号与安全</a>
      <!-- 直接打开一个接口的原始返回，用来快速确认后端与数据库是否正常 -->
      <a href="../api/dish/list.php" target="_blank" rel="noopener">接口自检</a>
    </nav>

    <div class="topbar-right">
      <span class="who">👤 <?php echo e(AdminAuth::username()); ?></span>
      <!-- 退出必须走 POST + CSRF：如果用 GET，别人在页面上放一张
           指向 logout.php 的图片就能把你踢下线（CSRF 注销攻击） -->
      <form method="post" action="logout.php" class="inline-form">
        <?php echo AdminAuth::csrfField(); ?>
        <button type="submit" class="btn btn-ghost btn-sm">退出</button>
      </form>
    </div>
  </div>
</header>

<main class="wrap">
  <div class="page-head">
    <h1 class="page-title"><?php echo e($title); ?></h1>
    <?php if ($subtitle !== ''): ?>
      <span class="page-subtitle"><?php echo e($subtitle); ?></span>
    <?php endif; ?>
  </div>

  <?php render_flash(); ?>
    <?php
}

/**
 * 输出页面尾部
 */
function admin_footer()
{
    ?>
</main>

<footer class="foot">
  TasteWise 智味 · AI 点餐系统管理后台 —— 仅限运营人员使用
</footer>

</body>
</html>
    <?php
}

/**
 * 渲染一次性提示（flash）
 */
function render_flash()
{
    $list = flash_take();
    if (empty($list)) {
        return;
    }

    foreach ($list as $item) {
        $type = isset($item['type']) ? $item['type'] : 'info';
        $allowed = ['success', 'error', 'warn', 'info'];
        if (!in_array($type, $allowed, true)) {
            $type = 'info';
        }
        echo '<div class="flash flash-' . $type . '">' . e($item['message']) . '</div>' . "\n";
    }
}

/**
 * 渲染空状态提示（列表无数据时用）
 */
function render_empty($text, $hint = '')
{
    echo '<div class="empty"><div class="empty-icon">🗒️</div><div class="empty-text">' . e($text) . '</div>';
    if ($hint !== '') {
        echo '<div class="empty-hint">' . e($hint) . '</div>';
    }
    echo '</div>';
}

/**
 * 渲染分页条
 *
 * @param int    $page      当前页（从 1 开始）
 * @param int    $totalPage 总页数
 * @param array  $overrides 需要覆盖的查询参数（如筛选条件）
 */
function render_pager($page, $totalPage, $overrides = [])
{
    if ($totalPage <= 1) {
        return;
    }

    echo '<div class="pager">';

    if ($page > 1) {
        echo '<a class="page-link" href="' . e(url_with($overrides + ['page' => $page - 1])) . '">← 上一页</a>';
    } else {
        echo '<span class="page-link disabled">← 上一页</span>';
    }

    echo '<span class="page-info">第 ' . (int) $page . ' / ' . (int) $totalPage . ' 页</span>';

    if ($page < $totalPage) {
        echo '<a class="page-link" href="' . e(url_with($overrides + ['page' => $page + 1])) . '">下一页 →</a>';
    } else {
        echo '<span class="page-link disabled">下一页 →</span>';
    }

    echo '</div>';
}

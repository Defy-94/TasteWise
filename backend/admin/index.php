<?php
/**
 * 管理后台 - 订单管理（首页）
 *
 * 功能：全部订单列表、按状态/关键词/日期筛选、分页、快捷改状态
 *
 * 【写操作一律走 POST + CSRF】行内的「确认收款」「完成」「取消」都是独立的
 * 小表单，而不是 <a href="...?action=pay">。用 GET 改数据是典型错误：
 * 浏览器预取、爬虫抓取、甚至图片加载都可能触发它。
 *
 * 【PRG 模式】POST 处理完立即 302 跳回列表页，这样刷新不会重复提交，
 * 也不会出现「刷新后询问是否重新提交表单」的浏览器提示。
 */
define('TW_ADMIN', true);
require_once __DIR__ . '/lib/bootstrap.php';

AdminAuth::requireLogin();

$db = DB::getInstance();

// ======================================================================
// 1. 处理快捷操作（POST）
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AdminAuth::requireCsrf();

    $orderId = int_param($_POST, 'order_id', 0, 0);
    $action  = str_param($_POST, 'action', '', 20);

    // 「清理超时未支付订单」不是针对某一笔订单的操作，单独处理
    if ($action === 'expire_stale') {
        $count = OrderExpiry::expireStale(null, true);

        flash_set(
            $count > 0 ? 'success' : 'info',
            $count > 0
                ? '已取消 ' . $count . ' 笔超时未支付的订单，对应库存已归还'
                : '没有需要清理的订单（待支付超过 ' . OrderExpiry::TIMEOUT_MINUTES . ' 分钟的才会被清理）'
        );

        redirect(url_with([]));
    }

    list($ok, $message) = admin_apply_order_action($orderId, $action);
    flash_set($ok ? 'success' : 'error', $message);

    // 带上原来的筛选条件跳回去，管理员的操作视线不被打断
    redirect(url_with([]));
}

// 顺手清理超时未支付的订单（带概率门控），保证列表里的状态是真实的。
// 详见 core/OrderExpiry.php：phpstudy 没有可靠 cron，所以采用懒清理。
OrderExpiry::expireStale(null);

// ======================================================================
// 2. 解析筛选条件（SQL 拼装见 admin/lib/queries/orders.php）
// ======================================================================
$page    = int_param($_GET, 'page', 1, 1);
$perPage = 15;

$filters      = admin_order_filters($_GET);
$whereSql     = $filters['where'];
$params       = $filters['params'];
$statusFilter = $filters['status'];
$keyword      = $filters['kw'];
$dateFrom     = $filters['from'];
$dateTo       = $filters['to'];

$statusTextMap = OrderStatus::allText();

// ======================================================================
// 3. 查询
// ======================================================================
try {
    // 3.1 总条数（决定分页）
    $totalRows = admin_order_count($whereSql, $params);
    $totalPage = $totalRows > 0 ? (int) ceil($totalRows / $perPage) : 1;

    // 页码越界时回到最后一页，而不是显示一片空白
    if ($page > $totalPage) {
        $page = $totalPage;
    }

    // 3.2 当前页数据
    $orders = admin_order_page($whereSql, $params, $page, $perPage);

    // 3.3 顶部总览（不受筛选影响，始终看全量）
    $stats    = admin_order_stats();
    $statToday = admin_order_today_count();

} catch (Exception $e) {
    // 查询出错时不要白屏，给出可读的提示
    admin_header('订单管理', 'orders');
    echo '<div class="flash flash-error">查询失败：' . e($e->getMessage()) . '</div>';
    admin_footer();
    exit;
}

$statTotal    = (int) $stats['total'];
$statRevenue  = (float) $stats['revenue'];

// ======================================================================
// 4. 渲染
// ======================================================================
admin_header('订单管理', 'orders', '共 ' . $totalRows . ' 条符合当前筛选条件');
?>

<!-- 总览 -->
<div class="stat-row">
  <div class="stat">
    <div class="stat-label">订单总数</div>
    <div class="stat-value"><?php echo $statTotal; ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">今日新增</div>
    <div class="stat-value"><?php echo $statToday; ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">待支付</div>
    <div class="stat-value accent"><?php echo (int) $stats['pending']; ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">已支付 / 已完成</div>
    <div class="stat-value"><?php echo (int) $stats['paid']; ?> / <?php echo (int) $stats['done']; ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">有效营业额（不含已取消）</div>
    <div class="stat-value accent"><?php echo money($statRevenue); ?></div>
  </div>
</div>

<!-- 维护操作：手动清理超时未支付订单 -->
<div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
  <div>
    <div style="font-weight:600">清理超时未支付订单</div>
    <div class="muted" style="font-size:12px;line-height:1.7">
      下单会立即扣库存，而只有取消订单才会把库存还回去。
      顾客下单后不付款也不取消，那份库存就会被一直占着 ——
      所以待支付超过 <strong><?php echo (int) OrderExpiry::TIMEOUT_MINUTES; ?> 分钟</strong>的订单会被自动取消并归还库存。
      平时是懒清理（打开订单页时顺手做），这里可以立刻清一次。
    </div>
  </div>
  <form method="post" action="<?php echo e(url_with([])); ?>" class="inline-form"
        onsubmit="return confirm('确定立刻清理超时未支付的订单吗？被占用的库存会一并归还。');">
    <?php echo AdminAuth::csrfField(); ?>
    <input type="hidden" name="action" value="expire_stale">
    <button type="submit" class="btn btn-ghost">立刻清理</button>
  </form>
</div>

<!-- 筛选 -->
<div class="card">
  <form method="get" action="index.php" class="filters">
    <div class="field">
      <label for="f-status">订单状态</label>
      <select id="f-status" name="status">
        <option value="">全部状态</option>
        <?php foreach ($statusTextMap as $value => $text): ?>
          <option value="<?php echo (int) $value; ?>" <?php echo $statusFilter === (string) $value ? 'selected' : ''; ?>>
            <?php echo e($text); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="f-kw">关键词</label>
      <input type="text" id="f-kw" name="kw" value="<?php echo e($keyword); ?>"
             placeholder="订单号 / 昵称 / 手机号 / 备注">
    </div>

    <div class="field">
      <label for="f-from">起始日期</label>
      <input type="text" id="f-from" name="from" value="<?php echo e($dateFrom); ?>" placeholder="2024-01-01">
    </div>

    <div class="field">
      <label for="f-to">截止日期</label>
      <input type="text" id="f-to" name="to" value="<?php echo e($dateTo); ?>" placeholder="2024-12-31">
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">筛选</button>
      <a class="btn btn-ghost" href="index.php">重置</a>
    </div>
  </form>
</div>

<!-- 列表 -->
<div class="card">
  <?php if (empty($orders)): ?>
    <?php render_empty('没有符合条件的订单', '可以放宽筛选条件，或先在小程序端下一单试试'); ?>
  <?php else: ?>
    <div class="table-scroll">
      <table class="list">
        <thead>
          <tr>
            <th>订单号</th>
            <th>用户</th>
            <th class="num">份数</th>
            <th class="num">金额</th>
            <th>状态</th>
            <th>备注</th>
            <th>下单时间</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orders as $order): ?>
            <?php
              $currentStatus = OrderStatus::normalize($order['status']);
              $owner = $order['nickname'] !== null && $order['nickname'] !== ''
                  ? $order['nickname']
                  : ($order['username'] !== null && $order['username'] !== '' ? $order['username'] : '用户' . $order['user_id']);
            ?>
            <tr>
              <td class="mono nowrap"><?php echo e($order['order_no']); ?></td>
              <td class="nowrap">
                <?php echo e($owner); ?>
                <span class="muted">#<?php echo (int) $order['user_id']; ?></span>
              </td>
              <td class="num"><?php echo (int) $order['item_count']; ?></td>
              <td class="num"><?php echo money($order['total_price']); ?></td>
              <td>
                <span class="badge <?php echo e(status_badge_class($currentStatus)); ?>">
                  <?php echo e(OrderStatus::text($currentStatus)); ?>
                </span>
              </td>
              <td><?php echo $order['remark'] !== '' ? e($order['remark']) : '<span class="muted">-</span>'; ?></td>
              <td class="nowrap muted"><?php echo e(short_time($order['created_at'])); ?></td>
              <td class="actions">
                <div class="row-actions">
                  <a class="btn btn-ghost btn-sm" href="order.php?id=<?php echo (int) $order['id']; ?>">详情</a>

                  <?php foreach (OrderStatus::nextStates($currentStatus) as $targetStatus => $targetText): ?>
                    <?php
                      // 目标状态 → 后台操作名
                      $actionName = '';
                      $btnClass   = 'btn-ghost';
                      if ($targetStatus === OrderStatus::PAID) {
                          $actionName = 'pay';
                          $btnClass   = 'btn-info';
                      } elseif ($targetStatus === OrderStatus::DONE) {
                          $actionName = 'complete';
                          $btnClass   = 'btn-ok';
                      } elseif ($targetStatus === OrderStatus::CANCELED) {
                          $actionName = 'cancel';
                          $btnClass   = 'btn-danger';
                      }
                    ?>
                    <form method="post" action="<?php echo e(url_with([])); ?>" class="inline-form"
                          <?php echo $actionName === 'cancel' ? 'onsubmit="return confirm(\'取消订单会按明细把库存加回去，确定继续吗？\');"' : ''; ?>>
                      <?php echo AdminAuth::csrfField(); ?>
                      <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
                      <input type="hidden" name="action" value="<?php echo e($actionName); ?>">
                      <button type="submit" class="btn <?php echo e($btnClass); ?> btn-sm"><?php echo e($targetText); ?></button>
                    </form>
                  <?php endforeach; ?>

                  <?php if (OrderStatus::isFinal($currentStatus)): ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php render_pager($page, $totalPage, ['status' => $statusFilter, 'kw' => $keyword, 'from' => $dateFrom, 'to' => $dateTo]); ?>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>

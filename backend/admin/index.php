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

    list($ok, $message) = admin_apply_order_action($orderId, $action);
    flash_set($ok ? 'success' : 'error', $message);

    // 带上原来的筛选条件跳回去，管理员的操作视线不被打断
    redirect(url_with([]));
}

// ======================================================================
// 2. 解析筛选条件
// ======================================================================
$page    = int_param($_GET, 'page', 1, 1);
$perPage = 15;

$statusFilter = str_param($_GET, 'status', '', 5);
$keyword      = str_param($_GET, 'kw', '', 50);
$dateFrom     = str_param($_GET, 'from', '', 10);
$dateTo       = str_param($_GET, 'to', '', 10);

// 非法日期直接丢弃，避免拼出无意义的查询
if (!is_date($dateFrom)) {
    $dateFrom = '';
}
if (!is_date($dateTo)) {
    $dateTo = '';
}

$statusTextMap = OrderStatus::allText();

$where  = [];
$params = [];

if ($statusFilter !== '' && isset($statusTextMap[(int) $statusFilter])) {
    // status 列是 varchar，这里按字符串比较，避免依赖 MySQL 的隐式类型转换
    $where[]  = 'o.status = ?';
    $params[] = (string) (int) $statusFilter;
}

if ($keyword !== '') {
    // 订单号 / 备注 / 用户昵称 / 用户名 / 手机号 都参与匹配
    $like     = '%' . like_escape($keyword) . '%';
    $where[]  = '(o.order_no LIKE ? OR o.remark LIKE ? OR u.nickname LIKE ? OR u.username LIKE ? OR u.phone LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($dateFrom !== '') {
    $where[]  = 'o.created_at >= ?';
    $params[] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where[]  = 'o.created_at <= ?';
    $params[] = $dateTo . ' 23:59:59';
}

$whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

// ======================================================================
// 3. 查询
// ======================================================================
try {
    // 3.1 总条数（决定分页）
    $countRow  = $db->selectOne(
        "SELECT COUNT(*) AS n FROM orders o LEFT JOIN users u ON u.id = o.user_id $whereSql",
        $params
    );
    $totalRows = (int) $countRow['n'];
    $totalPage = $totalRows > 0 ? (int) ceil($totalRows / $perPage) : 1;

    // 页码越界时回到最后一页，而不是显示一片空白
    if ($page > $totalPage) {
        $page = $totalPage;
    }
    $offset = ($page - 1) * $perPage;

    // 3.2 当前页数据
    // LIMIT/OFFSET 直接把整数拼进 SQL：占位符在原生预处理模式下传参
    // 容易被 MySQL 当成字符串而对 LIMIT 报错，而这两个值已经过 int_param
    // 夹取并强转成 int，不存在注入风险。
    $orders = $db->select(
        "SELECT o.id, o.order_no, o.user_id, o.total_price, o.status, o.remark, o.created_at,
                u.nickname, u.username, u.phone,
                (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
         FROM orders o
         LEFT JOIN users u ON u.id = o.user_id
         $whereSql
         ORDER BY o.id DESC
         LIMIT " . (int) $perPage . " OFFSET " . (int) $offset,
        $params
    );

    // 3.3 顶部总览（不受筛选影响，始终看全量）
    $stats = $db->selectOne(
        "SELECT COUNT(*) AS total,
                SUM(status = '0') AS pending,
                SUM(status = '1') AS paid,
                SUM(status = '2') AS done,
                SUM(status = '3') AS canceled,
                COALESCE(SUM(CASE WHEN status <> '3' THEN total_price ELSE 0 END), 0) AS revenue
         FROM orders"
    );
    $todayRow = $db->selectOne('SELECT COUNT(*) AS n FROM orders WHERE created_at >= CURDATE()');

} catch (Exception $e) {
    // 查询出错时不要白屏，给出可读的提示
    admin_header('订单管理', 'orders');
    echo '<div class="flash flash-error">查询失败：' . e($e->getMessage()) . '</div>';
    admin_footer();
    exit;
}

$statTotal    = (int) $stats['total'];
$statRevenue  = (float) $stats['revenue'];
$statToday    = (int) $todayRow['n'];

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

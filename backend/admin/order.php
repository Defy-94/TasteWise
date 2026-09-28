<?php
/**
 * 管理后台 - 订单详情
 *
 * 功能：查看订单完整信息与明细，并对订单做状态流转
 *
 * 【库存还原的责任划分】取消订单时会把库存按明细加回去，这件事由
 * core/OrderStatus.php 统一负责，后台自己不碰库存字段 —— 否则后台和
 * 小程序接口两套实现，早晚会出现「后台取消没还库存」这种对不上账的问题。
 */
define('TW_ADMIN', true);
require_once __DIR__ . '/lib/bootstrap.php';

AdminAuth::requireLogin();

$db      = DB::getInstance();
$orderId = int_param($_GET, 'id', 0, 0);

// ======================================================================
// 1. 处理状态操作（POST）
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AdminAuth::requireCsrf();

    $postOrderId = int_param($_POST, 'order_id', 0, 0);
    $action      = str_param($_POST, 'action', '', 20);

    list($ok, $message) = admin_apply_order_action($postOrderId, $action);
    flash_set($ok ? 'success' : 'error', $message);

    // PRG：跳回详情页，刷新不会重复提交
    redirect('order.php?id=' . (int) $postOrderId);
}

if ($orderId <= 0) {
    flash_set('error', '参数错误：缺少订单ID');
    redirect('index.php');
}

// ======================================================================
// 2. 查询订单
// ======================================================================
try {
    $order = $db->selectOne(
        'SELECT o.id, o.order_no, o.user_id, o.total_price, o.status, o.remark,
                o.created_at, o.updated_at,
                u.nickname, u.username, u.phone, u.address, u.openid
         FROM orders o
         LEFT JOIN users u ON u.id = o.user_id
         WHERE o.id = ?',
        [$orderId]
    );

    if (!$order) {
        flash_set('error', '订单不存在：#' . $orderId);
        redirect('index.php');
    }

    // 明细：同时带出菜品「当前」的上架与库存情况，
    // 方便管理员判断顾客要的菜现在还能不能做。
    // order_items 里存的是下单当时的菜名与单价快照，
    // 所以菜品后来改名、改价甚至被删除，历史订单金额都不会变。
    $items = $db->select(
        'SELECT oi.id, oi.dish_id, oi.dish_name, oi.dish_price, oi.quantity,
                d.id AS dish_exists, d.is_on_sale, d.stock AS current_stock
         FROM order_items oi
         LEFT JOIN dishes d ON d.id = oi.dish_id
         WHERE oi.order_id = ?
         ORDER BY oi.id',
        [$orderId]
    );

} catch (Exception $e) {
    admin_header('订单详情', 'orders');
    echo '<div class="flash flash-error">查询失败：' . e($e->getMessage()) . '</div>';
    admin_footer();
    exit;
}

$currentStatus = OrderStatus::normalize($order['status']);
$owner = $order['nickname'] !== null && $order['nickname'] !== ''
    ? $order['nickname']
    : ($order['username'] !== null && $order['username'] !== '' ? $order['username'] : '用户' . $order['user_id']);

// 明细合计（顺便校验订单主表的金额对不对，防止历史脏数据）
$itemsTotal = 0.0;
foreach ($items as $item) {
    $itemsTotal += (float) $item['dish_price'] * (int) $item['quantity'];
}

admin_header('订单详情', 'orders', $order['order_no']);
?>

<p><a href="index.php">← 返回订单列表</a></p>

<!-- 订单概要 -->
<div class="card">
  <h2 class="card-title">订单信息</h2>

  <div class="detail-grid">
    <div class="detail-item">
      <div class="k">订单编号</div>
      <div class="v mono"><?php echo e($order['order_no']); ?></div>
    </div>
    <div class="detail-item">
      <div class="k">当前状态</div>
      <div class="v">
        <span class="badge <?php echo e(status_badge_class($currentStatus)); ?>">
          <?php echo e(OrderStatus::text($currentStatus)); ?>
        </span>
      </div>
    </div>
    <div class="detail-item">
      <div class="k">下单时间</div>
      <div class="v"><?php echo e($order['created_at']); ?></div>
    </div>
    <div class="detail-item">
      <div class="k">最后更新</div>
      <div class="v"><?php echo e($order['updated_at']); ?></div>
    </div>
    <div class="detail-item">
      <div class="k">订单金额</div>
      <div class="v"><strong><?php echo money($order['total_price']); ?></strong></div>
    </div>
    <div class="detail-item">
      <div class="k">顾客备注</div>
      <div class="v"><?php echo $order['remark'] !== '' ? e($order['remark']) : '<span class="muted">无</span>'; ?></div>
    </div>
  </div>
</div>

<!-- 顾客信息 -->
<div class="card">
  <h2 class="card-title">顾客信息</h2>

  <div class="detail-grid">
    <div class="detail-item">
      <div class="k">昵称</div>
      <div class="v"><?php echo e($owner); ?></div>
    </div>
    <div class="detail-item">
      <div class="k">用户ID</div>
      <div class="v mono">#<?php echo (int) $order['user_id']; ?></div>
    </div>
    <div class="detail-item">
      <div class="k">手机号</div>
      <div class="v"><?php echo $order['phone'] !== null && $order['phone'] !== '' ? e($order['phone']) : '<span class="muted">未填写</span>'; ?></div>
    </div>
    <div class="detail-item">
      <div class="k">默认地址</div>
      <div class="v"><?php echo $order['address'] !== null && $order['address'] !== '' ? e($order['address']) : '<span class="muted">未填写</span>'; ?></div>
    </div>
    <div class="detail-item">
      <div class="k">openid</div>
      <div class="v mono"><?php echo e($order['openid']); ?></div>
    </div>
  </div>
</div>

<!-- 菜品明细 -->
<div class="card">
  <h2 class="card-title">菜品明细</h2>

  <?php if (empty($items)): ?>
    <?php render_empty('该订单没有明细记录', '属于异常数据，可能是历史遗留订单'); ?>
  <?php else: ?>
    <div class="table-scroll">
      <table class="list">
        <thead>
          <tr>
            <th>菜品</th>
            <th class="num">单价</th>
            <th class="num">数量</th>
            <th class="num">小计</th>
            <th>菜品当前状态</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <?php $subtotal = (float) $item['dish_price'] * (int) $item['quantity']; ?>
            <tr>
              <td><?php echo e($item['dish_name']); ?></td>
              <td class="num"><?php echo money($item['dish_price']); ?></td>
              <td class="num"><?php echo (int) $item['quantity']; ?></td>
              <td class="num"><?php echo money($subtotal); ?></td>
              <td>
                <?php if ($item['dish_exists'] === null): ?>
                  <span class="badge badge-muted">菜品已删除</span>
                <?php elseif ((int) $item['is_on_sale'] !== 1): ?>
                  <span class="badge badge-warn">已下架</span>
                <?php else: ?>
                  <span class="badge badge-ok">在售</span>
                  <span class="muted">库存 <?php echo (int) $item['current_stock']; ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="3" class="num">明细合计</th>
            <th class="num"><?php echo money($itemsTotal); ?></th>
            <th></th>
          </tr>
        </tfoot>
      </table>
    </div>

    <?php if (abs($itemsTotal - (float) $order['total_price']) > 0.001): ?>
      <div class="flash flash-warn" style="margin-top:14px">
        注意：明细合计 <?php echo money($itemsTotal); ?> 与订单金额 <?php echo money($order['total_price']); ?> 不一致，请核查该订单数据。
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- 可执行操作 -->
<div class="card">
  <h2 class="card-title">状态操作</h2>

  <?php $nextStates = OrderStatus::nextStates($currentStatus); ?>

  <?php if (empty($nextStates)): ?>
    <p class="muted">
      「<?php echo e(OrderStatus::text($currentStatus)); ?>」是终态，不能再流转。
    </p>
  <?php else: ?>
    <p class="muted" style="margin-top:0">
      状态流转规则：待支付 → 已支付 → 已完成；待支付或已支付都可以取消。
      取消订单会把库存按明细加回去。
    </p>

    <div class="form-actions">
      <?php foreach ($nextStates as $targetStatus => $targetText): ?>
        <?php
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
        <form method="post" action="order.php?id=<?php echo (int) $order['id']; ?>" class="inline-form"
              <?php echo $actionName === 'cancel' ? 'onsubmit="return confirm(\'取消订单会按明细把库存加回去，确定继续吗？\');"' : ''; ?>>
          <?php echo AdminAuth::csrfField(); ?>
          <input type="hidden" name="order_id" value="<?php echo (int) $order['id']; ?>">
          <input type="hidden" name="action" value="<?php echo e($actionName); ?>">
          <button type="submit" class="btn <?php echo e($btnClass); ?>">
            标记为「<?php echo e($targetText); ?>」
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>

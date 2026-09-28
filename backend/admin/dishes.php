<?php
/**
 * 管理后台 - 菜品管理
 *
 * 功能：菜品列表、按分类/状态/关键词筛选、行内改库存、上架下架、推荐标记、
 *      新增与编辑（dish_edit.php）、删除
 *
 * 【写操作全部 POST + CSRF】包括「上架/下架」这种看起来无害的开关：
 * 下架会直接影响小程序端能不能看到这道菜，属于改变数据，必须防 CSRF
 * 与浏览器/爬虫的意外触发。
 *
 * 【删除菜品的影响】
 *   - cart 表对 dishes 有外键 ON DELETE CASCADE，购物车里的该菜会一起被清掉（符合预期）
 *   - order_items 表不引用 dishes，而且冗余存了下单当时的菜名与单价，
 *     所以历史订单的金额和内容完全不受影响（这正是当初冗余存储的目的）
 *   - 已下架不等于删除：下架只是不在小程序里展示，数据与历史订单都留着。
 *     不确定时应该用下架，而不是删除。
 */
define('TW_ADMIN', true);
require_once __DIR__ . '/lib/bootstrap.php';

AdminAuth::requireLogin();

$db = DB::getInstance();

// ======================================================================
// 1. 处理写操作（POST）
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AdminAuth::requireCsrf();

    $dishId = int_param($_POST, 'dish_id', 0, 0);
    $action = str_param($_POST, 'action', '', 20);

    if ($dishId <= 0) {
        flash_set('error', '参数错误：缺少菜品ID');
        redirect(url_with([]));
    }

    try {
        // 先取出菜品，用于拼提示语，同时确认它确实存在
        $dish = $db->selectOne('SELECT id, name, stock, is_on_sale, is_recommend FROM dishes WHERE id = ?', [$dishId]);

        if (!$dish) {
            flash_set('error', '菜品不存在：#' . $dishId);
            redirect(url_with([]));
        }

        switch ($action) {

            case 'toggle_sale':
                $to = (int) $dish['is_on_sale'] === 1 ? 0 : 1;
                $db->execute('UPDATE dishes SET is_on_sale = ? WHERE id = ?', [$to, $dishId]);
                flash_set(
                    'success',
                    '「' . $dish['name'] . '」已' . ($to === 1 ? '上架，小程序端可见' : '下架，小程序端不再展示')
                );
                break;

            case 'toggle_recommend':
                $to = (int) $dish['is_recommend'] === 1 ? 0 : 1;
                $db->execute('UPDATE dishes SET is_recommend = ? WHERE id = ?', [$to, $dishId]);
                flash_set('success', '「' . $dish['name'] . '」已' . ($to === 1 ? '设为推荐' : '取消推荐'));
                break;

            case 'set_stock':
                $newStock = int_param($_POST, 'stock', 0, 0, 999999);
                $db->execute('UPDATE dishes SET stock = ? WHERE id = ?', [$newStock, $dishId]);
                flash_set('success', '「' . $dish['name'] . '」库存：' . (int) $dish['stock'] . ' → ' . $newStock);
                break;

            case 'delete':
                $db->execute('DELETE FROM dishes WHERE id = ?', [$dishId]);
                flash_set(
                    'success',
                    '「' . $dish['name'] . '」已删除（历史订单不受影响；该菜也会从各用户购物车中移除）'
                );
                break;

            default:
                flash_set('error', '未知操作：' . $action);
        }

    } catch (Exception $e) {
        flash_set('error', '操作失败：' . $e->getMessage());
    }

    redirect(url_with([]));
}

// ======================================================================
// 2. 解析筛选条件
// ======================================================================
$categoryFilter = int_param($_GET, 'category_id', 0, 0);
$saleFilter     = str_param($_GET, 'on_sale', '', 5);     // '' 全部 | '1' 已上架 | '0' 已下架
$keyword        = str_param($_GET, 'kw', '', 50);

$where  = [];
$params = [];

if ($categoryFilter > 0) {
    $where[]  = 'd.category_id = ?';
    $params[] = $categoryFilter;
}

if ($saleFilter === '0' || $saleFilter === '1') {
    $where[]  = 'd.is_on_sale = ?';
    $params[] = $saleFilter;
}

if ($keyword !== '') {
    $like     = '%' . like_escape($keyword) . '%';
    $where[]  = '(d.name LIKE ? OR d.description LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}

$whereSql = empty($where) ? '' : ('WHERE ' . implode(' AND ', $where));

// ======================================================================
// 3. 查询
// ======================================================================
try {
    $categories = $db->select('SELECT id, name FROM categories ORDER BY sort_order DESC, id');

    // 菜品是「菜单级」数据（几十条），一次性全部列出即可，不做分页
    $dishes = $db->select(
        "SELECT d.id, d.category_id, d.name, d.price, d.image_url, d.description,
                d.spiciness, d.is_recommend, d.stock, d.is_on_sale,
                c.name AS category_name
         FROM dishes d
         LEFT JOIN categories c ON c.id = d.category_id
         $whereSql
         ORDER BY d.category_id, d.id",
        $params
    );

    $stats = $db->selectOne(
        'SELECT COUNT(*) AS total,
                SUM(is_on_sale = 1) AS on_sale,
                SUM(is_on_sale = 0) AS off_sale,
                SUM(stock <= 0)     AS out_of_stock,
                SUM(is_recommend = 1) AS recommend
         FROM dishes'
    );

} catch (Exception $e) {
    admin_header('菜品管理', 'dishes');
    echo '<div class="flash flash-error">查询失败：' . e($e->getMessage()) . '</div>';
    admin_footer();
    exit;
}

admin_header('菜品管理', 'dishes', '共 ' . count($dishes) . ' 道菜符合当前筛选条件');
?>

<!-- 总览 -->
<div class="stat-row">
  <div class="stat">
    <div class="stat-label">菜品总数</div>
    <div class="stat-value"><?php echo (int) $stats['total']; ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">已上架</div>
    <div class="stat-value"><?php echo (int) $stats['on_sale']; ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">已下架</div>
    <div class="stat-value"><?php echo (int) $stats['off_sale']; ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">零库存</div>
    <div class="stat-value accent"><?php echo (int) $stats['out_of_stock']; ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">推荐位</div>
    <div class="stat-value"><?php echo (int) $stats['recommend']; ?></div>
  </div>
</div>

<!-- 筛选 -->
<div class="card">
  <form method="get" action="dishes.php" class="filters">
    <div class="field">
      <label for="f-cat">分类</label>
      <select id="f-cat" name="category_id">
        <option value="0">全部分类</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?php echo (int) $cat['id']; ?>" <?php echo $categoryFilter === (int) $cat['id'] ? 'selected' : ''; ?>>
            <?php echo e($cat['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="field">
      <label for="f-sale">上架状态</label>
      <select id="f-sale" name="on_sale">
        <option value=""  <?php echo $saleFilter === ''  ? 'selected' : ''; ?>>全部</option>
        <option value="1" <?php echo $saleFilter === '1' ? 'selected' : ''; ?>>已上架</option>
        <option value="0" <?php echo $saleFilter === '0' ? 'selected' : ''; ?>>已下架</option>
      </select>
    </div>

    <div class="field">
      <label for="f-kw">关键词</label>
      <input type="text" id="f-kw" name="kw" value="<?php echo e($keyword); ?>" placeholder="菜名或描述">
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary">筛选</button>
      <a class="btn btn-ghost" href="dishes.php">重置</a>
      <a class="btn btn-ok" href="dish_edit.php">＋ 新增菜品</a>
    </div>
  </form>
</div>

<!-- 列表 -->
<div class="card">
  <?php if (empty($dishes)): ?>
    <?php render_empty('没有符合条件的菜品', '换个筛选条件，或者点「新增菜品」加一道'); ?>
  <?php else: ?>
    <div class="table-scroll">
      <table class="list">
        <thead>
          <tr>
            <th>图片</th>
            <th>菜名</th>
            <th>分类</th>
            <th class="num">价格</th>
            <th class="num">辣度</th>
            <th>库存</th>
            <th>状态</th>
            <th>推荐</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dishes as $dish): ?>
            <?php
              $onSale    = (int) $dish['is_on_sale'] === 1;
              $isRec     = (int) $dish['is_recommend'] === 1;
              $outOfStock = (int) $dish['stock'] <= 0;
            ?>
            <tr>
              <td>
                <?php if ($dish['image_url'] !== ''): ?>
                  <img class="preview-img" style="width:56px;height:56px" src="<?php echo e($dish['image_url']); ?>" alt="">
                <?php else: ?>
                  <span class="muted">无图</span>
                <?php endif; ?>
              </td>

              <td>
                <div><?php echo e($dish['name']); ?></div>
                <div class="muted" style="font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                  <?php echo e($dish['description']); ?>
                </div>
              </td>

              <td class="nowrap"><?php echo e($dish['category_name'] !== null ? $dish['category_name'] : '未分类'); ?></td>

              <td class="num"><?php echo money($dish['price']); ?></td>

              <td class="num"><?php echo (int) $dish['spiciness']; ?></td>

              <td>
                <!-- 行内改库存：改完立即保存，不用进编辑页 -->
                <form method="post" action="<?php echo e(url_with([])); ?>" class="inline-form">
                  <?php echo AdminAuth::csrfField(); ?>
                  <input type="hidden" name="dish_id" value="<?php echo (int) $dish['id']; ?>">
                  <input type="hidden" name="action" value="set_stock">
                  <input type="number" name="stock" value="<?php echo (int) $dish['stock']; ?>"
                         min="0" max="999999" style="width:88px">
                  <button type="submit" class="btn btn-ghost btn-sm">保存</button>
                </form>
                <?php if ($outOfStock): ?>
                  <div><span class="badge badge-warn">已售完</span></div>
                <?php endif; ?>
              </td>

              <td>
                <span class="badge <?php echo $onSale ? 'badge-ok' : 'badge-muted'; ?>">
                  <?php echo $onSale ? '已上架' : '已下架'; ?>
                </span>
              </td>

              <td>
                <span class="badge <?php echo $isRec ? 'badge-info' : 'badge-muted'; ?>">
                  <?php echo $isRec ? '推荐' : '普通'; ?>
                </span>
              </td>

              <td class="actions">
                <div class="row-actions">
                  <a class="btn btn-ghost btn-sm" href="dish_edit.php?id=<?php echo (int) $dish['id']; ?>">编辑</a>

                  <!-- 上架/下架 -->
                  <form method="post" action="<?php echo e(url_with([])); ?>" class="inline-form">
                    <?php echo AdminAuth::csrfField(); ?>
                    <input type="hidden" name="dish_id" value="<?php echo (int) $dish['id']; ?>">
                    <input type="hidden" name="action" value="toggle_sale">
                    <button type="submit" class="btn <?php echo $onSale ? 'btn-ghost' : 'btn-ok'; ?> btn-sm">
                      <?php echo $onSale ? '下架' : '上架'; ?>
                    </button>
                  </form>

                  <!-- 推荐标记 -->
                  <form method="post" action="<?php echo e(url_with([])); ?>" class="inline-form">
                    <?php echo AdminAuth::csrfField(); ?>
                    <input type="hidden" name="dish_id" value="<?php echo (int) $dish['id']; ?>">
                    <input type="hidden" name="action" value="toggle_recommend">
                    <button type="submit" class="btn btn-ghost btn-sm">
                      <?php echo $isRec ? '取消推荐' : '设为推荐'; ?>
                    </button>
                  </form>

                  <!-- 删除：二次确认 -->
                  <form method="post" action="<?php echo e(url_with([])); ?>" class="inline-form"
                        onsubmit="return confirm('确定删除「<?php echo e($dish['name']); ?>」吗？\n\n如果只是暂时不卖，建议用「下架」而不是删除。\n历史订单不受影响。');">
                    <?php echo AdminAuth::csrfField(); ?>
                    <input type="hidden" name="dish_id" value="<?php echo (int) $dish['id']; ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger btn-sm">删除</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>

<?php
/**
 * 管理后台 - 新增 / 编辑菜品
 *
 * 同一个页面承担新增与编辑两件事，由 URL 上的 id 决定：
 *   dish_edit.php        新增
 *   dish_edit.php?id=3   编辑 #3
 *
 * 【为什么图片地址要校验协议】
 *   image_url 会在两个地方作为 <img>/<image> 的 src 输出：
 *   后台菜品列表，以及小程序端菜品卡片。
 *   如果允许任意字符串，就可以填 javascript: 这类伪协议。
 *   这里只放行 http/https，从源头挡掉。
 *
 * 【价格用字符串写库】
 *   DECIMAL 列用 sprintf('%.2f') 转成字符串再存，
 *   避免浮点数在传输/转换过程中出现 28.000000000000004 这种脏值。
 */
define('TW_ADMIN', true);
require_once __DIR__ . '/lib/bootstrap.php';

AdminAuth::requireLogin();

$db = DB::getInstance();

$editingId = int_param($_GET, 'id', 0, 0);
$isEdit    = $editingId > 0;

// 表单默认值（同时也是允许提交的字段白名单）
$form = [
    'name'         => '',
    'category_id'  => 0,
    'price'        => '',
    'image_url'    => '',
    'description'  => '',
    'spiciness'    => 0,
    'is_recommend' => 0,
    'stock'        => 999,
    'is_on_sale'   => 1,
];

$errors = [];

// ======================================================================
// 1. 编辑模式：先读出原有数据用于回显
// ======================================================================
if ($isEdit) {
    $existing = admin_dish_find($editingId);

    if (!$existing) {
        flash_set('error', '菜品不存在：#' . $editingId);
        redirect('dishes.php');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // 只取表单里的字段，忽略 extra_field1/2 等其余列
        $form = array_merge($form, array_intersect_key($existing, $form));
    }
}

// 分类下拉（SQL 见 admin/lib/queries/dishes.php）
$categories = admin_category_options();

// ======================================================================
// 2. 处理提交
// ======================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    AdminAuth::requireCsrf();

    $form['name']        = str_param($_POST, 'name', '', 100);
    $form['description'] = str_param($_POST, 'description', '', 500);
    $form['image_url']   = str_param($_POST, 'image_url', '', 500);
    $form['category_id'] = int_param($_POST, 'category_id', 0, 0);
    $form['spiciness']   = int_param($_POST, 'spiciness', 0, 0, 5);
    $form['stock']       = int_param($_POST, 'stock', 0, 0, 999999);
    $form['price']       = str_param($_POST, 'price', '', 20);
    // 复选框：勾选时浏览器才会提交这个键
    $form['is_recommend'] = isset($_POST['is_recommend']) ? 1 : 0;
    $form['is_on_sale']   = isset($_POST['is_on_sale']) ? 1 : 0;

    // ---------- 校验 ----------
    if ($form['name'] === '') {
        $errors['name'] = '菜名不能为空';
    }

    // 分类必须真实存在，否则外键会报错、或者菜挂在不存在的分类下
    $categoryOk = false;
    foreach ($categories as $cat) {
        if ((int) $cat['id'] === (int) $form['category_id']) {
            $categoryOk = true;
            break;
        }
    }
    if (!$categoryOk) {
        $errors['category_id'] = '请选择所属分类';
    }

    $priceValue = 0.0;
    if ($form['price'] === '' || !is_numeric($form['price'])) {
        $errors['price'] = '请填写价格（数字）';
    } else {
        $priceValue = round((float) $form['price'], 2);
        if ($priceValue < 0) {
            $errors['price'] = '价格不能为负数';
        } elseif ($priceValue > 999999.99) {
            $errors['price'] = '价格超出可存储范围';
        }
    }

    if ($form['image_url'] !== '' && preg_match('#^https?://#i', $form['image_url']) !== 1) {
        $errors['image_url'] = '图片地址必须以 http:// 或 https:// 开头';
    }

    // ---------- 写库 ----------
    if (empty($errors)) {
        try {
            if ($isEdit) {
                $db->execute(
                    'UPDATE dishes
                     SET category_id = ?, name = ?, price = ?, image_url = ?, description = ?,
                         spiciness = ?, is_recommend = ?, stock = ?, is_on_sale = ?
                     WHERE id = ?',
                    [
                        (int) $form['category_id'],
                        $form['name'],
                        sprintf('%.2f', $priceValue),
                        $form['image_url'],
                        $form['description'],
                        (int) $form['spiciness'],
                        (int) $form['is_recommend'],
                        (int) $form['stock'],
                        (int) $form['is_on_sale'],
                        $editingId,
                    ]
                );
                flash_set('success', '「' . $form['name'] . '」已保存');

            } else {
                $newId = $db->insert(
                    'INSERT INTO dishes (category_id, name, price, image_url, description,
                                         spiciness, is_recommend, stock, is_on_sale)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        (int) $form['category_id'],
                        $form['name'],
                        sprintf('%.2f', $priceValue),
                        $form['image_url'],
                        $form['description'],
                        (int) $form['spiciness'],
                        (int) $form['is_recommend'],
                        (int) $form['stock'],
                        (int) $form['is_on_sale'],
                    ]
                );
                flash_set('success', '已新增菜品「' . $form['name'] . '」（#' . $newId . '）');
            }

            redirect('dishes.php');

        } catch (Exception $e) {
            $errors['_global'] = '保存失败：' . $e->getMessage();
        }
    }
}

$pageTitle = $isEdit ? '编辑菜品' : '新增菜品';
admin_header($pageTitle, 'dishes', $isEdit ? ('#' . $editingId) : '');
?>

<p><a href="dishes.php">← 返回菜品列表</a></p>

<?php if (isset($errors['_global'])): ?>
  <div class="flash flash-error"><?php echo e($errors['_global']); ?></div>
<?php endif; ?>

<form method="post" action="dish_edit.php<?php echo $isEdit ? '?id=' . (int) $editingId : ''; ?>">
  <?php echo AdminAuth::csrfField(); ?>

  <div class="card">
    <h2 class="card-title">基本信息</h2>

    <div class="form-grid">

      <div class="field">
        <label for="f-name">菜名 <span style="color:#d94a4a">*</span></label>
        <input type="text" id="f-name" name="name" value="<?php echo e($form['name']); ?>" maxlength="100" placeholder="例如：宫保鸡丁">
        <?php if (isset($errors['name'])): ?>
          <div class="form-hint" style="color:#d94a4a"><?php echo e($errors['name']); ?></div>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="f-category">所属分类 <span style="color:#d94a4a">*</span></label>
        <select id="f-category" name="category_id">
          <option value="0">请选择分类</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?php echo (int) $cat['id']; ?>"
              <?php echo (int) $form['category_id'] === (int) $cat['id'] ? 'selected' : ''; ?>>
              <?php echo e($cat['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['category_id'])): ?>
          <div class="form-hint" style="color:#d94a4a"><?php echo e($errors['category_id']); ?></div>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="f-price">价格（元） <span style="color:#d94a4a">*</span></label>
        <input type="text" id="f-price" name="price" value="<?php echo e($form['price']); ?>" placeholder="28.00">
        <?php if (isset($errors['price'])): ?>
          <div class="form-hint" style="color:#d94a4a"><?php echo e($errors['price']); ?></div>
        <?php else: ?>
          <div class="form-hint">支持两位小数，例如 28.50</div>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="f-stock">库存数量</label>
        <input type="number" id="f-stock" name="stock" value="<?php echo (int) $form['stock']; ?>" min="0" max="999999">
        <div class="form-hint">设为 0 表示已售完，小程序端仍会展示但无法下单</div>
      </div>

      <div class="field">
        <label for="f-spiciness">辣度（0-5）</label>
        <input type="number" id="f-spiciness" name="spiciness" value="<?php echo (int) $form['spiciness']; ?>" min="0" max="5">
      </div>

      <div class="field">
        <label>状态</label>
        <label style="font-size:14px;color:#2c3038;display:flex;align-items:center;gap:8px;padding-top:6px">
          <input type="checkbox" name="is_on_sale" value="1" style="width:auto" <?php echo (int) $form['is_on_sale'] === 1 ? 'checked' : ''; ?>>
          上架（取消勾选后小程序端不再展示这道菜）
        </label>
        <label style="font-size:14px;color:#2c3038;display:flex;align-items:center;gap:8px">
          <input type="checkbox" name="is_recommend" value="1" style="width:auto" <?php echo (int) $form['is_recommend'] === 1 ? 'checked' : ''; ?>>
          设为推荐菜品
        </label>
      </div>

      <div class="field span-2">
        <label for="f-desc">菜品描述</label>
        <textarea id="f-desc" name="description" maxlength="500" placeholder="例如：经典川菜，鸡肉嫩滑，花生香脆"><?php echo e($form['description']); ?></textarea>
      </div>

      <div class="field span-2">
        <label for="f-image">图片地址</label>
        <div class="preview-wrap">
          <input type="text" id="f-image" name="image_url" value="<?php echo e($form['image_url']); ?>"
                 placeholder="https://..." oninput="document.getElementById('img-preview').src = this.value;">
          <?php if ($form['image_url'] !== ''): ?>
            <img id="img-preview" class="preview-img" src="<?php echo e($form['image_url']); ?>" alt="预览">
          <?php else: ?>
            <img id="img-preview" class="preview-img" src="" alt="预览" style="display:none"
                 onerror="this.style.display='none'" onload="this.style.display='block'">
          <?php endif; ?>
        </div>
        <?php if (isset($errors['image_url'])): ?>
          <div class="form-hint" style="color:#d94a4a"><?php echo e($errors['image_url']); ?></div>
        <?php else: ?>
          <div class="form-hint">必须以 http:// 或 https:// 开头（小程序端需要能被公网访问的图片地址）</div>
        <?php endif; ?>
      </div>

    </div>

    <div class="form-actions" style="margin-top:18px">
      <button type="submit" class="btn btn-primary"><?php echo $isEdit ? '保存修改' : '新增菜品'; ?></button>
      <a class="btn btn-ghost" href="dishes.php">取消</a>
    </div>
  </div>
</form>

<?php admin_footer(); ?>

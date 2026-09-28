<?php
/**
 * 接口：创建订单（下单结算）
 * 请求地址：POST /api/order/create.php
 * 请求头：token: <登录令牌>
 * 入参JSON：{"remark":"少放辣"}
 * 返回：{"order_id":10,"order_no":"...","total_price":"56.00","status":0}
 * 对应前端：购物车页面"结算"按钮
 * 
 * 【鉴权说明】下单用户取自令牌，客户端传入的 user_id 一律忽略，
 *            因此无法替他人下单或清空他人购物车。
 * 
 * 【订单状态】新建订单状态为 0（待支付），
 *            后续通过 order/pay.php（支付）、order/cancel.php（取消）、
 *            order/complete.php（完成）流转。
 * 
 * ============================================
 * 【为什么不会超卖】（重点）
 * 
 * 老实现是「先 SELECT 查库存 → 判断够不够 → 再无条件扣减」，两步之间有时间差：
 * 两个用户同时下单时都可能读到「库存=1、够用」，然后各自扣减一次，库存变成 -1。
 * （实测复现：把库存设为 1 后并发下两单，两单都成交、库存 -1）
 * 
 * 现在改成把「库存充足」这个条件直接写进 UPDATE 的 WHERE：
 *     UPDATE dishes SET stock = stock - :数量 WHERE id = :菜品 AND stock >= :数量
 * 检查与扣减合并成一条原子语句，由 InnoDB 行锁保证同一行的扣减是串行的：
 * 谁先抢到谁扣，后到的影响行数为 0 → 立即回滚并返回 409。
 * 因此无论多少请求并发，库存都不可能被扣成负数。
 * 
 * 同时为了避免「同一用户重复点击结算」生成两笔订单，购物车在事务内以
 * FOR UPDATE 读取（只锁 cart 表，不锁 dishes，避免跨用户死锁）。
 * ============================================
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/Auth.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

$input  = json_decode(file_get_contents('php://input'), true);
$remark = isset($input['remark']) ? trim($input['remark']) : '';

try {
    $db = DB::getInstance();

    // 订单编号在事务外先生成，减少持锁时间
    $orderNo = date('YmdHis') . rand(1000, 9999);

    $db->beginTransaction();

    try {
        // ============================================
        // 1. 锁住当前用户的购物车行
        //    只锁 cart 表：同一用户的并发请求会串行执行，
        //    因此重复点击「结算」时，第二个请求会读到已清空的购物车。
        //    （不锁 dishes，避免不同用户因菜品加锁顺序不同而死锁）
        // ============================================
        $db->select('SELECT id FROM cart WHERE user_id = ? FOR UPDATE', [$userId]);

        // 2. 读取购物车明细（含菜品名称与单价）
        //    注：菜品若被删除，cart 行的外键 ON DELETE CASCADE 会级联删除，
        //        所以这里不会出现「购物车里挂着已删除菜品」的情况。
        $cartItems = $db->select(
            'SELECT c.id as cart_id, c.quantity, d.id as dish_id, d.name, d.price, d.is_on_sale
             FROM cart c
             JOIN dishes d ON c.dish_id = d.id
             WHERE c.user_id = ?',
            [$userId]
        );

        if (empty($cartItems)) {
            $db->rollback();
            Response::error('购物车为空，无法下单');
        }

        // 2.1 下架拦截
        //     菜品可能在顾客加购之后被管理后台下架，此时不能再卖出去。
        //     这里逐项检查而不是在 JOIN 上加条件，是为了能明确告诉顾客
        //     是哪道菜下架了 —— 如果直接从结果集里过滤掉，顾客只会看到
        //     购物车里的菜莫名其妙消失或金额对不上。
        foreach ($cartItems as $item) {
            if ((int) $item['is_on_sale'] !== 1) {
                $db->rollback();
                Response::error('「' . $item['name'] . '」已下架，请从购物车中移除后再结算', 409);
            }
        }

        // 3. 计算总价（单价来自数据库，不信任客户端传值）
        $totalPrice = 0;
        foreach ($cartItems as $item) {
            $totalPrice += $item['price'] * $item['quantity'];
        }

        // 4. 插入订单主表（状态 0 = 待支付）
        $orderId = $db->insert(
            'INSERT INTO orders (order_no, user_id, total_price, status, remark) VALUES (?, ?, ?, 0, ?)',
            [$orderNo, $userId, $totalPrice, $remark]
        );

        // 5. 插入订单明细 + 原子扣减库存（防超卖的关键）
        foreach ($cartItems as $item) {
            $db->insert(
                'INSERT INTO order_items (order_id, dish_id, dish_name, dish_price, quantity) VALUES (?, ?, ?, ?, ?)',
                [$orderId, $item['dish_id'], $item['name'], $item['price'], $item['quantity']]
            );

            // ★ 库存条件写进 WHERE：检查与扣减是一条原子语句
            $affected = $db->execute(
                'UPDATE dishes SET stock = stock - ? WHERE id = ? AND stock >= ?',
                [$item['quantity'], $item['dish_id'], $item['quantity']]
            );

            if ($affected <= 0) {
                // 影响行数为 0：库存已被并发请求抢走（或本来就不够）
                // 回滚整个事务——已插入的订单/明细、以及前面几项的扣减都会撤销
                $db->rollback();
                Response::error("「{$item['name']}」库存不足，请调整数量后重试", 409);
            }
        }

        // 6. 清空购物车
        $db->execute('DELETE FROM cart WHERE user_id = ?', [$userId]);

        // 7. 提交事务
        $db->commit();

        Response::success([
            'order_id'    => $orderId,
            'order_no'    => $orderNo,
            'total_price' => $totalPrice,
            'status'      => 0,          // 0 = 待支付
        ], '下单成功');

    } catch (Exception $e) {
        // 任何异常都回滚（注意上面的 Response::error 内部已 exit，不会走到这里）
        if ($db->getPdo()->inTransaction()) {
            $db->rollback();
        }
        throw $e;
    }

} catch (Exception $e) {
    Response::error('下单失败：' . $e->getMessage(), 500);
}

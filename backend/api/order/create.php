<?php
/**
 * 接口：创建订单（下单结算）
 *
 * 请求头：token: <登录令牌>
 * 入参JSON：{"remark":"少放辣"}
 * 请求地址：POST /api/order/create.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requirePost();

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

$remark = Request::str('remark');

// ============================================
// 顺手清理超时未支付的订单（懒清理，见 core/OrderExpiry.php）
//
// 放在这里的原因很实在：顾客刚才那笔超时未支付的订单会一直占着库存，
// 如果不先清掉，他这次结算可能因为「库存不足」而失败 ——
// 而缺的正是他自己上一笔没付钱的订单占掉的那一份。
// 第二步的全量清理带概率门控，只偶尔做一次。
// ============================================
OrderExpiry::expireStale($userId);
OrderExpiry::expireStale(null);

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
    Response::serverError('下单失败', $e);
}

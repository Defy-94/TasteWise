<?php
/**
 * 超时未支付订单的自动取消（懒清理）
 *
 * ============================================
 * 【为什么需要它】
 *   下单时会立即扣库存（这是防超卖的必要设计），但库存只有在
 *   「取消订单」时才会还回去。如果顾客下单后直接走人、既不付款也不取消，
 *   那份库存就被**永久占用**了 —— 库存在 999 时看不出来，
 *   一旦某个菜只剩一两份，就等于被一个幽灵订单吃掉了，而且再也回不来。
 *
 *   正常做法是定时任务扫一遍超时订单。但这个项目跑在 phpstudy 上，
 *   没有可靠的 cron 保证，所以改用**懒清理**：
 *   在「下单」「查看我的订单」「后台看订单」这些本来就要访问订单的场景里
 *   顺手清理一批（和 core/AiRateLimit.php 的概率性清理是同一个思路）。
 * ============================================
 *
 * ============================================
 * 【为什么不自己实现取消，而是调用 OrderStatus】
 *   取消订单要做的事不止改状态：还要在事务里加行锁、按订单明细把库存加回去、
 *   用「原状态」做原子 CAS 防止并发重复取消。
 *   这些逻辑已经在 core/OrderStatus.php 里实现了一遍，
 *   这里如果再写一遍，两边早晚会不一致（典型后果就是库存被重复加回）。
 *   所以这里只负责「找出该清理哪些订单」，真正的流转交给 OrderStatus。
 * ============================================
 */

class OrderExpiry
{
    /**
     * 待支付订单保留多久（分钟），超过就自动取消并归还库存
     *
     * 15 分钟是外卖/点餐类应用的常见值：够用户完成支付，
     * 又不会让库存被占太久。想让顾客有更多时间就调大它。
     */
    const TIMEOUT_MINUTES = 15;

    /** 单次最多处理多少笔，避免一次请求里扫出成百上千笔拖慢响应 */
    const BATCH_LIMIT = 50;

    /**
     * 全量清理的概率分母：平均每 N 次调用才真的做一次全表清理
     *
     * 单用户的清理用的是 user_id 索引、开销很小，每次都做；
     * 而全量清理要按「状态 + 时间」扫，没必要每个请求都扫一遍。
     */
    const GLOBAL_GC_DIVISOR = 20;

    /** 自动取消时追加到备注末尾的说明，方便后台看出这笔为什么被取消 */
    const CANCEL_NOTE = '（超时未支付，系统自动取消）';

    /**
     * 清理超时未支付的订单
     *
     * @param int|null $userId 传用户ID则只清理该用户的订单；
     *                         传 null 表示不限定（全量清理，默认带概率门控）
     * @param bool     $force  为 true 时忽略概率门控，强制做一次全量清理
     *                         （后台的「清理超时订单」按钮用）
     *
     * @return int 实际取消掉的订单数
     */
    public static function expireStale($userId = null, $force = false)
    {
        $db = DB::getInstance();

        // 全量清理的概率门控
        if ($userId === null && !$force && mt_rand(1, self::GLOBAL_GC_DIVISOR) !== 1) {
            return 0;
        }

        // ⚠️ 截止时间交给 MySQL 用 NOW() 算，不要在 PHP 里 date() 出来传入：
        //    created_at 是 MySQL 按它自己的时区写入的，而 PHP 的时区未必一致，
        //    用 PHP 算会让「超时」判断整体偏移几个小时（可能把刚下的单当成超时）。
        //    TIMEOUT_MINUTES 是本类常量（整数），拼进 SQL 不存在注入风险。
        $sql = 'SELECT id, user_id FROM orders
                WHERE status = ?
                  AND created_at < DATE_SUB(NOW(), INTERVAL ' . (int) self::TIMEOUT_MINUTES . ' MINUTE)';

        $params = [(string) OrderStatus::PENDING];

        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $params[] = (int) $userId;
        }

        $sql .= ' ORDER BY id ASC LIMIT ' . (int) self::BATCH_LIMIT;

        try {
            $rows = $db->select($sql, $params);
        } catch (Exception $e) {
            // 清理是「顺手做的好事」，查询失败绝不能影响调用方的主流程
            return 0;
        }

        if (empty($rows)) {
            return 0;
        }

        $expired = 0;

        foreach ($rows as $row) {
            $orderId = (int) $row['id'];

            try {
                // 真正的流转交给唯一实现：
                // 事务 + 行锁 + 原子 CAS + 按订单明细把库存加回去
                OrderStatus::transition($orderId, OrderStatus::CANCELED, $userId);

                // 在备注里留个痕迹，方便后台和顾客看出「为什么被取消了」
                // 用 LEFT(..., 200) 兜住 remark varchar(200) 的长度限制 ——
                // 严格模式下超长会直接报错，不能想当然地直接 CONCAT
                $db->execute(
                    'UPDATE orders SET remark = LEFT(CONCAT(remark, ?), 200) WHERE id = ?',
                    [self::CANCEL_NOTE, $orderId]
                );

                $expired++;

            } catch (OrderStatusException $e) {
                // 并发下很可能已被另一个请求处理掉了（状态冲突 409）或订单不存在（404），
                // 这属于正常竞争结果，跳过即可，不要当成错误
            } catch (Exception $e) {
                // 单笔失败不影响同批次的其它订单
            }
        }

        return $expired;
    }
}

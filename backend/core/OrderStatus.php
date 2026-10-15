<?php
/**
 * 订单状态流转（接口层与管理后台共用的唯一实现）
 *
 * 如果后台另写一份，就会出现两套「什么状态能变成什么」的规则，
 * 尤其「取消订单按明细把库存加回去」一旦两边不一致，库存就会对不上账。
 *
 * 状态机：0待支付 →(支付) 1已支付 →(完成) 2已完成；0/1 →(取消) 3已取消。
 * 2 与 3 是终态。数据库里 status 是 varchar，本类统一转 int 再比较。
 *
 * 并发安全：流转在事务内完成 —— 先 SELECT ... FOR UPDATE 锁住订单行，
 * 再用带原状态条件的 UPDATE（CAS），影响行数为 0 说明状态已被并发改掉，
 * 回滚并返回 409。两层叠加，防重复取消把库存加回多次、也防重复支付/完成。
 */

/**
 * 订单流转业务异常
 * 携带 HTTP 状态码，供接口层直接映射为响应码（400 参数错 / 404 不存在 / 409 状态冲突）
 */
class OrderStatusException extends Exception
{
    /** @var int HTTP 状态码 */
    private $httpCode;

    public function __construct($message, $httpCode = 400)
    {
        parent::__construct($message);
        $this->httpCode = (int) $httpCode;
    }

    /** @return int */
    public function getHttpCode()
    {
        return $this->httpCode;
    }
}

class OrderStatus
{
    /** 待支付 */
    const PENDING = 0;
    /** 已支付 */
    const PAID = 1;
    /** 已完成 */
    const DONE = 2;
    /** 已取消 */
    const CANCELED = 3;

    /** 状态 → 中文文本 */
    private static $textMap = [
        self::PENDING  => '待支付',
        self::PAID     => '已支付',
        self::DONE     => '已完成',
        self::CANCELED => '已取消',
    ];

    /** 合法流转表：原状态 → 允许的目标状态（2/3 为终态，否则库存回滚会重复执行） */
    private static $allowed = [
        self::PENDING  => [self::PAID, self::CANCELED],
        self::PAID     => [self::DONE, self::CANCELED],
        self::DONE     => [],
        self::CANCELED => [],
    ];

    /** 全部状态文本（管理后台筛选用） */
    public static function allText()
    {
        return self::$textMap;
    }

    /** 状态转文本（未知状态返回「未知(N)」） */
    public static function text($status)
    {
        $status = (int) $status;
        return isset(self::$textMap[$status]) ? self::$textMap[$status] : '未知(' . $status . ')';
    }

    /** 状态规范化成 int；非数字按 0 处理，避免订单卡在无法操作的中间态 */
    public static function normalize($status)
    {
        return is_numeric($status) ? (int) $status : self::PENDING;
    }

    /** 是否为终态（已完成/已取消，不能再流转） */
    public static function isFinal($status)
    {
        return count(self::nextStates($status)) === 0;
    }

    /**
     * 判断某个流转是否合法
     */
    public static function canTransition($from, $to)
    {
        $from = self::normalize($from);
        $to   = (int) $to;

        return isset(self::$allowed[$from]) && in_array($to, self::$allowed[$from], true);
    }

    /** 某状态允许到达的目标状态（后台据此决定显示哪些按钮），空数组表示终态 */
    public static function nextStates($from)
    {
        $from  = self::normalize($from);
        $nexts = isset(self::$allowed[$from]) ? self::$allowed[$from] : [];

        $result = [];
        foreach ($nexts as $to) {
            $result[$to] = self::$textMap[$to];
        }
        return $result;
    }

    /**
     * 执行订单状态流转
     * @param int|null $userId 传用户 ID 表示只能操作本人订单（小程序端）；null 不限定归属（后台）
     * @return array ['order_id','order_no','status','status_text', 'restored_items'?]
     * @throws OrderStatusException 业务错误（含 HTTP 状态码）
     */
    public static function transition($orderId, $to, $userId = null)
    {
        $orderId = (int) $orderId;
        $to      = (int) $to;

        if ($orderId <= 0) {
            throw new OrderStatusException('参数错误：order_id必填', 400);
        }

        if (!isset(self::$textMap[$to])) {
            throw new OrderStatusException('参数错误：不支持的目标状态 ' . $to, 400);
        }

        $db = DB::getInstance();

        $db->beginTransaction();

        try {
            // ---------- 1. 锁住订单行 ----------
            // 带 user_id 条件时，查不到即视为「不存在或无权操作」，
            // 统一返回 404 而不是 403，避免通过响应差异探测他人订单号是否存在。
            if ($userId === null) {
                $order = $db->selectOne(
                    'SELECT id, order_no, status FROM orders WHERE id = ? FOR UPDATE',
                    [$orderId]
                );
                $notFoundMsg = '订单不存在';
            } else {
                $order = $db->selectOne(
                    'SELECT id, order_no, status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE',
                    [$orderId, (int) $userId]
                );
                $notFoundMsg = '订单不存在或无权操作';
            }

            if (!$order) {
                $db->rollback();
                throw new OrderStatusException($notFoundMsg, 404);
            }

            $from = self::normalize($order['status']);

            // ---------- 2. 状态机校验 ----------
            if ($from === $to) {
                $db->rollback();
                throw new OrderStatusException('订单已是「' . self::text($to) . '」状态，无需重复操作', 409);
            }

            if (!self::canTransition($from, $to)) {
                $db->rollback();
                throw new OrderStatusException(
                    '订单状态不允许该操作：' . self::text($from) . ' → ' . self::text($to),
                    409
                );
            }

            // ---------- 3. 取消订单：按明细把库存加回去 ----------
            $restoredItems = 0;
            if ($to === self::CANCELED) {
                $items = $db->select(
                    'SELECT dish_id, quantity FROM order_items WHERE order_id = ?',
                    [$orderId]
                );

                foreach ($items as $item) {
                    // 菜品可能已被删除，此时影响行数为 0，属正常情况
                    $db->execute(
                        'UPDATE dishes SET stock = stock + ? WHERE id = ?',
                        [(int) $item['quantity'], $item['dish_id']]
                    );
                }
                $restoredItems = count($items);
            }

            // ---------- 4. CAS 更新：WHERE 带上原状态 ----------
            if ($userId === null) {
                $affected = $db->execute(
                    'UPDATE orders SET status = ? WHERE id = ? AND status = ?',
                    [$to, $orderId, $from]
                );
            } else {
                $affected = $db->execute(
                    'UPDATE orders SET status = ? WHERE id = ? AND user_id = ? AND status = ?',
                    [$to, $orderId, (int) $userId, $from]
                );
            }

            if ($affected <= 0) {
                // 理论上被 FOR UPDATE 挡住不会发生，留作最后一道防线
                $db->rollback();
                throw new OrderStatusException('订单状态已变更，请刷新后重试', 409);
            }

            $db->commit();

            $result = [
                'order_id'    => $orderId,
                'order_no'    => $order['order_no'],
                'status'      => $to,
                'status_text' => self::text($to),
            ];

            if ($to === self::CANCELED) {
                $result['restored_items'] = $restoredItems;
            }

            return $result;

        } catch (OrderStatusException $e) {
            // 业务异常：事务已在抛出前回滚，这里只保证异常继续向上传递
            if ($db->getPdo()->inTransaction()) {
                $db->rollback();
            }
            throw $e;

        } catch (Exception $e) {
            // 数据库等未预期异常：回滚后原样抛出，由接口层兜底为 500
            if ($db->getPdo()->inTransaction()) {
                $db->rollback();
            }
            throw $e;
        }
    }
}

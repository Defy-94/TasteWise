<?php
/**
 * 用户点餐历史查询
 *
 * 为「跟上次一样」与「我的常点」提供数据：
 * 只统计已完成（status = 2）的订单，取消与未支付的不算数。
 * 返回结果都已与在售菜品列表做过匹配，下架/售完的菜不会出现。
 */

class UserOrderHistory
{
    /** 「我的常点」最多返回几道菜 */
    const MAX_REGULAR = 4;

    /**
     * 最近一次已完成订单的菜品
     *
     * @param int $userId
     * @return array ['dishes' => [...], 'order_no' => '', 'ordered_at' => ''] 或空数组
     */
    public static function lastOrder($userId)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return [];
        }

        $db = DB::getInstance();

        $order = $db->selectOne(
            'SELECT id, order_no, created_at FROM orders
             WHERE user_id = ? AND status = ? ORDER BY id DESC LIMIT 1',
            [$userId, OrderStatus::DONE]
        );
        if (!$order) {
            return [];
        }

        $items = $db->select(
            'SELECT dish_id, quantity FROM order_items WHERE order_id = ?',
            [(int) $order['id']]
        );

        $total = count($items);
        $dishes = self::attachToSellable($items);
        if (empty($dishes)) {
            return [];
        }

        return [
            'dishes' => $dishes,
            'order_no' => $order['order_no'],
            'ordered_at' => $order['created_at'],
            'skipped' => max(0, $total - count($dishes)),   // 已下架而没进预览的菜品数
        ];
    }

    /**
     * 常点的菜（按累计点过的份数排序，默认取前 4）
     * 常点不是固定搭配，所以只取前几道交给推荐卡片，由顾客自己挑
     *
     * @param int $userId
     * @param int $limit
     * @return array 菜品列表（含 times 字段表示点过几次）
     */
    public static function regularDishes($userId, $limit = self::MAX_REGULAR)
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return [];
        }

        $db = DB::getInstance();

        $rows = $db->select(
            'SELECT i.dish_id, SUM(i.quantity) AS times
             FROM order_items i
             JOIN orders o ON o.id = i.order_id
             WHERE o.user_id = ? AND o.status = ?
             GROUP BY i.dish_id
             ORDER BY times DESC, i.dish_id DESC
             LIMIT ' . (int) $limit,
            [$userId, OrderStatus::DONE]
        );

        $dishes = self::attachToSellable($rows);
        // 把点过几次写进结果，便于文案里说「点过 3 次」
        foreach ($dishes as &$d) {
            foreach ($rows as $r) {
                if ((int) $r['dish_id'] === (int) $d['id']) {
                    $d['times'] = (int) $r['times'];
                    break;
                }
            }
        }
        unset($d);

        return $dishes;
    }

    /**
     * 把历史里的 dish_id 补成完整菜品信息
     *
     * 只保留仍在售的菜：下架的菜点不了，不能拿来说「给你再来一份」。
     * 「在售」的判定统一走 DishRepo::sellableList()，与 AI 推荐等场景保持一致。
     *
     * @param array $rows 含 dish_id / quantity 的行
     * @return array
     */
    private static function attachToSellable($rows)
    {
        if (empty($rows)) {
            return [];
        }

        $menu = [];
        foreach (DishRepo::sellableList() as $dish) {
            $menu[(int) $dish['id']] = $dish;
        }

        $dishes = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = (int) $row['dish_id'];
            if (!isset($menu[$id]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $dish = $menu[$id];
            $dish['quantity'] = isset($row['quantity']) ? (int) $row['quantity'] : 1;
            $dishes[] = $dish;
        }

        return $dishes;
    }

    /**
     * 把历史拼成一段文本，注入 AI 提示词
     *
     * 让「小味」知道这位顾客上次点了什么、常点什么，回答时能顺着说，
     * 而不是每次都问「你想吃什么」。
     *
     * @param int $userId
     * @return string 空字符串表示没有历史
     */
    public static function promptContext($userId)
    {
        $last = self::lastOrder($userId);
        $regular = self::regularDishes($userId);

        if (empty($last['dishes']) && empty($regular)) {
            return '';
        }

        $lines = ['【这位顾客的点餐历史】'];

        if (!empty($last['dishes'])) {
            $names = [];
            foreach ($last['dishes'] as $d) {
                $names[] = $d['name'] . ' x' . $d['quantity'];
            }
            $lines[] = '上次已完成的一单：' . implode('、', $names);
        }

        if (!empty($regular)) {
            $names = [];
            foreach ($regular as $d) {
                $names[] = $d['name'];
            }
            $lines[] = '常点的菜：' . implode('、', $names);
        }

        $lines[] = '顾客说「跟上次一样」「我的常点」时，直接围绕这些菜回答，不要再问他想吃什么。';

        return implode("\n", $lines);
    }
}

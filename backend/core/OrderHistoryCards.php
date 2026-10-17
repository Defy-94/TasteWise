<?php
/**
 * 点餐历史相关：意图识别 + 确认卡构建
 *
 * 一句话分工：
 *   - 用户问「跟上次一样 / 我的常点」时，后端先按历史算好菜品，
 *     前端据此渲染一张「确认点餐」卡片，一次把整单加进购物车
 *   - AI 只负责说一句自然的话（它已经通过提示词知道了历史），
 *     菜品的真实数据以这里查出来的为准，避免 AI 编造
 */

class OrderHistoryCards
{
    /** 跟上次一样的口语说法 */
    const LAST_KEYWORDS = [
        '跟上次一样', '和上次一样', '上次一样', '跟上次的', '和上次的',
        '再来一份', '再来一单', '再点一次', '照旧', '老样子', '老规矩',
    ];

    /** 常点的口语说法 */
    const REGULAR_KEYWORDS = [
        '我的常点', '常点', '我常点', '经常点', '平常点', '老点',
        '平时点', '常吃', '我常吃', '最常点',
    ];

    /**
     * 判断这次提问属于哪种历史请求
     *
     * @param string $message
     * @return string 'last' | 'regular' | ''
     */
    public static function detectIntent($message)
    {
        if (!is_string($message) || $message === '') {
            return '';
        }

        // 「我的常点」优先判断：它的词更容易被「上次」类关键词误命中
        foreach (self::REGULAR_KEYWORDS as $kw) {
            if (mb_strpos($message, $kw) !== false) {
                return 'regular';
            }
        }

        foreach (self::LAST_KEYWORDS as $kw) {
            if (mb_strpos($message, $kw) !== false) {
                return 'last';
            }
        }

        return '';
    }

    /**
     * 「我的常点」的推荐卡片
     *
     * 常点是一个集合而不是一份固定订单，所以不整单下单，
     * 而是取点单次数前 4 道，交给原来的推荐卡片，让顾客自己挑。
     *
     * @param int $userId
     * @return array 菜品卡片列表（含 ai_reason = 「点过 N 次」）
     */
    public static function regularCards($userId)
    {
        $dishes = UserOrderHistory::regularDishes($userId, 4);

        $cards = [];
        foreach ($dishes as $d) {
            $times = isset($d['times']) ? (int) $d['times'] : 0;
            $d['ai_reason'] = $times > 0 ? ('点过 ' . $times . ' 次') : '';
            $cards[] = $d;
        }

        return $cards;
    }

    /**
     * 构建「确认点餐」卡片的数据
     *
     * @param int    $userId
     * @param string $intent 'last' | 'regular'
     * @return array|null 无历史或菜都已下架时返回 null
     */
    public static function build($userId, $intent)
    {
        // regular 不走整单卡片（见 regularCards），这里只处理「跟上次一样」
        if ($intent === 'last') {
            $last = UserOrderHistory::lastOrder($userId);
            if (empty($last['dishes'])) {
                return null;
            }

            return [
                'source'     => 'last',
                'label'      => '上次的订单',
                'order_no'   => $last['order_no'],
                'ordered_at' => $last['ordered_at'],
                'dishes'     => $last['dishes'],
                'total'      => self::total($last['dishes']),
                'total_text' => number_format(self::total($last['dishes']), 2, '.', ''),
                'count'      => self::count($last['dishes']),
                'skipped'    => isset($last['skipped']) ? (int) $last['skipped'] : 0,
            ];
        }

        return null;
    }

    /**
     * 历史请求的兜底回复
     *
     * AI 也可能已经说了一句，但卡片是否出现由后端决定，
     * 所以这里的文案要与卡片状态一致（没历史就说没历史）。
     */
    public static function fallbackReply($intent, $card)
    {
        if ($card === null) {
            return $intent === 'regular'
                ? '你还没有完成过的订单，所以暂时没有常点的菜。想吃什么可以直接告诉我~'
                : '你还没有完成过的订单，暂时没有「上次」可以参考。想吃什么可以直接告诉我~';
        }

        $names = [];
        foreach ($card['dishes'] as $d) {
            $names[] = $d['name'];
        }
        $list = implode('、', $names);

        if ($card['source'] === 'regular') {
            return '你常点的是 ' . $list . '。要我按这个给你来一份吗？';
        }

        return '你上次点的是 ' . $list . '。要照这个再来一份吗？';
    }

    /** 合计金额 */
    private static function total(array $dishes)
    {
        $sum = 0;
        foreach ($dishes as $d) {
            $sum += (float) $d['price'] * (int) $d['quantity'];
        }
        return round($sum, 2);
    }

    /** 合计份数 */
    private static function count(array $dishes)
    {
        $sum = 0;
        foreach ($dishes as $d) {
            $sum += (int) $d['quantity'];
        }
        return $sum;
    }
}

<?php
/**
 * AI 回复解析工具
 * 
 * ============================================
 * 【职责】
 *   从 AI 的回复文本里解析出「提到了哪些菜品」，用于生成可点击的推荐卡片。
 *   这里只做纯文本处理，不依赖数据库连接，因此可以单独做单元测试。
 * ============================================
 * 
 * ============================================
 * 【提取推荐理由的难点】
 *   AI 的回复格式并不固定，常见有三种：
 *     1) 每道菜一行：        "- 番茄蛋汤：家常美味，清淡不辣"
 *     2) 一行内并列多道菜：  "解渴推荐：冰镇柠檬水（清凉解暑）、酸梅汤（酸甜解腻）、鲜榨西瓜汁（清甜解暑）。"
 *     3) 一句话顺带提到：    "鲜榨西瓜汁，新鲜西瓜现榨，清甜解暑，很适合解渴！"
 * 
 *   如果只按「菜名之后截到句末标点」来取理由，格式 2 就会出问题：
 *   一句话里没有句号也没有换行，第一道菜的理由会把后面几道菜一起吞进来。
 *   因此这里的规则是：**理由的结束位置以「下一道菜名的起点」为界**，
 *   再叠加句末标点截断，最后循环清理首尾的分隔符与括号。
 * ============================================
 */
class AiReply
{
    /** 句末标点（用于截断理由） */
    const SENTENCE_END = '/[\r\n。！!？?；;]/u';

    /** 开头需要清理掉的分隔符/括号 */
    const LEADING_NOISE = '/^[\s:：,，、\-—~～（(【\[]+/u';

    /** 结尾需要清理掉的分隔符/括号 */
    const TRAILING_NOISE = '/[\s:：,，、\-—~～）)】\]]+$/u';

    /** 单条推荐理由的最大字数 */
    const REASON_MAX_LEN = 30;

    /**
     * 从 AI 回复中找出被提到的菜品，并尽力提取一句推荐理由
     * 
     * @param string $reply  AI 回复文本
     * @param array  $dishes 菜单数组（每项需含 name，以及 id/price/image_url 等卡片字段）
     * @param int    $limit  最多返回几道（避免 AI 一口气列十道时卡片刷屏）
     * @return array 菜品数组，每项附加 ai_reason 字段；按在回复中出现的先后排序
     */
    public static function matchDishes($reply, $dishes, $limit = 4)
    {
        if (!is_string($reply) || $reply === '' || !is_array($dishes)) {
            return [];
        }

        // ===== 1. 找出所有被提到的菜品及其位置 =====
        $hits = [];
        foreach ($dishes as $dish) {
            $name = isset($dish['name']) ? (string) $dish['name'] : '';
            if ($name === '') {
                continue;
            }

            $pos = mb_strpos($reply, $name);
            if ($pos === false) {
                continue;   // 回复里没提到这道菜
            }

            $hits[] = [
                'pos'  => $pos,
                'len'  => mb_strlen($name, 'UTF-8'),
                'dish' => $dish,
            ];
        }

        if (empty($hits)) {
            return [];
        }

        // 按出现位置排序，卡片顺序与 AI 描述顺序一致
        usort($hits, function ($a, $b) {
            return $a['pos'] - $b['pos'];
        });

        // 去掉与上一道菜名字区域重叠的命中
        // （防止菜单里存在"A菜"与"AB菜"这类互相包含的名字时解析错乱）
        $clean    = [];
        $lastEnd  = -1;
        foreach ($hits as $hit) {
            if ($hit['pos'] < $lastEnd) {
                continue;
            }
            $clean[] = $hit;
            $lastEnd = $hit['pos'] + $hit['len'];
        }
        $hits = array_slice($clean, 0, max(1, (int) $limit));

        // ===== 2. 逐条提取推荐理由 =====
        $result = [];
        $count  = count($hits);

        foreach ($hits as $i => $hit) {
            $start = $hit['pos'] + $hit['len'];

            // 关键：理由的结束位置是「下一道菜名的起点」，最后一道菜则取到文本末尾。
            //      只截到句末标点是不够的——一行内并列多道菜时会把后面的菜也吞进理由。
            $end = ($i + 1 < $count)
                ? $hits[$i + 1]['pos']
                : mb_strlen($reply, 'UTF-8');

            $tail = mb_substr($reply, $start, $end - $start, 'UTF-8');

            // 再按句末标点截断（如 "（清甜解暑）。想喝哪个？" → "（清甜解暑）"）
            $parts = preg_split(self::SENTENCE_END, $tail);
            $tail  = isset($parts[0]) ? $parts[0] : '';

            // 清理首尾的分隔符与括号：需要循环几次，
            // 因为剥掉外层括号后可能又露出里面的逗号（如 "（清凉解暑）、" → "清凉解暑"）
            for ($k = 0; $k < 3; $k++) {
                $before = $tail;
                $tail   = preg_replace(self::LEADING_NOISE, '', $tail);
                $tail   = preg_replace(self::TRAILING_NOISE, '', $tail);
                if ($tail === $before) {
                    break;
                }
            }

            $tail = trim($tail);

            if (mb_strlen($tail, 'UTF-8') > self::REASON_MAX_LEN) {
                $tail = mb_substr($tail, 0, self::REASON_MAX_LEN, 'UTF-8') . '…';
            }

            $dish = $hit['dish'];
            $dish['ai_reason'] = $tail;
            $result[] = $dish;
        }

        return $result;
    }

    /**
     * 判断用户消息是否明显在要「推荐」
     * 仅作为兜底路径的触发条件（回复里一道菜都没提到时才会用到）
     * 
     * @param string $message 用户消息
     * @return bool
     */
    public static function hasRecommendIntent($message)
    {
        if (!is_string($message) || $message === '') {
            return false;
        }

        $keywords = ['推荐', '建议', '吃什么', '点餐', '好吃', '招牌', '来点', '想要', '有没有'];

        foreach ($keywords as $kw) {
            if (mb_strpos($message, $kw) !== false) {
                return true;
            }
        }

        return false;
    }
}

<?php
/**
 * AI 回复 → 推荐卡片的构建
 *
 * 这段逻辑（取在售菜单 → 从回复文本里识别菜品 → 必要时兜底做一次结构化推荐）
 * 原本在 ai/chat.php 和 ai/chat_stream.php 里各写了一份，是**逐字重复**的，
 */

class AiCards
{
    /** 一次最多给几张卡片（太多会挤满聊天界面，也没人往下看） */
    const MAX_CARDS = 4;

    /**
     * 从 AI 回复文本里构建推荐卡片
     *
     * @param string        $reply        AI 的完整回复
     * @param string        $userMessage  用户原话（判断是否需要兜底推荐）
     * @param DeepSeek|null $deepseek     调用方已有的实例；传 null 则只在真需要兜底时才创建
     *
     * @return array 菜品卡片列表（可能为空）
     */
    public static function fromReply($reply, $userMessage, DeepSeek $deepseek = null)
    {
        // 只拿「上架且有库存」的菜：不能给顾客推荐一道已经卖完或下架的菜
        $menu = DishRepo::sellableList();

        $cards = AiReply::matchDishes($reply, $menu, self::MAX_CARDS);

        if (!empty($cards)) {
            return $cards;
        }

        // 兜底：回复里一道菜都没提到，但用户明显在要推荐。
        // 这会多花一次 AI 调用，所以必须先判断意图。
        if (!AiReply::hasRecommendIntent($userMessage)) {
            return [];
        }

        // DeepSeek 的构造函数会读 API Key 配置，所以能复用调用方的实例就复用，
        // 真需要时再创建
        if ($deepseek === null) {
            $deepseek = new DeepSeek();
        }

        $result = $deepseek->recommend($menu, $userMessage);

        return self::matchRecommendations(
            isset($result['recommendations']) ? $result['recommendations'] : [],
            $menu
        );
    }

    /**
     * 把结构化推荐结果（[{id, reason}]）匹配成完整的菜品卡片
     *
     * AI 只返回菜品ID和理由，其余字段要从菜单里补齐。
     * ID 对不上菜单的一律丢弃 —— 这是防止 AI 编造菜品的关键一道闸。
     *
     * @param array $recommendations DeepSeek::recommend() 返回的 recommendations
     * @param array $menu            可供匹配的菜品列表
     *
     * @return array
     */
    public static function matchRecommendations($recommendations, array $menu)
    {
        if (!is_array($recommendations) || empty($recommendations)) {
            return [];
        }

        $cards = [];

        foreach ($recommendations as $item) {
            if (!is_array($item) || !isset($item['id'])) {
                continue;
            }

            foreach ($menu as $dish) {
                if ($dish['id'] == $item['id']) {
                    $dish['ai_reason'] = isset($item['reason']) ? $item['reason'] : '';
                    $cards[] = $dish;
                    break;
                }
            }
        }

        return $cards;
    }
}

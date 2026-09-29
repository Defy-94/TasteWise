<?php
/**
 * 菜品查询的统一入口
 *
 * ============================================
 * 【为什么要专门建这个类】
 *   在它出现之前，「哪些菜算在售」这条规则散落在 6 个地方：
 *     api/dish/list.php、api/dish/detail.php、api/dish/recommend.php、
 *     api/ai/chat.php、api/ai/chat_stream.php、core/DeepSeek.php
 *   而且已经**出现了不一致**：
 *     - 多数地方写的是「上架」（is_on_sale = 1）
 *     - 只有两处额外加了「有库存」（stock > 0）
 *   于是 AI 推荐接口会把已经售完的菜推给顾客 —— 因为那一处漏了库存条件。
 *   这类「同一条业务规则被抄了多份、然后各自跑偏」的问题很难靠读代码发现，
 *   所以统一到这里。
 * ============================================
 *
 * ============================================
 * 【两种「在售」是不同的规则，不要混用】
 *
 *   1. 顾客可见（condVisible）= 上架
 *      - 用于：菜品列表、菜品详情
 *      - 为什么不排除售完：库存为 0 的菜仍要展示、只是标成「已售完」。
 *        整道菜突然消失会让顾客以为店里没这道菜。
 *
 *   2. 可出售（condSellable）= 上架 且 有库存
 *      - 用于：AI 推荐、AI 对话的菜单注入、AI 回复里的卡片识别
 *      - 为什么排除售完：这些场景是「向顾客推荐」，
 *        推荐一道点不了的菜只会让人白高兴一场。
 * ============================================
 *
 * 【字段清单也统一在这里】避免各处 SELECT 的列不一致，
 * 导致「列表有图、卡片没图」这类莫名其妙的缺字段问题。
 */

class DishRepo
{
    /** 顾客可见菜品的标准列（列表 / 详情 / AI 卡片都用这一套） */
    const COLUMNS = 'id, category_id, name, price, image_url, description, spiciness, is_recommend, stock';

    /**
     * 条件模板：{t} 是表别名的占位符，由 condXxx($alias) 替换
     *
     * 之所以用模板而不是直接写字符串，是因为有些查询要 JOIN
     * （例如取分类名），那时列名必须带别名前缀，否则字段重名会报
     * 「Column 'is_on_sale' in where clause is ambiguous」。
     */
    const T_VISIBLE  = '{t}is_on_sale = 1';
    const T_SELLABLE = '{t}is_on_sale = 1 AND {t}stock > 0';

    /**
     * 规则 1：顾客可见的条件（含已售完）
     * @param string $alias 表别名，不传表示不带前缀
     */
    public static function condVisible($alias = '')
    {
        return str_replace('{t}', self::prefix($alias), self::T_VISIBLE);
    }

    /**
     * 规则 2：可出售的条件（上架 且 有库存）
     * @param string $alias 表别名，不传表示不带前缀
     */
    public static function condSellable($alias = '')
    {
        return str_replace('{t}', self::prefix($alias), self::T_SELLABLE);
    }

    /**
     * 顾客可见的菜品列表（含已售完）
     *
     * @param array $filters 支持：
     *                       - category_id (int)  按分类筛选，0 或不传表示全部
     *                       - keyword (string)   关键词，同时匹配菜名与描述
     *
     * @return array
     */
    public static function visibleList(array $filters = [])
    {
        $db = DB::getInstance();

        $sql    = 'SELECT ' . self::COLUMNS . ' FROM dishes WHERE ' . self::condVisible();
        $params = [];

        $categoryId = isset($filters['category_id']) ? (int) $filters['category_id'] : 0;
        if ($categoryId > 0) {
            $sql .= ' AND category_id = ?';
            $params[] = $categoryId;
        }

        $keyword = isset($filters['keyword']) ? trim((string) $filters['keyword']) : '';

        if ($keyword === '') {
            return $db->select($sql . ' ORDER BY id', $params);
        }

        // 关键词同时匹配菜名与描述：
        //   只搜菜名会漏掉「辣」这类只在描述里出现的词 ——
        //   菜品名里没有「辣」字，但好几道菜的描述是「麻辣鲜香」。
        $like = '%' . DB::likeEscape($keyword) . '%';

        $sql .= ' AND (name LIKE ? OR description LIKE ?)';
        $params[] = $like;
        $params[] = $like;

        // 菜名命中的排前面：搜「汤」时「番茄蛋汤」应该比
        // 「描述里顺带提到汤」的菜更靠前，否则最相关的结果可能藏在后面。
        //
        // ⚠️ 这个占位符出现在 WHERE 之后，所以它的参数必须追加在数组最后 ——
        //    PDO 是按位置绑定参数的，顺序错了会把关键词绑到错误的 ? 上。
        $sql .= ' ORDER BY CASE WHEN name LIKE ? THEN 0 ELSE 1 END, id';
        $params[] = $like;

        return $db->select($sql, $params);
    }

    /**
     * 单个顾客可见菜品，并带上所属分类名
     *
     * 详情页需要分类名，所以在 SQL 里直接 JOIN 出来，
     * 避免调用方为了一个分类名再查一次库。
     *
     * @param int $dishId
     * @return array|false 不存在或已下架时返回 false
     */
    public static function findVisibleWithCategory($dishId)
    {
        $db = DB::getInstance();

        return $db->selectOne(
            'SELECT d.id, d.category_id, d.name, d.price, d.image_url, d.description,
                    d.spiciness, d.is_recommend, d.stock,
                    c.name AS category_name
             FROM dishes d
             LEFT JOIN categories c ON c.id = d.category_id
             WHERE d.id = ? AND ' . self::condVisible('d'),
            [(int) $dishId]
        );
    }

    /**
     * 可出售的菜品（上架 且 有库存）
     *
     * 供 AI 推荐、菜单注入、回复卡片识别使用 —— 这些场景都不该出现「点不了的菜」。
     *
     * @return array
     */
    public static function sellableList()
    {
        $db = DB::getInstance();

        return $db->select(
            'SELECT ' . self::COLUMNS . ' FROM dishes WHERE ' . self::condSellable() . ' ORDER BY category_id, id'
        );
    }

    /**
     * 把别名变成前缀
     * @return string 传 'd' 返回 'd.'，传空串返回 ''
     */
    private static function prefix($alias)
    {
        $alias = trim((string) $alias);
        return $alias === '' ? '' : $alias . '.';
    }
}

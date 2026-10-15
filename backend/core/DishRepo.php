<?php
/**
 * 菜品查询的统一入口
 *
 * 「哪些菜算在售」原本散落在 6 个文件里并且已经出现不一致（多数只判上架，
 * 两处多判了库存），导致 AI 推荐会把售完的菜推给顾客。所以统一到这里。
 *
 * 两种「在售」是不同的规则，不要混用：
 *   1. 顾客可见（condVisible）= 上架 —— 用于列表与详情；
 *      不排除售完：库存为 0 的菜仍要展示，只是标成「已售完」。
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
     * 条件模板：{t} 由 condXxx($alias) 替换成表别名前缀
     * 用模板是因为 JOIN 查询（如取分类名）必须带别名，否则重名字段会报 ambiguous
     */
    const T_VISIBLE  = '{t}is_on_sale = 1';
    const T_SELLABLE = '{t}is_on_sale = 1 AND {t}stock > 0';

    /** 规则 1：顾客可见（含已售完） */
    public static function condVisible($alias = '')
    {
        return str_replace('{t}', self::prefix($alias), self::T_VISIBLE);
    }

    /** 规则 2：可出售（上架且有库存） */
    public static function condSellable($alias = '')
    {
        return str_replace('{t}', self::prefix($alias), self::T_SELLABLE);
    }

    /**
     * 顾客可见的菜品列表（含已售完）
     * @param array $filters category_id（0 或不传为全部）、keyword（匹配菜名与描述）
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

        // 菜名命中排前面（最相关的结果不该藏在后面）。
        // 该占位符在 WHERE 之后，参数必须追加在数组最后：PDO 按位置绑定，顺序错了会绑错。
        $sql .= ' ORDER BY CASE WHEN name LIKE ? THEN 0 ELSE 1 END, id';
        $params[] = $like;

        return $db->select($sql, $params);
    }

    /**
     * 单个顾客可见菜品（带分类名，用 JOIN 取出，避免调用方再查一次）
     * @return array|false 不存在或已下架返回 false
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

    /** 可出售的菜品（上架且有库存）：供 AI 推荐、菜单注入、卡片识别使用 */
    public static function sellableList()
    {
        $db = DB::getInstance();

        return $db->select(
            'SELECT ' . self::COLUMNS . ' FROM dishes WHERE ' . self::condSellable() . ' ORDER BY category_id, id'
        );
    }

    /** 别名转前缀：'d' -> 'd.'，空串 -> '' */
    private static function prefix($alias)
    {
        $alias = trim((string) $alias);
        return $alias === '' ? '' : $alias . '.';
    }
}

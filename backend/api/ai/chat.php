<?php
/**
 * 接口：DeepSeek AI 智能问答（点餐助手对话）
 * 请求地址：POST /api/ai/chat.php
 * 入参JSON：{"message":"推荐几道辣菜","history":[]}
 * 返回：{"code":200,"data":{"reply":"...","recommend_dishes":[{...}],"has_recommend":true}}
 * 
 * ============================================
 * 【推荐卡片（recommend_dishes）是怎么来的】
 * 
 * 先让 AI 正常回答，然后**从 AI 的回复文本里识别它提到的菜品**，把这些菜品
 * 作为可点击卡片返回。相比"靠关键词猜意图"的老做法，好处有三：
 * 
 *   1) 不会漏：用户说「我有点渴」「西瓜汁」这类不含任何关键词的消息，
 *      只要 AI 回复里明确提到了菜品名，就能给出对应卡片；
 *   2) 更准确：卡片内容就是 AI 刚刚说的那几道菜，而不是另一次推荐调用猜出来的；
 *   3) 更省钱：绝大多数情况不需要第二次调用 AI（每次 AI 调用都是成本）。
 * 
 * 只有当「回复里一道菜都没提到、而用户又明显在要推荐」时，
 * 才兜底调用一次结构化推荐（DeepSeek::recommend）。
 * ============================================
 */
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/DeepSeek.php';
require_once __DIR__ . '/../../core/DB.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

$input   = json_decode(file_get_contents('php://input'), true);
$message = isset($input['message']) ? trim($input['message']) : '';
$history = isset($input['history']) ? $input['history'] : [];

if (empty($message)) {
    Response::error('参数错误：message必填');
}

try {
    // ============================================
    // 1. 调用 DeepSeek 获取 AI 回复
    // ============================================
    $deepseek = new DeepSeek();
    $result   = $deepseek->chat($message, $history);

    if (isset($result['error'])) {
        Response::error('AI服务异常：' . $result['error'], 500);
    }

    $reply = isset($result['choices'][0]['message']['content'])
        ? $result['choices'][0]['message']['content']
        : '抱歉，我暂时无法回答这个问题。';

    // ============================================
    // 2. 取出在售菜单，用于识别回复中提到的菜品
    // ============================================
    $db   = DB::getInstance();
    $menu = $db->select(
        'SELECT id, category_id, name, price, image_url, description, spiciness 
         FROM dishes 
         WHERE stock > 0'
    );

    $recommendDishes = matchDishesInReply($reply, $menu, 4);

    // ============================================
    // 3. 兜底：回复里没提到菜品，但用户明显在要推荐
    //    （会多一次 AI 调用，所以只在必要时才走这里）
    // ============================================
    if (empty($recommendDishes) && hasRecommendIntent($message)) {
        $rec = $deepseek->recommend($menu, $message);

        if (!empty($rec['recommendations'])) {
            foreach ($rec['recommendations'] as $item) {
                foreach ($menu as $dish) {
                    if ($dish['id'] == $item['id']) {
                        $dish['ai_reason'] = isset($item['reason']) ? $item['reason'] : '';
                        $recommendDishes[] = $dish;
                        break;
                    }
                }
            }
        }
    }

    // ============================================
    // 4. 返回统一格式
    // ============================================
    Response::success([
        'reply'            => $reply,
        'recommend_dishes' => $recommendDishes,          // 推荐菜品列表（可能为空）
        'has_recommend'    => !empty($recommendDishes),
    ]);

} catch (Exception $e) {
    Response::error('AI请求失败：' . $e->getMessage(), 500);
}

/**
 * 从 AI 回复文本中找出被提到的菜品，并尽量提取一句推荐理由
 * 
 * 例：回复里有 "- 番茄蛋汤：家常美味，清淡不辣"
 *     → 命中菜品「番茄蛋汤」，理由取为「家常美味，清淡不辣」
 * 
 * @param string $reply  AI 回复文本
 * @param array  $dishes 菜单（含 id / name / price / image_url ...）
 * @param int    $limit  最多返回几道（避免 AI 一口气列十道时卡片刷屏）
 * @return array 菜品数组，每项附加 ai_reason 字段；按在回复中出现的先后排序
 */
function matchDishesInReply($reply, $dishes, $limit = 4)
{
    $matched = [];

    foreach ($dishes as $dish) {
        $name = isset($dish['name']) ? $dish['name'] : '';
        if ($name === '') {
            continue;
        }

        $pos = mb_strpos($reply, $name);
        if ($pos === false) {
            continue;   // 回复里没有提到这道菜
        }

        // 取菜品名后面的一小段文字作为推荐理由
        $tail = mb_substr($reply, $pos + mb_strlen($name), 80, 'UTF-8');

        // 截断到第一个换行或句末标点
        $parts = preg_split('/[\r\n。！!；;]/u', $tail);
        $tail  = isset($parts[0]) ? $parts[0] : '';

        // 去掉开头的分隔符与空白（如 "："、"、"、"-"）
        $tail = preg_replace('/^[\s:：,，、\-—~～]+/u', '', $tail);
        $tail = trim(mb_substr($tail, 0, 30, 'UTF-8'));

        $dish['ai_reason'] = $tail;
        $dish['_pos']      = $pos;      // 临时字段：记录出现位置用于排序
        $matched[]         = $dish;
    }

    // 按在回复中出现的先后排序，让卡片顺序与 AI 的描述顺序一致
    usort($matched, function ($a, $b) {
        return $a['_pos'] - $b['_pos'];
    });

    $matched = array_slice($matched, 0, $limit);

    // 清理临时字段
    foreach ($matched as &$item) {
        unset($item['_pos']);
    }
    unset($item);

    return $matched;
}

/**
 * 判断用户消息是否明显在要「推荐」
 * 仅作为兜底路径的触发条件，不再决定是否显示卡片
 * 
 * @param string $message 用户消息
 * @return bool
 */
function hasRecommendIntent($message)
{
    $keywords = ['推荐', '建议', '吃什么', '点餐', '好吃', '招牌', '来点', '想要', '有没有'];

    foreach ($keywords as $kw) {
        if (mb_strpos($message, $kw) !== false) {
            return true;
        }
    }

    return false;
}

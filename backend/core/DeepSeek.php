<?php
/**
 * DeepSeek AI API请求封装类
 * 
 * ============================================
 * 【DeepSeek密钥配置修改位置】
 * 修改 config/apikey.php 中的 deepseek_api_key 值
 * ============================================
 * 
 * ============================================
 * 【AI提示词自定义修改位置】
 * 修改 buildPrompt() 方法中的提示词内容
 * ============================================
 * 
 * 功能说明：
 * 1. 封装 DeepSeek API 的调用逻辑
 * 2. 动态从数据库读取菜品菜单，注入系统提示词
 * 3. 智能推荐菜品，过滤无效ID，防止AI编造
 * 4. 严格限制AI行为，防止幻觉（如声称已操作购物车）
 */
class DeepSeek
{
    // ===== DeepSeek API 配置 =====
    private $apiKey;
    
    // API请求地址
    private $apiUrl = 'https://api.deepseek.com/v1/chat/completions';
    
    // 默认使用的模型名称（可切换为 deepseek-reasoner）
    private $model = 'deepseek-chat';
    
    // 最大返回Tokens
    private $maxTokens = 2000;
    
    // 温度参数（0-1，越低越精准，越高越随机；0.5降低随机性减少幻觉）
    private $temperature = 0.5;

    // ==================================================
    // 对话长度上限
    //
    // 为什么必须有：第 N 轮请求要把前面 N-1 轮的内容全部重发一遍，
    // 所以一次长对话的成本是 O(N²)。不限制的话，一次几十轮的闲聊
    // 就能烧掉大量额度，而且迟早超出模型上下文窗口、请求直接失败。
    //
    // 注意：前端也会截断，但那只是"省流量"，不能当安全边界 ——
    // 任何人都可以绕过小程序直接调接口，所以服务端必须自己再洗一遍。
    // ==================================================

    /** 最多携带多少条历史消息（一问一答算 2 条） */
    const MAX_HISTORY_MESSAGES = 12;

    /** 单条历史消息最多多少字符 */
    const MAX_HISTORY_MSG_CHARS = 1000;

    /** 历史消息合计最多多少字符（成本主要由它决定） */
    const MAX_HISTORY_TOTAL_CHARS = 4000;

    /** 本次用户消息最多多少字符 */
    const MAX_MESSAGE_CHARS = 1000;

    /**
     * 清洗并裁剪客户端传来的历史对话
     *
     * 做三件事：
     *
     * 1. 【安全】拒绝 system 角色
     *    请求体里的消息会被拼在系统提示词**之后**。如果放行客户端传来的
     *    {"role":"system", ...}，就等于让调用者覆盖「小味」的身份与行为约束 ——
     *    典型的提示词注入，能让 AI 说出任何话、还顶着助手的名义返回。
     *    所以只放行 user / assistant 两种角色。
     *
     * 2. 【成本与稳定性】限制条数与长度
     *    见上面 MAX_HISTORY_* 常量的说明。
     *
     * 3. 【健壮性】丢掉结构不合法的项
     *    防止脏数据把请求体搞成模型无法解析的形状。
     *
     * @param mixed $history
     * @return array 干净的 [['role'=>..., 'content'=>...], ...]
     */
    public static function sanitizeHistory($history)
    {
        if (!is_array($history)) {
            return [];
        }

        $clean = [];

        foreach ($history as $item) {
            if (!is_array($item)) {
                continue;
            }

            $role = isset($item['role']) ? $item['role'] : '';

            // 只允许 user / assistant —— system 一律丢弃（见上面的安全说明）
            if ($role !== 'user' && $role !== 'assistant') {
                continue;
            }

            $content = isset($item['content']) && is_string($item['content']) ? $item['content'] : '';
            $content = trim($content);

            if ($content === '') {
                continue;
            }

            if (mb_strlen($content, 'UTF-8') > self::MAX_HISTORY_MSG_CHARS) {
                $content = mb_substr($content, 0, self::MAX_HISTORY_MSG_CHARS, 'UTF-8');
            }

            $clean[] = ['role' => $role, 'content' => $content];
        }

        // 先按条数保留最近的若干条（越靠后越新）
        if (count($clean) > self::MAX_HISTORY_MESSAGES) {
            $clean = array_slice($clean, -self::MAX_HISTORY_MESSAGES);
        }

        // 再按总字符预算从最近往前取，保证整体不超预算。
        // 从后往前是因为「越近的对话越重要」，宁可丢掉最早的。
        $total = 0;
        $kept  = [];

        for ($i = count($clean) - 1; $i >= 0; $i--) {
            $len = mb_strlen($clean[$i]['content'], 'UTF-8');

            if ($total + $len > self::MAX_HISTORY_TOTAL_CHARS) {
                break;
            }

            $total += $len;
            array_unshift($kept, $clean[$i]);
        }

        return $kept;
    }

    /**
     * 裁剪本次用户消息（防御性：接口层已经判过必填，但没人保证它不会是一兆字节）
     */
    private static function clampMessage($message)
    {
        $message = is_string($message) ? trim($message) : '';

        if (mb_strlen($message, 'UTF-8') > self::MAX_MESSAGE_CHARS) {
            $message = mb_substr($message, 0, self::MAX_MESSAGE_CHARS, 'UTF-8');
        }

        return $message;
    }

    /**
     * 构造函数：从配置文件加载API密钥
     */
    public function __construct()
    {
        $config = require __DIR__ . '/../config/apikey.php';
        if (!isset($config['deepseek_api_key'])) {
            throw new \RuntimeException('Missing API key in config');
        }
        $this->apiKey = $config['deepseek_api_key'];
    }

    /**
     * 发送对话请求
     * 
     * @param string $userMessage  用户消息
     * @param array  $history      历史对话(可选) 格式:[['role'=>'user','content'=>'...'],...]
     * @return array               返回AI回复
     */
    public function chat($userMessage, $history = [])
    {
        // 动态获取菜单
        $menuText = $this->getDynamicMenu();
        
        // 构建系统提示词（注入动态菜单）
        $systemPrompt = $this->buildPrompt($menuText);
        
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];
        
        // 合并历史对话（必须先清洗：拒绝 system 角色 + 限制条数与长度）
        $history = self::sanitizeHistory($history);
        if (!empty($history)) {
            $messages = array_merge($messages, $history);
        }
        
        // 添加当前用户消息
        $messages[] = ['role' => 'user', 'content' => self::clampMessage($userMessage)];

        $postData = [
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        return $this->requestApi($postData);
    }

    /**
     * 流式对话：AI 吐一个字就转发一个字
     *
     * ============================================
     * 【为什么值得单独做一条链路】
     *   非流式接口要等 AI 把整段话写完（通常 2-5 秒）才返回，
     *   界面上只能干等。改成流式后第一个字通常在 1 秒内就到，
     *   体感快很多 —— 这也是各家 AI 产品都默认流式的原因。
     * ============================================
     *
     * @param string        $userMessage 用户消息
     * @param array         $history     历史对话
     * @param callable|null $onDelta     每收到一段增量文本就调用一次：function(string $delta): void
     *
     * @return array ['text' => 完整回复] 或 ['error' => '错误说明']
     */
    public function chatStream($userMessage, $history = [], $onDelta = null)
    {
        // 与非流式完全一致的提示词构建：动态菜单 + 同一套身份/行为约束
        $menuText     = $this->getDynamicMenu();
        $systemPrompt = $this->buildPrompt($menuText);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // 与非流式完全一致的清洗规则（拒绝 system 角色 + 限制条数与长度）
        $history = self::sanitizeHistory($history);
        if (!empty($history)) {
            $messages = array_merge($messages, $history);
        }

        $messages[] = ['role' => 'user', 'content' => self::clampMessage($userMessage)];

        return $this->requestApiStream([
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $this->maxTokens,
            'temperature' => $this->temperature,
            'stream'      => true,
        ], $onDelta);
    }

    /**
     * 以流式方式请求 API，逐段回调
     *
     * ============================================
     * 【实现要点】
     *   1. 用 CURLOPT_WRITEFUNCTION 代替 CURLOPT_RETURNTRANSFER：
     *      前者是「数据一到就交给我们」，后者是「攒齐了再给」，
     *      用错任何一个流式都会退化成一次性返回。
     *   2. 写回调必须返回收到的字节数。返回别的值（或什么都不返回）
     *      cURL 会认为写入失败并中断连接 —— 这是最容易踩的坑。
     *   3. TCP 分块边界和 SSE 事件边界完全无关：
     *      一次回调可能拿到半行、也可能拿到好几行，
     *      所以必须自己维护一个行缓冲，只处理完整的行，剩下的留到下次。
     * ============================================
     *
     * @param array         $postData
     * @param callable|null $onDelta
     * @return array ['text' => string] 或 ['error' => string]
     */
    private function requestApiStream($postData, $onDelta = null)
    {
        $fullText = '';     // 已解析出的完整回复
        $lineBuf  = '';     // 行缓冲：跨回调的半个行
        $rawHead  = '';     // 原始响应的开头，仅用于出错时给出可读信息

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->apiUrl,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: text/event-stream',
            ],
            CURLOPT_POSTFIELDS     => json_encode($postData),
            CURLOPT_TIMEOUT        => 60,          // 流式总时长更宽松一些
            CURLOPT_SSL_VERIFYPEER => false,       // 本地调试关闭SSL验证
            CURLOPT_RETURNTRANSFER => false,       // 关键：不要攒齐再返回
            CURLOPT_WRITEFUNCTION  => function ($handle, $chunk) use (&$fullText, &$lineBuf, &$rawHead, $onDelta) {
                if (strlen($rawHead) < 500) {
                    $rawHead .= substr($chunk, 0, 500 - strlen($rawHead));
                }

                $lineBuf .= $chunk;

                // 只处理完整的行；最后可能剩下的半行留在缓冲里等下一块
                while (($pos = strpos($lineBuf, "\n")) !== false) {
                    $line    = rtrim(substr($lineBuf, 0, $pos), "\r");
                    $lineBuf = substr($lineBuf, $pos + 1);

                    // SSE 里只有 data: 开头的行承载内容，其余是注释/事件名/空行
                    if ($line === '' || strpos($line, 'data:') !== 0) {
                        continue;
                    }

                    $payload = trim(substr($line, 5));

                    // DeepSeek 用 [DONE] 表示结束
                    if ($payload === '[DONE]' || $payload === '') {
                        continue;
                    }

                    $json = json_decode($payload, true);
                    if (!is_array($json)) {
                        continue;   // 非法 JSON 直接跳过，不要因为一行坏数据中断整个回复
                    }

                    // 出错时 DeepSeek 会把 error 放在 data 里
                    if (isset($json['error'])) {
                        continue;
                    }

                    $delta = isset($json['choices'][0]['delta']['content'])
                        ? $json['choices'][0]['delta']['content']
                        : '';

                    if ($delta !== '') {
                        $fullText .= $delta;
                        if ($onDelta !== null) {
                            call_user_func($onDelta, $delta);
                        }
                    }
                }

                // 必须返回本次收到的字节数，否则 cURL 判定写入失败并中断
                return strlen($chunk);
            },
        ]);

        $ok        = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $curlError !== '') {
            return ['error' => 'cURL请求失败: ' . $curlError];
        }

        if ($httpCode !== 200) {
            // 非 200 时返回的是普通 JSON（不是 SSE），直接用原始内容提示
            return ['error' => 'DeepSeek 返回 HTTP ' . $httpCode . '：' . mb_substr($rawHead, 0, 300, 'UTF-8')];
        }

        if ($fullText === '') {
            return ['error' => 'DeepSeek 没有返回任何内容'];
        }

        return ['text' => $fullText];
    }

    /**
     * 智能推荐菜品
     * 
     * @param array  $dishes      菜品列表（从数据库查询）
     * @param string $preference  用户偏好描述
     * @return array              推荐结果 {'recommendations': [{id, reason}]}
     */
    public function recommend($dishes, $preference = '')
    {
        $dishesJson = json_encode($dishes, JSON_UNESCAPED_UNICODE);
        
        $systemPrompt = "你是一个专业的点餐推荐助手。根据用户的口味偏好和菜品列表，推荐3道最合适的菜品。返回格式必须是JSON：{\"recommendations\":[{\"id\":菜品ID,\"reason\":\"推荐理由\"}]}。只返回JSON，不要返回任何其他文字。菜品ID必须来自给定的菜品列表，绝对不能编造ID。";
        
        $userMessage = "菜品列表：{$dishesJson}\n用户偏好：{$preference}\n请推荐。";

        $result = $this->requestApi([
            'model'    => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens'  => 800,
            'temperature' => 0.3,   // 推荐场景使用更低温度，结果更精准
        ]);

        // 解析推荐结果
        if (isset($result['choices'][0]['message']['content'])) {
            $content = $result['choices'][0]['message']['content'];
            
            // 提取JSON：截取花括号之间的内容，容错处理
            $jsonStart = strpos($content, '{');
            $jsonEnd = strrpos($content, '}');
            if ($jsonStart !== false && $jsonEnd !== false && $jsonEnd > $jsonStart) {
                $content = substr($content, $jsonStart, $jsonEnd - $jsonStart + 1);
            }
            
            $json = json_decode($content, true);
            if ($json && isset($json['recommendations'])) {
                // 过滤无效ID：只保留在菜品列表中实际存在的ID，防止AI编造
                $validIds = array_column($dishes, 'id');
                $validRecommendations = [];
                foreach ($json['recommendations'] as $rec) {
                    if (isset($rec['id']) && in_array($rec['id'], $validIds)) {
                        $validRecommendations[] = $rec;
                    }
                }
                if (!empty($validRecommendations)) {
                    return ['recommendations' => $validRecommendations];
                }
            }
        }
        
        return ['recommendations' => []];
    }

    /**
     * 动态获取菜单数据
     * 从数据库读取所有在售菜品，按分类格式化文本
     * 
     * @return string 格式化后的菜单文本
     */
    private function getDynamicMenu()
    {
        try {
            // 引入数据库操作类
            $db = DB::getInstance();

            // 查询分类和菜品
            // 只把「上架且还有库存」的菜品喂给 AI，否则 AI 会推荐顾客根本点不到的菜。
            // 这条规则统一定义在 core/DishRepo.php，不要在这里另写一份 ——
            // 之前正因为抄了多份，AI 推荐接口漏掉了库存条件。

            $categories = $db->select('SELECT id, name FROM categories ORDER BY sort_order DESC');
            $dishes     = DishRepo::sellableList();
            
            if (empty($dishes)) {
                return '（菜单暂无数据）';
            }
            
            // 按分类组织菜品
            $menuByCategory = [];
            foreach ($dishes as $dish) {
                $categoryName = '其他';
                foreach ($categories as $cat) {
                    if ($cat['id'] == $dish['category_id']) {
                        $categoryName = $cat['name'];
                        break;
                    }
                }
                $menuByCategory[$categoryName][] = $dish;
            }
            
            // 格式化菜单文本
            $menuText = '## 当前菜单（共' . count($dishes) . '道在售菜品）' . "\n";
            foreach ($menuByCategory as $categoryName => $categoryDishes) {
                $menuText .= '【' . $categoryName . '】' . "\n";
                foreach ($categoryDishes as $dish) {
                    $menuText .= '- ' . $dish['name'] . ' ' . $dish['price'] . '元';
                    if ($dish['spiciness'] > 0) {
                        $menuText .= ' 辣度' . $dish['spiciness'];
                    } else {
                        $menuText .= ' 不辣';
                    }
                    if (!empty($dish['description'])) {
                        $menuText .= '（' . mb_substr($dish['description'], 0, 20, 'UTF-8') . '）';
                    }
                    $menuText .= "\n";
                }
            }
            
            return $menuText;
            
        } catch (Exception $e) {
            // 数据库读取失败时的兜底处理
            return '（菜单数据加载失败，请稍后重试）';
        }
    }

    /**
     * 构建系统提示词
     * ============================================
     * 【提示词修改入口】在此处自定义AI的系统提示词
     * ============================================
     * 
     * 设计要点：
     * 1. 明确身份定位：仅提供信息咨询，无操作权限
     * 2. 严格行为限制：禁止声称已加入购物车/已下单等
     * 3. 禁止编造菜品：只能推荐动态菜单中存在的菜品
     * 4. 规定回复规范：简短、不重复价格、不假装执行操作
     * 
     * @param string $menuText 动态菜单文本
     * @return string 完整的系统提示词
     */
    private function buildPrompt($menuText)
    {
        $prompt = '你是「智味」餐厅的AI点餐助手，你的名字叫「小味」。' . "\n\n";
        
        $prompt .= '## 你的身份定位' . "\n";
        $prompt .= '你是一个只能提供信息咨询的AI助手，你没有任何操作权限。' . "\n\n";
        
        $prompt .= '## 你能做的事情' . "\n";
        $prompt .= '1. 根据用户口味偏好推荐菜品（只能说菜品名称和推荐理由，系统会自动展示可点击的菜品卡片）' . "\n";
        $prompt .= '2. 回答关于菜品的咨询（食材、口味、辣度、价格等）' . "\n";
        $prompt .= '3. 提供简单的饮食搭配建议' . "\n\n";
        
        $prompt .= '## 你的严格限制（绝对不能违反）' . "\n";
        $prompt .= '1. 你绝对不能声称自己已加入购物车、已下单、已修改信息等任何操作性描述' . "\n";
        $prompt .= '2. 你绝对不能编造菜单上不存在的菜品' . "\n";
        $prompt .= '3. 你绝对不能编造菜品价格、口味、食材信息' . "\n";
        $prompt .= '4. 你没有操作购物车、订单、用户数据的能力' . "\n";
        $prompt .= '5. 如果用户说帮我加入购物车、帮我下单等操作请求，必须回复：抱歉，我无法直接操作购物车，请您点击下方菜品卡片上的加入按钮完成操作' . "\n";
        $prompt .= '6. 如果用户提到一个菜单中没有的菜品，必须回复：抱歉，我们菜单上没有这道菜。您可以看看我们现有的菜品，或者告诉我您想吃什么口味，我帮您推荐' . "\n\n";
        
        $prompt .= '## 以下是完整的动态菜单（只能推荐这些菜品）' . "\n";
        $prompt .= $menuText . "\n\n";
        
        $prompt .= '## 回复规范' . "\n";
        $prompt .= '1. 回复要简短自然，最多推荐 3 道菜' . "\n";
        $prompt .= '2. 推荐菜品时，每道菜单独一行，写成「- 菜名：一句推荐理由」的格式' . "\n";
        $prompt .= '   例如：「- 宫保鸡丁：微辣下饭」。不要写价格，系统会自动在下方展示可点击的菜品卡片' . "\n";
        $prompt .= '3. 永远不要假装执行了任何操作' . "\n";
        $prompt .= '4. 永远只推荐上面菜单中列出的菜品';

        return $prompt;
    }

    /**
     * 实际发送API请求
     * 
     * @param array $postData  请求体数据
     * @return array           响应数据（含error字段表示请求失败）
     */
    private function requestApi($postData)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($postData),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,   // 本地调试关闭SSL验证
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        // cURL错误处理
        if ($error) {
            return ['error' => 'cURL请求失败: ' . $error];
        }

        // HTTP状态码检查
        if ($httpCode !== 200) {
            return ['error' => 'API返回HTTP ' . $httpCode, 'body' => $response];
        }

        return json_decode($response, true);
    }
}
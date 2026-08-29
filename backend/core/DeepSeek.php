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
        
        // 合并历史对话
        if (!empty($history)) {
            $messages = array_merge($messages, $history);
        }
        
        // 添加当前用户消息
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $postData = [
            'model'       => $this->model,
            'messages'    => $messages,
            'max_tokens'  => $this->maxTokens,
            'temperature' => $this->temperature,
        ];

        return $this->requestApi($postData);
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
            require_once __DIR__ . '/DB.php';
            $db = DB::getInstance();
            
            // 查询分类和菜品
            $categories = $db->select('SELECT id, name FROM categories ORDER BY sort_order DESC');
            $dishes = $db->select(
                'SELECT d.id, d.category_id, d.name, d.price, d.description, d.spiciness 
                 FROM dishes d 
                 WHERE d.stock > 0 
                 ORDER BY d.category_id, d.id'
            );
            
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
        $prompt .= '1. 回复必须简短，不超过80个字' . "\n";
        $prompt .= '2. 推荐菜品时只说名称+一句推荐理由，不要重复价格（卡片上会显示）' . "\n";
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
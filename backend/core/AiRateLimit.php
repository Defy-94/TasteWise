<?php
/**
 * AI 接口限流
 *
 * ============================================
 * 【为什么要做这件事】
 *   ai/chat.php 与 dish/recommend.php 每次调用都会真实消耗 DeepSeek 的额度。
 *   这两个接口原本是免登录的、也没有任何次数限制 —— 意味着任何人写个循环
 *   就能把 API Key 的余额刷光，属于「成本失控」而不是「数据泄露」类风险，
 *   但后果同样严重（账单）。
 * ============================================
 *
 * ============================================
 * 【限流模型】固定窗口计数，三层同时生效
 *
 *   1. 身份 · 每分钟   —— 挡住瞬时连点与脚本高速轰炸
 *   2. 身份 · 每天     —— 挡住「细水长流」式的持续消耗
 *   3. 全站 · 每天     —— 最后一道保险：无论怎么绕过前面两层，
 *                        当天的总消耗都不会超过这个数
 *
 *   「身份」的判定：带了有效登录令牌就按用户ID，否则按 IP。
 *   （这两个接口本身免登录，所以游客也会被按 IP 限流。）
 * ============================================
 *
 * ============================================
 * 【窗口怎么算】以 Unix 时间戳对齐的固定窗口
 *   - 分钟窗口：window_start = now - now % 60
 *   - 天窗口  ：window_start = now - now % 86400
 *   刻意不依赖服务器时区（不用「本地零点」），这样行为完全可预测、也便于测试。
 *   代价是「天配额」在北京时间早上 8 点重置（UTC 零点）。
 * ============================================
 *
 * ============================================
 * 【为什么先加计数再判断，而不是先判断再加】
 *   被拒绝的请求也必须计数 —— 否则攻击者可以无限重试，限流形同虚设。
 *   所以顺序是「原子自增 → 读回 → 超了才拒绝」。
 *   并发下读到的值可能已经包含别人的自增结果（只是让限制更严一点），
 *   方向是安全的，不会漏放。
 * ============================================
 *
 * 【想改限额】直接改下面的常量即可，不需要动数据库。
 */

/**
 * 限流异常：携带建议的重试等待秒数，供接口层返回 429 与 Retry-After
 */
class AiRateLimitException extends Exception
{
    /** @var int 建议等待秒数 */
    private $retryAfter;

    public function __construct($message, $retryAfter)
    {
        parent::__construct($message);
        $this->retryAfter = max(1, (int) $retryAfter);
    }

    /** @return int */
    public function getRetryAfter()
    {
        return $this->retryAfter;
    }
}

class AiRateLimit
{
    // ==================================================
    // 限额配置（单位：次）
    // ==================================================

    /** 已登录用户：每分钟最多调用次数 */
    const USER_PER_MINUTE = 6;

    /** 已登录用户：每天最多调用次数 */
    const USER_PER_DAY = 100;

    /** 未登录（按 IP）：每分钟最多调用次数。
     *  比登录用户更严 —— 一个 IP 背后可能是很多人（校园网/公司出口），
     *  而且游客没有账号成本，更容易被拿来刷。 */
    const ANON_PER_MINUTE = 3;

    /** 未登录（按 IP）：每天最多调用次数 */
    const ANON_PER_DAY = 30;

    /** 全站每天总调用上限（最后一道费用保险） */
    const GLOBAL_PER_DAY = 3000;

    // ==================================================
    // 过期数据清理
    // ==================================================

    /** 清理概率分母：平均每 50 次请求触发一次清理 */
    const GC_DIVISOR = 50;

    /** 保留多久的计数记录（秒），默认 2 天 */
    const GC_KEEP_SECONDS = 172800;

    /**
     * 检查并消费一次配额
     *
     * 通过时返回剩余额度信息，同时会设置若干响应头供客户端参考；
     * 超限时抛 AiRateLimitException（调用方应当返回 429）。
     *
     * @return array ['kind'=>'user|anon','minute_left'=>int,'day_left'=>int]
     * @throws AiRateLimitException
     */
    public static function enforce()
    {
        $db       = DB::getInstance();
        $identity = self::identity();
        $now      = time();

        $isUser = $identity['kind'] === 'user';

        // 三项检查：身份×分钟、身份×天、全站×天
        $checks = [
            [
                'key'      => $identity['key'] . ':1m',
                'window'   => $now - ($now % 60),
                'length'   => 60,
                'limit'    => $isUser ? self::USER_PER_MINUTE : self::ANON_PER_MINUTE,
                'too_many' => '请求太频繁了，请稍等一会儿再问',
            ],
            [
                'key'      => $identity['key'] . ':1d',
                'window'   => $now - ($now % 86400),
                'length'   => 86400,
                'limit'    => $isUser ? self::USER_PER_DAY : self::ANON_PER_DAY,
                'too_many' => '今天的 AI 使用次数已用完，请明天再来',
            ],
            [
                'key'      => 'global:1d',
                'window'   => $now - ($now % 86400),
                'length'   => 86400,
                'limit'    => self::GLOBAL_PER_DAY,
                'too_many' => '今日 AI 服务总调用量已达上限，请稍后再试',
            ],
        ];

        $result = [
            'kind'        => $identity['kind'],
            'minute_left' => 0,
            'day_left'    => 0,
        ];

        foreach ($checks as $index => $check) {
            $hits = self::hit($db, $check['key'], $check['window']);

            if ($hits > $check['limit']) {
                $retryAfter = $check['window'] + $check['length'] - $now;

                // 把「还剩多久」也写进响应头，前端可以据此做倒计时提示
                self::sendHeader('Retry-After: ' . max(1, $retryAfter));

                throw new AiRateLimitException($check['too_many'], $retryAfter);
            }

            if ($index === 0) {
                $result['minute_left'] = $check['limit'] - $hits;
            } elseif ($index === 1) {
                $result['day_left'] = $check['limit'] - $hits;
            }
        }

        // 成功路径的响应头：客户端可以据此提前提醒用户，而不是撞到 429 才知道
        self::sendHeader('X-RateLimit-Limit-Minute: ' . ($isUser ? self::USER_PER_MINUTE : self::ANON_PER_MINUTE));
        self::sendHeader('X-RateLimit-Remaining-Minute: ' . $result['minute_left']);
        self::sendHeader('X-RateLimit-Limit-Day: ' . ($isUser ? self::USER_PER_DAY : self::ANON_PER_DAY));
        self::sendHeader('X-RateLimit-Remaining-Day: ' . $result['day_left']);

        // 顺手机率性清理过期计数，避免这张表无限增长
        self::maybeGc($db, $now);

        return $result;
    }

    /**
     * 输出 429 并终止请求（供接口层在 catch 里调用）
     *
     * 单独抽出来是为了让三个接口的 catch 分支都只有一行，不会各写各的。
     */
    public static function rejectResponse(AiRateLimitException $e)
    {

        self::sendHeader('Retry-After: ' . $e->getRetryAfter());

        Response::error($e->getMessage(), 429);
    }

    /**
     * 安全地发送响应头
     *
     * 加 headers_sent() 判断有两个作用：
     *   1. 命令行下（测试脚本直接调用 enforce()）header() 会刷一堆
     *      "Cannot modify header information" 警告，把这个函数一包就干净了；
     *   2. 万一将来有人在已经输出内容之后才调用限流，也不会再产生警告噪音。
     */
    private static function sendHeader($line)
    {
        if (!headers_sent()) {
            header($line);
        }
    }

    /**
     * 原子自增并读回当前窗口的计数
     *
     * 用 INSERT ... ON DUPLICATE KEY UPDATE 一条语句完成「不存在就插入、存在就 +1」，
     * 靠 (bucket_key, window_start) 的唯一索引保证并发安全 ——
     * 换成「先 SELECT 判断再 UPDATE」在并发下会漏计数。
     *
     * @return int 自增后的计数
     */
    private static function hit($db, $key, $windowStart)
    {
        $db->execute(
            'INSERT INTO ai_rate_limit (bucket_key, window_start, hits)
             VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$key, $windowStart]
        );

        $row = $db->selectOne(
            'SELECT hits FROM ai_rate_limit WHERE bucket_key = ? AND window_start = ?',
            [$key, $windowStart]
        );

        return $row ? (int) $row['hits'] : 1;
    }

    /**
     * 判定请求身份
     *
     * 带有效令牌 → 按用户；否则按 IP。
     * 这两个 AI 接口是免登录的，所以这里用 currentUser()（允许校验失败）而不是
     * requireLogin() —— 游客也应该能用 AI，只是额度更低。
     *
     * @return array ['kind'=>'user|anon','key'=>string,'uid'=>int]
     */
    private static function identity()
    {

        $payload = Auth::currentUser();

        if ($payload && !empty($payload['uid'])) {
            return [
                'kind' => 'user',
                'key'  => 'uid:' . (int) $payload['uid'],
                'uid'  => (int) $payload['uid'],
            ];
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) && $_SERVER['REMOTE_ADDR'] !== ''
            ? $_SERVER['REMOTE_ADDR']
            : 'unknown';

        return [
            'kind' => 'anon',
            'key'  => 'ip:' . $ip,
            'uid'  => 0,
        ];
    }

    /**
     * 概率性清理过期计数
     *
     * 不做成定时任务，是因为这个项目跑在 phpstudy 上，没有 cron 的保证；
     * 用「平均每 N 次请求清一次」的方式最省事，而且清理失败绝不能影响主流程。
     */
    private static function maybeGc($db, $now)
    {
        if (mt_rand(1, self::GC_DIVISOR) !== 1) {
            return;
        }

        try {
            $db->execute(
                'DELETE FROM ai_rate_limit WHERE window_start < ?',
                [$now - self::GC_KEEP_SECONDS]
            );
        } catch (Exception $e) {
            // 清理是「顺手做的好事」，失败就算了，不要因此让用户的请求失败
        }
    }
}

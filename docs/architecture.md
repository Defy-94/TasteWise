# 系统架构

## 1. 总体架构

```
┌─────────────────────────────┐
│     微信小程序前端 (frontend) │
│   WXML + WXSS + TypeScript  │
│  pages/ · utils/ · images/  │
└─────────────┬───────────────┘
              │ HTTPS / wx.request（令牌鉴权）
              ▼
┌─────────────────────────────┐        ┌───────────────────────────┐
│       PHP 后端 (backend)     │        │  管理后台 (backend/admin)  │
│   api/  →  core/  →  config/│◄───────│  浏览器访问 · PHP 服务端渲染 │
│  Apache + PHP 7.3+          │ 会话登录 │  订单管理 · 菜品管理       │
└─────────────┬───────────────┘        └───────────────────────────┘
              │                                 同一套 core/（DB、OrderStatus）
      ┌───────┴────────┐
      ▼                ▼
┌──────────┐   ┌──────────────┐
│  MySQL   │   │ DeepSeek API │
│ 5.7+     │   │ (AI 大模型)   │
└──────────┘   └──────────────┘
```

- **前端**：微信小程序原生框架，无第三方 UI 库；通过 `utils/api.ts` 统一封装 `wx.request`。
- **后端**：原生 PHP，无框架、无 Composer 依赖，适合 phpstudy 等本地环境直接运行。
- **管理后台**：同样用原生 PHP 服务端渲染（**不引入 Vue/React 构建链**），复用 `core/DB.php` 与
  `core/OrderStatus.php`。它和小程序是**两套独立的鉴权**：小程序用 HMAC 令牌，后台用会话登录。
- **数据库**：MySQL，通过 `core/DB.php`（PDO 单例）统一访问，所有 SQL 均使用预处理语句防注入。
- **AI**：DeepSeek 官方 API（`https://api.deepseek.com/v1/chat/completions`），由 `core/DeepSeek.php` 封装。

## 2. 模块划分

### 前端 `frontend/`

| 模块 | 路径 | 职责 |
| --- | --- | --- |
| 全局入口 | `app.ts` / `app.json` / `app.wxss` | 应用生命周期、TabBar、全局样式 |
| 首页 | `pages/index/` | 分类导航、菜品列表、AI 推荐弹窗、加入购物车、点卡片进详情 |
| 菜品详情 | `pages/dishDetail/` | 大图、完整介绍、辣度与已售份数、可调份数加购、售完/下架状态 |
| 购物车 | `pages/cart/` | 购物车展示、删除、结算下单 |
| AI 助手 | `pages/aiChat/` | 对话界面、AI 推荐菜品卡片、快捷加购、点卡片进详情 |
| 用户中心 | `pages/userCenter/` | 用户信息展示与编辑、订单入口 |
| 我的订单 | `pages/myOrders/` | 订单列表、支付/取消/完成（状态流转）、状态色区分 |
| 登录 | `pages/login/` | 微信一键登录（登录即注册，已无独立注册页） |
| 关于 | `pages/about/` | 项目介绍 |
| 工具层 | `utils/api.ts` `utils/types.ts` `utils/cartBadge.ts` | 请求封装（令牌注入 + 401 跳登录）、TS 类型定义、购物车角标同步 |

### 后端 `backend/`

| 模块 | 路径 | 职责 |
| --- | --- | --- |
| 接口层 | `api/` | 按业务域拆分：category / dish / cart / order / user / ai |
| 管理后台 | `admin/` | 浏览器访问的运营后台（会话登录 + CSRF），详见第 9 节 |
| 核心层 | `core/DB.php` | PDO 单例，select / insert / execute / 事务 |
| 核心层 | `core/Response.php` | 统一 JSON 输出与 CORS 处理 |
| 核心层 | `core/Auth.php` | 登录令牌签发（`issue`）、校验（`verify`）、鉴权入口（`requireLogin`） |
| 核心层 | `core/OrderStatus.php` | ⭐ 订单状态机唯一实现（接口层与后台共用），含取消回滚库存 |
| 核心层 | `core/WeChat.php` | 微信 `code2Session` 封装（换取 openid） |
| 核心层 | `core/DeepSeek.php` | AI 对话、菜品推荐、动态菜单注入、提示词管理 |
| 核心层 | `core/AiReply.php` | 从 AI 回复文本中识别菜品名（生成推荐卡片），可单元测试 |
| 配置层 | `config/` | 数据库连接、DeepSeek Key、令牌签名密钥、微信 AppSecret、后台账号（⚠️ 敏感，不提交） |
| 数据库脚本 | `sql/init.sql` | 建库建表 + 测试数据 |

## 3. 请求流程（以「AI 点餐助手对话」为例）

1. 顾客在小程序里说「推荐几道辣菜」→ 前端 `POST /api/ai/chat_stream.php`（SSE 流式），
   基础库不支持分块接收时回退到 `POST /api/ai/chat.php`
2. 后端先做三层限流（`core/AiRateLimit.php`），再清洗 `history`（只放行 user/assistant 角色）
3. `core/DeepSeek.php` 把**当日真实菜单**注入提示词（防止 AI 编造菜品），请求大模型流式返回
4. 边收边转发给前端（逐字显示）；回复结束后 `core/AiCards.php`
   从文本中识别出菜品，必要时兜底调用 `DeepSeek::recommend()` 拿到结构化推荐
5. 返回 `{"reply": "...", "recommend_dishes": [...]}` → 对话页把小味切成
   「开心」表情，并把推荐菜品渲染成可点击的卡片

## 4. AI 集成设计（核心亮点）

`core/DeepSeek.php` 的设计要点：

- **动态菜单注入**：每次对话前从数据库实时读取在售菜品，格式化后注入系统提示词，AI 只能基于真实菜单推荐。
- **身份与行为约束**：提示词明确 AI（「小味」）只能提供信息咨询，禁止声称已执行加购/下单等操作，从源头防止幻觉。
- **结构化推荐**：`recommend()` 要求模型输出严格 JSON，并做容错解析（截取花括号段）+ ID 合法性过滤。
- **参数调优**：对话 `temperature=0.5`、推荐 `temperature=0.3`，兼顾创意与准确性；支持切换 `deepseek-reasoner` 模型。

> 修改提示词位置：`core/DeepSeek.php` 的 `buildPrompt()` 方法。
> 修改 API Key 位置：`config/apikey.php`（本地文件，不提交 Git）。

## 5. 订单状态流转

订单状态由 `orders.status` 表示，状态机如下：

```
                  pay.php                complete.php
   [0 待支付] ───────────► [1 已支付] ─────────────► [2 已完成]
        │                        │
        └──────── cancel.php ────┘
                     ▼
              [3 已取消]（自动恢复库存）
```

**这套状态机只有一个实现：`core/OrderStatus.php` 的 `transition($orderId, $to, $userId)`。**

`order/pay.php`、`order/complete.php`、`order/cancel.php` 都只是薄封装（鉴权 → 取参 → 调用 → 出参），
管理后台改订单状态走的也是同一个方法。这样做的原因很实际：

> 「取消订单要把库存按明细加回去」这段逻辑如果在小程序接口和后台各写一份，
> 迟早会出现一边改了一边没改的情况 —— 典型后果就是**后台取消订单没有还库存**，
> 账面上卖出去的菜比实际少，库存和营业额都对不上，而且很难查。

第三个参数 `$userId` 是这套实现的关键开关：

| 调用方 | 传参 | 效果 |
| --- | --- | --- |
| 小程序接口 | `$userId` = 令牌里的用户ID | SQL 的 WHERE 额外限定 `user_id`，**只能操作本人订单**（他人订单返回 404） |
| 管理后台 | `null` | 不限定归属，可以操作任何用户的订单 |

设计要点：

- **前置状态校验**：先判断当前状态是否允许该操作，不允许则抛 `OrderStatusException`（HTTP 409）。
  已完成/已取消是终态，不再流转 —— 否则取消会重复触发库存回滚。
- **原子更新（CAS）**：`UPDATE orders SET status = ? WHERE id = ? AND status = ?`，
  把「原状态」写进 WHERE 条件，并发重复请求只有一次生效。
- **事务 + 行锁**：整个流转在事务内完成，先 `SELECT ... FOR UPDATE` 锁定订单行，
  再逐项恢复库存，避免并发取消导致库存被重复加回。
- **兼容脏数据**：`normalize()` 会把 varchar 状态统一转成 int，非数字值按「待支付」处理，
  避免历史脏数据把订单卡在既不能支付也不能取消的状态。

## 6. 鉴权设计（WeChat.php + Auth.php）

**登录流程（微信一键登录，登录即注册）**：

```
小程序 wx.login() ──code──► POST /api/user/login.php
                              │
                              ├─ core/WeChat.php 调 code2Session 换 openid
                              ├─ 按 openid 查用户，不存在则自动创建
                              └─ core/Auth.php 签发令牌 ──token──► 后续请求头携带
```

- **openid 只信微信**：`core/WeChat.php` 封装 `code2Session`，openid 由微信服务器签发，客户端无法伪造。
  若直接信任客户端传来的 openid，等于「任何人知道别人的 openid 就能登录成对方账号」。
- **登录即注册**：首次登录自动建号，因此不存在单独的注册流程 ——
  小程序里只有一个登录入口（微信一键登录）。
- **令牌格式**：`base64url(payload) . base64url(HMAC-SHA256(payload, secret))`，
  payload 含 `uid / openid / iat / exp`，有效期 7 天。
- **无状态**：服务端不存储会话，校验只需重算签名；修改 `config/security.php` 的
  `token_secret` 即可让所有令牌立即失效。
- **防篡改**：使用 `hash_equals` 做恒定时间比较，签名不符或 payload 被改（如把 uid 改成别人）都会校验失败。
- **身份唯一来源**：所有业务接口通过 `Auth::requireLogin()` 取用户ID，
  **完全忽略客户端传入的 `user_id`**，这是修复越权漏洞（IDOR）的核心。
- **资源归属二次校验**：对具体资源（购物车记录、订单）的操作，
  SQL 的 WHERE 同时限定资源ID与用户ID，删除/修改他人数据会返回 404。
- **本地调试通道**：未配置 AppSecret 时，可同时满足「`allow_dev_openid_login = true`」+
  「请求来自本机回环地址」两个条件，用调试账号登录（默认关闭，线上必须为 false）。

## 7. 并发与数据一致性

订单、库存这类「读 → 改 → 写」操作都存在竞态风险，本项目用两种手段处理：

| 场景 | 做法 | 为什么这样做 |
| --- | --- | --- |
| **下单扣库存（防超卖）** | `UPDATE dishes SET stock = stock - ? WHERE id = ? AND stock >= ?`，影响行数为 0 即回滚并返回 `409` | 把「库存充足」写进 UPDATE 的 WHERE，检查与扣减合成**一条原子语句**，由 InnoDB 行锁保证同一行的扣减串行执行。老实现是「先 SELECT 查库存 → 再无条件扣减」，并发下两个请求都能通过检查，库存会被扣成负数（实测复现过：库存 1、并发两单 → 两单成交、库存 -1） |
| **同一用户重复提交结算** | 事务内 `SELECT id FROM cart WHERE user_id = ? FOR UPDATE` 锁住购物车行 | 第二个请求会等锁释放后读到已清空的购物车，因此只会生成一笔订单。**只锁 cart 表、不锁 dishes**，避免不同用户因菜品加锁顺序不同而死锁 |
| **订单状态流转** | `UPDATE orders SET status = ? WHERE id = ? AND user_id = ? AND status = ?` | 把当前状态写进 WHERE，避免并发重复支付/重复完成 |
| **取消订单回滚库存** | 事务 + `SELECT ... FOR UPDATE` 锁定订单行，再逐项恢复库存 | 防止并发取消把库存重复加回 |
| **购物车改数量** | 注意 MySQL 的 `UPDATE` 在「新值与旧值相同」时受影响行数也是 0，需再查一次以区分「记录不存在」与「值没变」 | 否则「把数量设成原值」会误报 404 |

> **通用原则**：不要「先查再改」，而是**把判断条件写进 UPDATE 的 WHERE 里**（CAS 思路），
> 让数据库来保证原子性；确实需要跨多条语句时，用事务 + `FOR UPDATE` 行锁。

## 8. 安全设计

- 所有数据库操作使用 PDO 预处理语句（`PDO::ATTR_EMULATE_PREPARES => false`），防 SQL 注入。
- 统一 JSON 输出，禁止输出原生错误堆栈。
- 敏感配置（API Key、数据库密码、令牌密钥、管理员账号）位于 `config/`，通过 `.gitignore` 防止上传泄露。
- 用户身份只取自令牌；对具体资源的操作在 SQL 层同时限定资源ID与用户ID（详见第 6 节）。
- 后台页面输出全部经过 `e()`（`htmlspecialchars` + `ENT_QUOTES` + UTF-8）转义，
  防存储型 XSS —— 菜品名、订单备注都是用户可控内容。
- ⚠️ 上线前仍需处理：
  1. 修改管理后台密码，并考虑限制 `/admin/` 的访问来源；
  2. `CURLOPT_SSL_VERIFYPEER` 置回 `true`；
  3. CORS `Access-Control-Allow-Origin: *` 收紧为具体域名；
  4. 订单支付接入微信支付（当前为模拟支付）；
  5. 接口限流（防止 AI 接口被刷导致费用失控）；
  6. 全站 HTTPS。

## 9. 管理后台设计（backend/admin/）

### 为什么是 PHP 服务端渲染

本项目后端没有任何框架和构建链，后台也保持同一风格：浏览器直接打开 `http://localhost/admin/` 就能用，
不需要 `npm install`、不需要打包、不需要单独部署前端静态资源。

> 换一种做法是写 Vue/React 单页应用，界面会更现代，但代价是为一个内部运营工具引入
> 完整的 node 构建链和一套额外的部署步骤。对一个原生 PHP + 小程序的项目来说，
> 这个成本明显不划算，而且会让「clone 下来就能跑」这件事失效。

### 目录结构

```
backend/admin/
├── login.php / logout.php     # 登录、退出（退出仅接受 POST）
├── index.php                  # 订单管理（默认页）
├── order.php                  # 订单详情 + 状态流转
├── dishes.php                 # 菜品管理（上下架 / 改库存 / 删除）
├── dish_edit.php              # 新增 / 编辑菜品
├── account.php                # 账号与安全（只读：凭据位置 + 改密码命令）
├── assets/admin.css           # 后台样式（手写，无 UI 框架）
└── lib/
    ├── bootstrap.php          # 统一引导：先 define('TW_ADMIN') 再引入本目录
    ├── AdminAuth.php          # 会话认证 + CSRF + 登录失败锁定
    ├── helpers.php            # 转义 e()、flash 提示、取参、格式化
    ├── layout.php             # 页面骨架（顶部导航 / 内容区 / 页脚 / 分页）
    └── order_actions.php      # 把 OrderStatus 的异常转成给管理员看的一句话

backend/tools/                 # 命令行工具（不在浏览器里用，URL 直访被拒）
└── admin_passwd.php           # 改管理员密码：交互式输入 → 写回配置 → 回读校验
```

### 改密码为什么做成了命令行脚本 + 双击入口

最初文档里写的是一条 `php -r "echo password_hash(...)"` 命令，但那条命令把**某台机器的
phpstudy 安装路径写死了**，换台电脑就失效。改成仓库里的脚本之后：

| 好处 | 说明 |
| --- | --- |
| 可移植 | 只要能用「你机器上的 PHP」执行它就行 —— 而 PHP 本来就是跑这个项目的前提 |
| 少踩坑 | 不用手抄 60 位哈希（抄错一位就登不上），也不会把含 `$` 的哈希丢进双引号里被当成变量 |
| 可校验 | 写完会重新读回文件、`password_verify` 验证一遍，并跑 `php -l` 确认配置文件仍是合法 PHP |
| 有退路 | 改之前自动备份成 `admin.php.bak-时间戳`（同样被 `.gitignore` 忽略） |
| 不含糊 | 顺手把配置文件顶部的「本地凭据备忘」一起改对 —— 否则会出现「文件里写着旧密码、登录却要用新密码」，比没有备忘更误导人 |

「可移植」还有一层意思：让使用者去记「怎么在我这台机器上执行 PHP」本身就是负担。
所以根目录再放一个 **`修改后台密码.bat`**，双击即可 ——
它按「PATH → phpstudy → xampp/wamp → 手工填一次路径」的顺序自己找 PHP。

### 管理员账号为什么放在配置文件里，而不是数据库表

当前是 `config/admin.php` 里的**一组** `username` + `password_hash`，也就是说**只能有一个管理员**。
这是刻意的取舍：单店点餐场景通常只有一个运营角色，用配置文件最简单，
而且和 `database.php` / `security.php` 一样统一放在 `config/` 下（已被 `.gitignore` 忽略），
不必为一个账号去改数据库结构。

代价是明确的，也记在了后台「账号与安全」页里：

| 做不到 | 原因 |
| --- | --- |
| 多个管理员 | 配置里只有一组用户名与密码 |
| 角色权限（只能看订单 / 不能改菜品） | 没有角色字段 |
| 操作日志（谁改了什么） | 没有记录主体 |
| 网页里改密码 | 要改的是 PHP 源文件，让 Web 进程去写自己的代码风险过大（写坏即后台失联） |
| 改密码后立刻踢掉旧会话 | 会话校验不关联凭据版本 |

> 会话校验确实会在每次请求时重新读配置（`AdminAuth::config()` 是缓存到单次请求内的静态变量），
> 所以改密码**只影响下一次登录**；已经登录的会话最长会再存活一个空闲超时周期
> （默认 2 小时）。需要立刻全部失效时，重启 Apache 或清掉 PHP session 目录下的会话文件即可。

升级路径：新建 `admins` 表（`username` / `password_hash` / `display_name` / `role` / `is_active` / `last_login_at`）
+ 后台加一个真正能提交的表单页面，上面这些问题都能一并解决。

### 鉴权与防护

| 风险 | 做法 |
| --- | --- |
| 未登录访问 | 每个页面 `AdminAuth::requireLogin()`，未登录 302 跳登录页并记住原目标地址 |
| 会话固定攻击 | 登录成功与退出时都 `session_regenerate_id(true)`；开启 `session.use_strict_mode` |
| Cookie 泄露 | 专用 Cookie 名 `TWSESSID`，`HttpOnly` + `SameSite=Lax` + `path=/admin`（接口请求不会带上它） |
| 密码泄露 | 配置里只存 bcrypt 哈希（`password_hash` / `password_verify`），不存明文 |
| 暴力破解 | 按 IP 统计失败次数，5 次锁定 5 分钟；失败时 `usleep` 延迟。计数写临时文件而非只存会话，**清 Cookie 无法绕过** |
| CSRF | 所有写操作必须 POST + `_csrf` 一次性令牌（`hash_equals` 比较）；退出登录也走 POST |
| 开放重定向 | 登录后跳回的地址必须是以 `/admin/` 开头的站内路径，挡掉 `//evil.com` 这类协议相对地址 |
| 存储型 XSS | 所有动态输出经过 `e()` 转义 |
| 源码被直接访问 | `lib/` 下每个文件开头 `if (!defined('TW_ADMIN')) { 403; exit; }`，**不依赖服务器配置**，Apache 与 nginx 下都有效；另加 `lib/.htaccess` 作为第二层 |
| 图片地址注入 | `image_url` 只放行 `http://` / `https://` 开头，挡掉 `javascript:` 等伪协议 |

### 写操作的统一模式（PRG）

```
表单 POST ──► 校验 CSRF ──► 执行业务 ──► flash_set(结果) ──► 302 跳回列表
                                                              │
                                       列表页读取 flash 并显示 ┘
```

好处是刷新页面不会重复提交，也不会弹「是否重新提交表单」。
筛选条件随 URL 一起跳回，管理员的操作视线不会被打断。

### 与小程序接口的关系

| 关注点 | 小程序接口 | 管理后台 |
| --- | --- | --- |
| 鉴权方式 | HMAC-SHA256 令牌（无状态，7 天） | 会话登录（有状态，2 小时空闲超时） |
| 身份来源 | 令牌里的 `uid` | 会话里的管理员名（与顾客账号体系完全无关） |
| 订单归属 | 只能操作本人订单 | 可操作任何订单 |
| 状态流转 | 调用 `OrderStatus::transition($id, $to, $userId)` | 调用 `OrderStatus::transition($id, $to, null)` |
| 上架/下架 | 只读，只能看到上架菜品 | 可切换 `dishes.is_on_sale` |

## 10. AI 接口限流（core/AiRateLimit.php）

AI 接口的风险不是「数据泄露」而是「**费用失控**」：`ai/chat.php`、`ai/chat_stream.php`
每次调用都真实消耗 DeepSeek 额度，而它们原本免登录、无次数限制 ——
任何人写个循环就能把 API Key 的余额刷光。

### 三层同时生效

| 层 | 标识 | 默认限额 | 作用 |
| --- | --- | --- | --- |
| 每分钟 | 身份 | 登录 6 次 / 游客 3 次 | 挡住瞬时连点与脚本高速轰炸 |
| 每天 | 身份 | 登录 100 次 / 游客 30 次 | 挡住「细水长流」式持续消耗 |
| 每天 | 全站 | 3000 次 | 最后一道保险：前两层被绕过也有总上限 |

身份判定：带有效令牌按 `uid:10004`，否则按 IP（`ip:1.2.3.4`）。
游客额度更低 —— 一个 IP 背后可能是很多人（校园网/公司出口），且游客没有账号成本、更容易被刷。

### 几个刻意的设计选择

| 选择 | 原因 |
| --- | --- |
| **先自增再判断**，被拒绝的请求也计数 | 否则攻击者可以无限重试，限流等于没有。顺序是「原子自增 → 读回 → 超了才拒」 |
| `INSERT ... ON DUPLICATE KEY UPDATE hits = hits + 1` | 靠 `(bucket_key, window_start)` 唯一索引一条语句完成，换成「先 SELECT 再 UPDATE」并发下会漏计数 |
| 窗口以 **Unix 时间戳对齐**（不按本地零点） | 行为完全可预测、便于测试；代价是天配额在北京时间早 8 点重置 |
| 用**数据库**而不是文件/APCu | 项目已有 MySQL，天然支持跨进程原子自增；文件锁容易写错，APCu 在 phpstudy 上不保证可用 |
| 清理用**概率触发**而非定时任务 | phpstudy 环境没有可靠的 cron；「平均每 50 次请求清一次」最省事，且清理失败不影响主流程 |
| 三个接口**共用同一套额度** | 它们烧的是同一份 API 额度，分开计数等于给了双倍配额 |

并发下读到的计数可能已包含别人的自增结果（只是让限制更严一点），方向是安全的，不会漏放。

## 11. AI 流式输出（SSE）

非流式接口要等 AI 把整段话写完（通常 2-5 秒）才返回，界面只能干等。
改成流式后第一个字通常在 1 秒内就到，体感差别很明显。

### 链路

```
DeepSeek SSE ──cURL 写回调──► PHP 逐段解析 ──echo──► 客户端 SSE ──► 小程序逐段渲染
```

| 环节 | 实现 | 关键点 |
| --- | --- | --- |
| 上游 | `DeepSeek::requestApiStream()` | 用 `CURLOPT_WRITEFUNCTION` 代替 `CURLOPT_RETURNTRANSFER`（前者数据一到就回调，后者攒齐才给）；**写回调必须返回收到的字节数**，否则 cURL 判定写入失败并中断连接 |
| 分帧 | 服务端按行缓冲 | TCP 分块边界与 SSE 事件边界无关，一次回调可能是半行、也可能是好几行，必须自己维护行缓冲，只处理完整的行 |
| 下游 | `api/ai/chat_stream.php` | 三层缓冲都要关：PHP 的 `output_buffering`（`ob_end_flush`）、gzip（`zlib.output_compression=0`）、反向代理（`X-Accel-Buffering: no`）。少关任何一层，用户都会看到"等几秒然后整段蹦出来" |
| 客户端 | `utils/sse.ts` | 小程序没有 EventSource，用 `wx.request({enableChunked:true})` + `onChunkReceived`，服务端仍是标准 SSE |

### 客户端两个必须处理的细节

1. **跨分块的 UTF-8 解码**（`utils/utf8.ts`）
   `onChunkReceived` 的分块边界是 TCP 层面的，一个汉字占 3 字节，
   完全可能被切成「前 1 字节在这块、后 2 字节在下块」。
   每块单独解码就会出乱码，所以必须把不完整的尾巴留到下一块。
   小程序里没有 `TextDecoder`，这段是手写的状态机。
2. **`setData` 节流**（`pages/aiChat/aiChat.ts`）
   AI 可能每几十毫秒吐一个词，每个字都 `setData` 会触发上百次视图更新。
   做法是把增量先累积到一个独立的 `streamingText` 字符串字段里，
   每 80ms 合并刷新一次（第一段立刻显示），结束后再作为一条正式消息追加进 `messages`。
   顺带避免了「每次刷新都把整段对话重新发给视图层」。

### 推荐卡片为什么放在最后

卡片是从**完整回复**里识别菜名得到的（`core/AiReply.php`），增量阶段拿不到全文，
所以 `delta` 只负责「显示得快」，卡片统一放在最后的 `done` 事件里。

### ⚠️ 部署环境要求：Apache + mod_fcgid 会破坏流式

phpstudy 的 Apache 用 **`mod_fcgid`** 跑 php-cgi，而它的 `FcgidOutputBufferSize`
**默认 65536 字节** —— 小于 64KB 的响应会被整个缓存到请求结束才发出去，
此时流式会退化成「等几秒、然后整段蹦出来」（功能正常，只是没有逐字效果）。

实测证据（一段每 0.5 秒输出一行的最小脚本，客户端侧观察到达时间）：

```
不修改配置：  3.036 秒  收到 tick 1 / tick 2 / ... / tick 6        ← 6 行全攒到最后一起到达（跨度 0）
加上配置后：  0.029 秒  tick 1
              0.531 秒  tick 2
              1.033 秒  tick 3
              1.536 秒  tick 4
              2.039 秒  tick 5
              2.553 秒  tick 6                                    ← 每 0.5 秒一行，跨度 2.524 秒
```

**修复**：在 Apache 配置里加一行并重启 Apache

```apache
FcgidOutputBufferSize 0
```

（放在已有的 `FcgidMaxRequestLen` 旁边即可；这是全局指令，会影响该 Apache 上所有站点，
但只是把「缓存 64KB 再发」改成「不缓存」，不会改变功能语义。）

对应地，`deployment.md` 的常见问题里也记了这一条。

> 补充一个实测观察：改完之后 AI 流式的内容有时会**成组出现**（例如先来两小段、
> 停顿一下、再涌出一大段），这不是本地缓冲没关干净 ——
> 最小复现脚本是严格每 0.5 秒一行的，说明输出层已经完全不缓冲了；
> 成组是上游模型自身吐 token 的节奏（服务端也会做小批量合并）。
> 用户视角依然是「边生成边显示」，只是节奏不如计时器那么均匀。

### 对话长度上限（为什么必须由服务端来管）

第 N 轮请求要把前面 N-1 轮的内容全部重发一遍，所以一次长对话的成本是 **O(N²)**：
一轮 20 个来回的闲聊，仅最后一句就要发约 3.8 万 input tokens。
不限制的话，除了烧钱，还迟早会**超出模型上下文窗口**，请求直接失败，
而用户完全不知道为什么。

**前端会截断（只带最近 12 条），但服务端必须自己再洗一遍** ——
前端截断只是「少传数据」，不是安全边界：任何人都可以绕过小程序直接调接口。
清洗逻辑在 `DeepSeek::sanitizeHistory()`，`chat()` 与 `chatStream()` 都会调用，
所以不存在「某个接口忘了洗」的可能。它做三件事：

| 做什么 | 为什么 |
| --- | --- |
| **只放行 `user` / `assistant`，丢弃 `system`** | ⚠️ 安全：请求体里的消息会被拼在系统提示词**之后**，放行 `system` 就等于允许调用者覆盖「小味」的身份与行为约束 —— 典型的**提示词注入**，能让 AI 说出任何话还顶着助手名义返回 |
| 限制条数（12）、单条长度（1000）、合计长度（4000） | 成本与稳定性（见上） |
| 丢掉结构不合法的项 | 防止脏数据把请求体搞成模型无法解析的形状 |

> 注意区分两个不同的成本漏洞：`core/AiRateLimit.php` 限的是**调用次数**，
> 这里限的是**每次的 token 量**。只做前者的话，一次超长对话照样能烧掉大量额度。

## 12. 超时未支付订单的自动取消（core/OrderExpiry.php）

### 要解决的问题：库存会被永久占用

下单时会**立即扣库存**（这是防超卖的必要设计，见第 7 节），而库存只有在
「取消订单」时才会还回去。如果顾客下单后既不付款也不取消，
那份库存就被永久占用了 —— 库存 999 时看不出来，一旦某个菜只剩一两份，
就等于被一个幽灵订单吃掉，而且再也回不来。

正常做法是定时任务扫一遍超时订单。但这个项目跑在 phpstudy 上，
**没有可靠的 cron 保证**，所以改用**懒清理**：在本来就要访问订单的场景里顺手清一批，
和 `core/AiRateLimit.php` 的概率性 GC 是同一个思路。

### 在哪几个地方触发

| 触发点 | 范围 | 门控 | 为什么放这里 |
| --- | --- | --- | --- |
| `order/create.php`（下单前） | 本人 | 每次都做 | 顾客上一笔超时订单会占着库存，不清掉的话他这次结算可能因「库存不足」失败 —— 而缺的正是他自己占掉的那份 |
| `order/list.php`（看我的订单） | 本人 | 每次都做 | 顾客最该在这里看到真实状态，而不是一笔早就超时却还挂着「待支付」的订单 |
| `backend/admin/index.php`（后台订单页） | 全站 | 概率门控（1/20） | 全站扫描没必要每个请求都做 |
| 后台「立刻清理」按钮 | 全站 | 强制 | 管理员要立刻对账时用 |

单用户维度走 `user_id` 索引、开销很小，所以每次都做；全站维度按
`status + created_at` 走 `idx_status_created`，用概率门控 + 手动按钮控制频率。

### 为什么不自己实现「取消」

取消订单要做的事不止改状态：要在事务里加行锁、按订单明细把库存加回去、
用原状态做原子 CAS 防止并发重复取消。这些已经在 `core/OrderStatus.php` 里
实现过一遍，再写一遍两边早晚不一致（典型后果是**库存被重复加回**）。
所以 `OrderExpiry` 只负责「找出该清理哪些订单」，真正的流转交给 `OrderStatus`。

并发下同一笔订单被两个请求同时清理是安全的：第二个会拿到 409（状态冲突）并被忽略，
库存不会加回两次 —— 这一点有测试覆盖。

### 一个容易写错的地方：不要用 PHP 算截止时间

```php
// ❌ 错的：created_at 是 MySQL 按它自己的时区写入的，而 PHP 的时区未必一致，
//         这样算会让「超时」判断整体偏移几个小时，可能把刚下的单当成超时。
$cutoff = date('Y-m-d H:i:s', time() - 15 * 60);

// ✅ 对的：把时间比较交给 MySQL 自己的 NOW()
'... AND created_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
```

超时时长在 `OrderExpiry::TIMEOUT_MINUTES`（默认 15 分钟）。
被自动取消的订单会在备注里追加「（超时未支付，系统自动取消）」，
方便后台与顾客看出原因（用 `LEFT(..., 200)` 兜住 `remark varchar(200)` 的长度限制 ——
严格模式下超长会直接报错）。

## 13. 菜品查询的统一（core/DishRepo.php）

### 问题：一条业务规则被抄了六份，然后各自跑偏

「哪些菜算在售」原本散落在 6 个地方，而且**已经出现了不一致**：

| 位置 | 原来的条件 | 问题 |
| --- | --- | --- |
| `api/dish/list.php` | `is_on_sale = 1` | 正确（售完仍要展示） |
| `api/dish/detail.php` | `d.is_on_sale = 1` | 正确 |
| `api/ai/chat.php` | `stock > 0 AND is_on_sale = 1` | 正确 |
| `api/ai/chat_stream.php` | `stock > 0 AND is_on_sale = 1` | 与上一行**逐字重复** |
| `core/DeepSeek.php` | `d.stock > 0 AND d.is_on_sale = 1` | 正确 |

「AI 推荐会推荐一道点不了的菜」这个 bug，正是这种散落结构的必然产物 ——
它不会被任何一次代码评审发现，因为每一处单独看都像是对的。

### 解法：把两种「在售」显式区分开

关键是承认**这里其实是两条不同的规则**，而不是一条规则被写错了：

| 规则 | 条件 | 用在哪 | 为什么 |
| --- | --- | --- | --- |
| **顾客可见** `condVisible()` | `is_on_sale = 1` | 菜品列表、菜品详情 | 售完仍展示、标成「已售完」。整道菜突然消失会让顾客以为店里没这道菜 |
| **可出售** `condSellable()` | `is_on_sale = 1 AND stock > 0` | AI 推荐、菜单注入、卡片识别 | 这些场景是「向顾客推荐」，推荐一道点不了的菜只会让人白高兴一场 |

`DishRepo` 提供 `visibleList()` / `findVisibleWithCategory()` / `sellableList()`
三个查询方法，6 处调用全部改成调它；条件本身用带别名占位符的模板定义，
需要 JOIN 的场景（取分类名）传别名即可，避免 `Column 'is_on_sale' in where clause is ambiguous`。

字段清单（`COLUMNS`）也统一在这里，避免出现「列表有图、卡片没图」这类缺字段问题。

### 配套：core/AiCards.php

`ai/chat.php` 与 `ai/chat_stream.php` 里「取在售菜单 → 从回复文本识别菜品 → 必要时兜底做一次结构化推荐」
这段逻辑原来是**逐字重复**的两份（连注释都一样）。
只要改一处忘一处，就会出现「非流式有卡片、流式没卡片」这种极难排查的不一致。
现在收成 `AiCards::fromReply()` 一份，两个接口都调它 ——
实测两条链路返回的卡片已经一致。

`AiCards::matchRecommendations()` 还承担「丢弃 AI 编造的菜品ID」这道闸：
AI 只返回 ID 和理由，其余字段从菜单里补齐，ID 对不上的一律丢弃。

## 14. 核心层基础设施

### 14.1 自动加载（core/bootstrap.php）

入口文件只需一行

```php
require_once __DIR__ . '/../../core/bootstrap.php';
```

`bootstrap.php` 里注册 `spl_autoload_register`，按类名去 `core/<类名>.php` 找。
**新增 core 类不再需要修改任何现有文件**。

两处刻意的保守设计：只接**没有命名空间**的类（将来引入带命名空间的第三方库不会被误抓）；
找不到文件时**什么都不做**、不抛异常，避免自己成为"类找不到"的报错来源。

### 14.2 入参读取（core/Request.php）

**改之前**：每个接口自己写这几句

```php
$input = json_decode(file_get_contents('php://input'), true);
$message = isset($input['message']) ? trim($input['message']) : '';
$page    = isset($_GET['page']) ? intval($_GET['page']) : 0;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { Response::error('仅支持POST请求'); }
```

全项目这样的样板有 20+ 处。问题不只是啰嗦，而是**同一个入参在不同接口里的行为可能不一样**：
有的地方 trim 了、有的没 trim；有的做了范围夹取、有的没做；`intval()` 不校验负数。
这种不一致没有任何地方会提醒你。

收进 `Request` 之后行为统一了：字符串一律 trim（可截断）、整数一律夹到指定范围、
非法的值退回默认值、方法判断只有一种措辞。

> ⚠️ **有一个 API 是刻意留的**：`Request::raw()` 取原始值，不转换。
> 因为有些接口必须区分「没传这个参数」和「传了 0」——
> 例如购物车改数量：没传 `quantity` 要报参数错误，而 `quantity=0` 是「移除这条记录」。
> 如果图省事用 `Request::int()`，非数字的 `"abc"` 会被静默当成 0，
> **结果是一次手滑的请求把用户的购物车记录删掉**。
> 这个坑在改写时差点踩到，所以 `Request::raw()` 的注释里专门写了这件事。

### 14.3 500 错误不再泄露内部信息（Response::serverError）

**改之前**：每个接口自己写

```php
Response::error('下单失败：' . $e->getMessage(), 500);
```

而异常信息里经常带内部细节，最典型的是 PDO 的报错：

```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'xxx' in 'where clause'
```

这等于把**表结构、列名、甚至约束名**告诉调用方 —— 既是信息泄露，也帮攻击者省了探测成本。

**改之后**统一走 `Response::serverError($context, $e)`：

| 去向 | 内容 |
| --- | --- |
| 服务器错误日志 | **始终**写完整细节（类名、消息、文件、行号、请求URI）—— 排查靠它 |
| 响应体（本机回环地址） | `下单失败：SQLSTATE[42S22]...` —— 本地调试需要 |
| 响应体（外部地址） | `下单失败` —— 不泄露任何内部信息 |

判定用的是 `REMOTE_ADDR` 是否为本机回环地址，与 `security.php` 里
`allow_dev_openid_login` 的「本地调试通道」是同一套思路：**不新增配置项，但默认对外不泄露**。

日志用 `error_log()` 而不自己写文件：不带目标时它进入 SAPI 日志
（Apache 下即 Apache 的 `error.log`），零配置、且不会因为目录不可写而失败。
phpstudy 下的位置：`Extensions\Apache2.4.39\logs\error.log`。

流式接口（`ai/chat_stream.php`）没法用 `serverError()`（它已进入 SSE 输出），
但用的是同一个判定：`Response::isLocalRequest()`。

## 15. 其他工程决策

### 15.1 HTTP 状态码与业务码一致（Response.php）

**改之前**：所有响应一律返回 HTTP 200，业务码放在 body 里。代价是 HTTP 这一层的信息完全失效：

- 监控/告警按状态码统计错误时永远是 0
- 反向代理、CDN、网关无法按状态码做缓存与重试策略
- 服务器访问日志里全是 200，出了问题看不出哪些请求失败了

**改之后**：`http_response_code($code)`，HTTP 状态码与 `code` 一致。**响应体结构没变**，
所以前端读 `code` 的逻辑不受影响。

> 之所以敢这么做，是因为小程序的 `wx.request` 对非 2xx 响应**仍然走 `success` 回调**
> （`fail` 只用于网络层失败：超时、域名不合法、连不上）。
> 但这一点本项目**无法在自动化测试中验证**（要跑小程序运行时），
> 所以留了一个一行开关：`Response::$mirrorHttpStatus = false` 即恢复「一律 200」的老行为，
> 前端不需要任何改动。

### 15.2 后端地址按运行环境自动切换（frontend/utils/api.ts）

**改之前**：`const BASE_URL = 'http://localhost'` 写死一行。
于是「本地调试 / 体验版 / 正式版」要切后端时只能改源码 ——
容易改错，更容易**忘记改回来**（把正式包指向 localhost 是致命错误）。

**改之后**：用 `wx.getAccountInfoSync().miniProgram.envVersion` 区分运行环境，按 `API_BASE_BY_ENV` 自动选：

```
develop（开发者工具/真机调试） → 本地后端
trial  （体验版）              → 测试服务器
release（正式版）              → 正式域名
```

真机调试时手机上的 `localhost` 指向手机自己，需要局域网 IP；
为了方便临时切换，还支持一句控制台命令覆盖（不必改代码）：

```js
wx.setStorageSync('apiBaseUrl', 'http://192.168.1.5')
```

### 15.3 admin 页面的查询层（admin/lib/queries/）

**改之前**：`admin/index.php` 339 行里既有「解析筛选参数」「拼 SQL」「查库」，
又有 180 行 HTML 渲染 —— 改查询逻辑要在 HTML 中间找位置。

**改之后**：SQL 挪到 `admin/lib/queries/orders.php` 与 `dishes.php`，
页面只负责「取参数 → 调查询 → 渲染」：

```php
$filters  = admin_order_filters($_GET);       // 解析筛选条件（含 LIKE 转义、日期校验）
$total    = admin_order_count($filters['where'], $filters['params']);
$orders   = admin_order_page($filters['where'], $filters['params'], $page, $perPage);
$stats    = admin_order_stats();              // 顶部总览（不受筛选影响）
```

这是无框架后台的正常取舍：**不引入模板引擎，但至少把「数据」和「展示」分开**。
好处是这些函数是纯数据函数，可以单独调用验证，不必先渲染页面。

### 15.4 订单列表分页

`order/list.php` 原本一次性返回全部订单。订单是只增不减的数据，
老用户迟早积累成百上千单，全量返回会让响应体越来越大，
而且每单还要再查一次明细（N+1），开销随订单数线性增长。

加了 `page` / `per_page` 之后有个**容易被忽略的细节**：排序必须是
`ORDER BY created_at DESC, id DESC`。只按 `created_at` 排的话，
同一秒创建的两笔订单顺序不固定，翻页时会出现「上一页出现过、下一页又出现一次」
或整条被跳过。测试里专门造了同一秒的多笔订单来覆盖这一点
（每页 3 条翻 5 页，验证无重复、无漏单）。

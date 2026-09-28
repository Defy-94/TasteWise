# 系统架构

## 1. 总体架构

```
┌─────────────────────────────┐
│     微信小程序前端 (frontend) │
│   WXML + WXSS + TypeScript  │
│  pages/ · utils/ · images/  │
└─────────────┬───────────────┘
              │ HTTPS / wx.request
              ▼
┌─────────────────────────────┐
│       PHP 后端 (backend)     │
│   api/  →  core/  →  config/│
│  Apache + PHP 7.4+          │
└─────────────┬───────────────┘
              │
      ┌───────┴────────┐
      ▼                ▼
┌──────────┐   ┌──────────────┐
│  MySQL   │   │ DeepSeek API │
│ 5.7+     │   │ (AI 大模型)   │
└──────────┘   └──────────────┘
```

- **前端**：微信小程序原生框架，无第三方 UI 库；通过 `utils/api.ts` 统一封装 `wx.request`。
- **后端**：原生 PHP，无框架、无 Composer 依赖，适合 phpstudy 等本地环境直接运行。
- **数据库**：MySQL，通过 `core/DB.php`（PDO 单例）统一访问，所有 SQL 均使用预处理语句防注入。
- **AI**：DeepSeek 官方 API（`https://api.deepseek.com/v1/chat/completions`），由 `core/DeepSeek.php` 封装。

## 2. 模块划分

### 前端 `frontend/`

| 模块 | 路径 | 职责 |
| --- | --- | --- |
| 全局入口 | `app.ts` / `app.json` / `app.wxss` | 应用生命周期、TabBar、全局样式 |
| 首页 | `pages/index/` | 分类导航、菜品列表、AI 推荐弹窗、加入购物车 |
| 购物车 | `pages/cart/` | 购物车展示、删除、结算下单 |
| AI 助手 | `pages/aiChat/` | 对话界面、AI 推荐菜品卡片、快捷加购 |
| 用户中心 | `pages/userCenter/` | 用户信息展示与编辑、订单入口 |
| 我的订单 | `pages/myOrders/` | 订单列表、支付/取消/完成（状态流转）、状态色区分 |
| 登录 | `pages/login/` | 微信一键登录（登录即注册，已无独立注册页） |
| 关于 | `pages/about/` | 项目介绍 |
| 工具层 | `utils/api.ts` `utils/types.ts` | 请求封装（令牌注入 + 401 跳登录）、TS 类型定义 |

### 后端 `backend/`

| 模块 | 路径 | 职责 |
| --- | --- | --- |
| 接口层 | `api/` | 按业务域拆分：category / dish / cart / order / user / ai |
| 核心层 | `core/DB.php` | PDO 单例，select / insert / execute / 事务 |
| 核心层 | `core/Response.php` | 统一 JSON 输出与 CORS 处理 |
| 核心层 | `core/Auth.php` | 登录令牌签发（`issue`）、校验（`verify`）、鉴权入口（`requireLogin`） |
| 核心层 | `core/DeepSeek.php` | AI 对话、菜品推荐、动态菜单注入、提示词管理 |
| 配置层 | `config/` | 数据库连接、DeepSeek Key、令牌签名密钥（⚠️ 敏感，不提交） |
| 数据库脚本 | `sql/init.sql` | 建库建表 + 测试数据 |

## 3. 请求流程（以「AI 推荐菜品」为例）

1. 首页点击「AI 推荐」→ 输入口味偏好 → `POST /api/dish/recommend.php`
2. 后端 `recommend.php` 从数据库读取全部菜品 → 调用 `DeepSeek::recommend($dishes, $preference)`
3. `DeepSeek` 将菜品列表 JSON 注入提示词，请求大模型返回 `{"recommendations":[{"id","reason"}]}`
4. 后端**过滤无效 ID**（只保留菜品表中真实存在的 ID，防止 AI 编造）
5. 返回完整菜品信息 + `ai_reason` 推荐理由 → 前端弹窗展示

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

设计要点：

- **前置状态校验**：每个流转接口先校验当前状态是否允许该操作，不允许则返回 `409`。
- **原子更新**：`UPDATE orders SET status = ? WHERE id = ? AND user_id = ? AND status = ?`，
  把「当前状态」写进 WHERE 条件，并发重复请求只有一次生效。
- **事务 + 行锁**：取消订单在事务内用 `SELECT ... FOR UPDATE` 锁定订单行，
  再逐项恢复库存，避免并发取消导致库存被重复加回。
- **归属校验**：所有操作都带 `user_id = 令牌中的用户ID`，无法操作他人订单。

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
- **登录即注册**：首次登录自动建号，因此不再需要单独的注册流程
  （原 `register.php`、`login_phone.php` 已停用，原因见 [api.md](api.md)）。
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
- 敏感配置（API Key、数据库密码、令牌密钥）位于 `config/`，通过 `.gitignore` 防止上传泄露。
- ⚠️ 上线前仍需处理：
  1. `CURLOPT_SSL_VERIFYPEER` 置回 `true`；
  2. CORS `Access-Control-Allow-Origin: *` 收紧为具体域名；
  3. 订单支付接入微信支付（当前为模拟支付）；
  4. 接口限流（防止 AI 接口被刷导致费用失控）；
  5. 全站 HTTPS。

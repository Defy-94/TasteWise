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

#### 关于「本地凭据备忘」的自动同步

只有文件里**本来就存在** `密码：xxx` 这样的行时才会去改它；
从来没写过备忘的配置不会被凭空塞进明文密码。用 `--hash` 传入哈希时拿不到明文，
脚本改为提示手动更新那一行。

五个实现上的坑（都实测踩过并已修）：

1. **非交互调用会挂死**：脚本在 Windows 下为隐藏密码输入会派生 PowerShell 读控制台，
   当 stdin 是管道时那个子进程永远等不到输入。现在先用 `stream_isatty(STDIN)` 判断
   「是否真的有人在终端前面」，非交互就直接 `fgets`。
2. **PowerShell 会往管道里塞 BOM**：PowerShell 5.1 往原生进程的 stdin 写内容前会先写一个
    UTF-8 BOM，于是管道喂进来的第一个密码会静静多出一个看不见的字符，
   结果是「设完密码却怎么都登不上」。现在非交互分支会剥掉行首 BOM。
3. **`chcp 65001` 会吃掉重定向的 stdin**：批处理里为了中文不乱码必须切到 UTF-8 代码页，
   但 `chcp 65001 >nul` 会把已经重定向进来的 stdin 读到 EOF，
   后面的 PHP 就读不到任何输入了。实测三种写法后确认
   **`chcp 65001 <nul >nul`** 既能切代码页、又不动 stdin
   （`<nul` 让 chcp 不碰那个被继承的输入句柄）。两个 `.bat` 用的都是这个写法。
4. **`.bat` 里不能混中文和 `chcp`**：cmd.exe 内部按「字符数」定位批处理文件、
   实际按「字节数」读取，代码页一换就对不上 —— 表现为**汉字被重复输出**
   （「后台地址」变成「后后台台地地址址」）和**半行被当成命令执行**
   （报 `'...' is not recognized as an internal or external command`）。
   是否触发取决于字节偏移，改几个字就可能从正常变出错。
   所以两个 `.bat` **只允许 ASCII 字符**，全部中文由 PHP 输出
   （`backend/tools/admin_launch.php` 与 `admin_passwd.php`）。
5. **PCRE 处理含中文的正则必须加 `u` 修饰符**：不带 `u` 时按字节匹配，
   `[：:]` 会变成「全角冒号的 3 个字节中任意一个字节」的字符类，
   只吃掉 1 个字节、剩下 2 个被后面的 `\S.*` 吞掉，
   于是往配置里写进一个残缺字节（`登录名：admin` 变成 `登录名<乱码>admin`）。
   同步凭据备忘的正则就是踩了这个坑。

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

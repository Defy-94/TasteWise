# 🍽️ TasteWise 智味 · AI 点餐微信小程序

一个集 **智能推荐** 与 **AI 客服** 于一体的微信点餐小程序，前端使用微信小程序原生框架（TypeScript），后端使用 PHP + MySQL，并深度集成 DeepSeek 大模型，实现「AI 推荐菜品」「AI 点餐助手对话」等特色功能。

> 项目英文名：TasteWise · 中文名：智味 · 小程序内 AI 助手昵称：小味

---

## ✨ 功能特性

| 模块 | 功能说明 |
| --- | --- |
| 🏠 首页 | 菜品分类导航、**菜品搜索（边打字边搜，同时匹配菜名与描述，菜名命中优先）**、菜品列表展示（价格/辣度/图片/描述）、AI 推荐入口、一键加入购物车、空结果友好提示 |
| 🍲 菜品详情 | 大图、完整介绍、辣度与**已售份数**、**可调份数加购**、售完/已下架友好提示 |
| 🛒 购物车 | 购物车列表、加入/移除商品、**数量调整（− / +，减到 0 即移除）**、订单备注、结算下单（含库存校验与扣减）、TabBar 件数角标 |
| 📦 订单系统 | 下单（事务保证 + **原子扣减防超卖**）、**订单状态流转**（待支付 → 已支付 → 已完成 / 已取消）、取消自动回滚库存、**按状态筛选订单** |
| 🤖 AI 助手 | 与 DeepSeek 大模型实时对话，**流式输出（SSE，逐字显示，基础库不支持时自动回退）**，动态注入当日菜单防止编造菜品、推荐结果生成可点击菜品卡片、**快捷提问**与「正在输入」提示 |
| 🛡️ 接口防护 | AI 接口**三层限流**（身份每分钟 / 身份每天 / 全站每天总量），防止 API Key 被刷导致费用失控；超限返回 `429` + `Retry-After`，成功时带剩余额度响应头 |
| 👤 用户中心 | **微信一键登录**（首次登录自动建号，无需注册）、**订单各状态数量概览（点击直达筛选）**、个人信息编辑、最近订单 |
| 🔐 登录鉴权 | 微信 `code2Session` 换取 openid + HMAC-SHA256 登录令牌，**防越权、防账号伪造** |
| 🛠️ 管理后台 | 独立的 PHP 后台（`/admin/`）：订单管理（筛选/改状态/看明细）、菜品管理（增删改查、**上架下架**、行内改库存价格）、数据总览；**会话登录 + CSRF 防护 + 登录失败锁定** |

## 🧰 技术栈

- **前端**：微信小程序原生框架（WXML / WXSS / TypeScript）、`miniprogram-api-typings`
- **后端**：PHP 7.3+（原生 PHP，无框架）、PDO 预处理防注入
- **数据库**：MySQL 5.7+（utf8mb4）
- **登录**：微信 `wx.login` + 服务端 `code2Session`（openid 由微信签发，客户端无法伪造）
- **鉴权**：HMAC-SHA256 签名的自包含登录令牌（有效期 7 天，服务端免存储）
- **AI 能力**：DeepSeek API（`deepseek-chat` 模型，支持切换 `deepseek-reasoner`）
- **本地环境**：phpstudy_pro（Apache + MySQL + PHP）

## 📁 项目结构

```
TasteWise/
├── 启动管理后台.bat           # 双击即打开管理后台（Windows 一键入口脚本）
├── 修改后台密码.bat           # 双击即改管理员密码（会自己找到 PHP）
├── frontend/                  # 微信小程序前端
│   ├── pages/                 # 页面（8 个）
│   │   ├── index/             #   首页：分类 + 菜品 + AI推荐
│   │   ├── dishDetail/        #   菜品详情（大图/介绍/已售/调份数加购）
│   │   ├── cart/              #   购物车（含数量调整）
│   │   ├── aiChat/            #   AI 助手对话
│   │   ├── userCenter/        #   用户中心
│   │   ├── myOrders/          #   我的订单（含支付/取消/完成）
│   │   ├── login/             #   微信一键登录
│   │   └── about/             #   关于
│   ├── utils/
│   │   ├── api.ts             #   HTTP 请求封装（BASE_URL 配置 + 令牌注入 + 401 处理）
│   │   ├── error.ts           #   统一的错误文案提取与日志
│   │   ├── sse.ts             #   AI 流式对话客户端（wx.request 分块接收 + SSE 解析）
│   │   ├── sseParser.ts       #   SSE 文本流分帧（纯逻辑，可单测）
│   │   ├── utf8.ts            #   跨分块的增量 UTF-8 解码（纯逻辑，可单测）
│   │   ├── cartBadge.ts       #   购物车 TabBar 角标同步
│   │   └── types.ts           #   全局 TS 类型定义
│   ├── images/                #  TabBar 图标等静态资源
│   ├── app.ts / app.json / app.wxss
│   └── project.config.json    #   开发者工具项目配置
├── backend/                   # PHP 后端服务
│   ├── api/                   # RESTful 接口（20 个）
│   │   ├── category/          #   分类
│   │   ├── dish/              #   菜品列表 / 详情 / AI 推荐
│   │   ├── cart/              #   购物车（列表/加入/移除/改数量）
│   │   ├── order/             #   订单（创建/列表/支付/取消/完成）
│   │   ├── user/              #   用户（登录/资料/校验）
│   │   └── ai/                #   AI 对话（非流式 + SSE 流式）
│   ├── admin/                 # 🛠️ 管理后台（浏览器访问 /admin/，PHP 服务端渲染）
│   │   ├── login.php          #   管理员登录（会话 + CSRF + 失败锁定）
│   │   ├── logout.php         #   退出（仅 POST）
│   │   ├── index.php          #   订单管理（筛选 / 分页 / 快捷改状态）
│   │   ├── order.php          #   订单详情与状态流转
│   │   ├── dishes.php         #   菜品管理（上下架 / 改库存 / 删除）
│   │   ├── dish_edit.php      #   新增 / 编辑菜品
│   │   ├── lib/               #   认证、布局、助手函数（URL 直访被拒）
│   │   └── assets/admin.css   #   后台样式
│   ├── tools/                 # 🧰 命令行工具（不在浏览器里用，URL 直访被拒）
│   │   └── admin_passwd.php   #   改管理员密码：交互式输入 → 写回配置 → 回读校验
│   ├── core/                  # 核心封装层
│   │   ├── bootstrap.php      #   自动加载注册（入口只需引它一行）
│   │   ├── DB.php             #   PDO 单例数据库操作类 + LIKE 转义
│   │   ├── Request.php        #   请求入参读取（统一 trim/夹取/默认值）
│   │   ├── Response.php       #   统一 JSON 返回格式 + 500 日志与脱敏
│   │   ├── Auth.php           #   登录令牌签发与校验（鉴权入口）
│   │   ├── DishRepo.php       #   ⭐ 菜品查询唯一入口（「在售」规则只在这里定义）
│   │   ├── AiRateLimit.php    #   AI 接口限流（三层窗口，防 API 费用失控）
│   │   ├── AiCards.php        #   AI 回复 → 推荐卡片（非流式与流式共用一份）
│   │   ├── OrderStatus.php    #   ⭐ 订单状态机唯一实现（接口与后台共用）
│   │   ├── OrderExpiry.php    #   超时未支付订单的自动取消（懒清理，归还库存）
│   │   ├── WeChat.php         #   微信接口封装（code2Session）
│   │   ├── DeepSeek.php       #   DeepSeek API 封装（提示词 + 动态菜单注入）
│   │   └── AiReply.php        #   AI 回复文本中识别菜品（生成推荐卡片）
│   ├── config/                # 配置文件（⚠️ 含敏感信息，已被 .gitignore 忽略）
│   │   ├── apikey.php         #   ⚠️ DeepSeek API Key（不提交，见 apikey.example.php）
│   │   ├── database.php       #   ⚠️ 数据库连接（不提交，见 database.example.php）
│   │   ├── security.php       #   ⚠️ 令牌签名密钥（不提交，见 security.example.php）
│   │   ├── wechat.php         #   ⚠️ 小程序 AppSecret（不提交，见 wechat.example.php）
│   │   └── admin.php          #   ⚠️ 管理员账号与密码哈希（不提交，见 admin.example.php）
│   ├── sql/init.sql           #   建库建表 + 演示数据
│   └── error/                 #   HTTP 错误页（被 Apache ErrorDocument 引用）
├── docs/                      # 📚 项目文档
└── README.md                  # 本文件
```

## 🚀 快速开始

### 1. 后端（PHP + MySQL）

1. 安装 [phpstudy_pro](https://www.xp.cn/)，启动 Apache 与 MySQL（PHP ≥ 7.3）。
2. 将 `backend/` 目录部署到站点根目录（如 `localhost/`，Apache 虚拟主机指向 `backend/`）。
3. 创建数据库并导入表结构与演示数据：
   ```sql
   -- 在 phpMyAdmin 或命令行执行
   source backend/sql/init.sql;
   ```
4. 复制并填写**五个**配置文件（**首次必做**）：
   ```bash
   cp backend/config/database.example.php backend/config/database.php
   cp backend/config/apikey.example.php   backend/config/apikey.php
   cp backend/config/security.example.php backend/config/security.php
   cp backend/config/wechat.example.php   backend/config/wechat.php
   cp backend/config/admin.example.php    backend/config/admin.php
   ```
   - `database.php`：数据库连接（phpstudy 默认 `root/root`）
   - `apikey.php`：在 [DeepSeek 开放平台](https://platform.deepseek.com/) 申请 API Key（`sk-` 开头）
   - `security.php`：令牌签名密钥，用 `php -r "echo bin2hex(random_bytes(32));"` 生成一个随机值填进去
   - `wechat.php`：小程序 `appid` + **`appsecret`**（小程序后台「开发管理 → 开发设置」获取；**AppSecret 属机密，只能放服务端**）
     > 未填写 `appsecret` 时微信一键登录会返回 503（提示"微信登录未配置"）。
     > 此时本地开发可临时打开 `security.php` 的 `allow_dev_openid_login` 用调试账号登录。
   - `admin.php`：管理后台账号。先复制模板，然后**双击项目根目录的 `修改后台密码.bat`** 设置密码
     （它会自己找到 PHP，提示输入两次新密码，直接写回配置文件并自动备份 + 回读校验）。
     习惯用命令行的话，等价的做法是在项目根目录执行：
     ```bash
     # 把 php 换成你机器上执行 PHP 的方式
     php backend/tools/admin_passwd.php
     ```
     > 也可以只看/改账号名：`--show`、`--username=新名字`（`--help` 看全部用法）。
     > 这个文件是必须建的：缺了它后台登录页会直接提示「缺少配置文件」。
     > 登录后台后，顶部导航的**「账号与安全」**页写着同样的方法。
5. 访问 `http://localhost/api/category/list.php`，返回 `{"code":200,...}` 即部署成功。

### 2. 管理后台（浏览器）

后端跑起来之后，浏览器打开 **`http://localhost/admin/`** 就是管理后台。

Windows 下更方便的方式：**双击项目根目录的 `启动管理后台.bat`**，它会先探测服务是否在运行（没启动会提示你去开 phpstudy），再自动打开浏览器。

首次使用需要在 `backend/config/admin.php` 里配置账号密码（见上一步第 4 条）。

后台能做什么：

| 页面 | 功能 |
| --- | --- |
| 订单管理 | 全部订单列表、按状态/关键词/日期筛选、分页、数据总览（订单数、今日新增、待支付、有效营业额）、订单详情、一键改状态 |
| 菜品管理 | 菜品列表与筛选、**上架/下架**、行内直接改库存、设为/取消推荐、新增与编辑、删除 |
| 账号与安全 | 只读页：当前管理员信息、**改密码的命令**（自动识别本机 PHP 路径，可直接复制）、配置文件位置、注意事项 |

> **上架/下架 vs 删除**：只是暂时不卖就用「下架」（小程序端立即看不到，数据与历史订单都保留）；确认不再经营才用「删除」。
> 删除菜品不会影响历史订单，因为 `order_items` 冗余存储了下单当时的菜名与单价。

### 3. 前端（微信开发者工具）

1. 打开 [微信开发者工具](https://developers.weixin.qq.com/miniprogram/dev/devtools/download.html)，导入 `frontend/` 目录。
2. 后端地址**不用改代码**：`frontend/utils/api.ts` 里按运行环境自动选（见 `API_BASE_BY_ENV`）。
   - **开发版**（开发者工具）默认用 `http://localhost`，开箱即用
   - **真机调试**时手机上的 `localhost` 指的是手机自己，需要改成电脑的局域网 IP，例如 `http://192.168.1.5`。
     不想改代码的话，在开发者工具控制台执行一句即可：
     ```js
     wx.setStorageSync('apiBaseUrl', 'http://192.168.1.5')   // 清掉：wx.removeStorageSync('apiBaseUrl')
     ```
   - **体验版 / 正式版**分别读 `trial` / `release` 两项配置，上线前填成你的测试与正式域名
3. 若使用手机预览，请勾选开发者工具「详情 → 本地设置 → 不校验合法域名」。

> 注意：本仓库未提交 `project.private.config.json`（微信开发者工具私有配置），clone 后首次打开会自动生成。

## 📚 文档导航

| 文档 | 说明 |
| --- | --- |
| [docs/README.md](docs/README.md) | 文档目录总览 |
| [docs/architecture.md](docs/architecture.md) | 系统架构、鉴权设计、订单状态机与并发一致性 |
| [docs/api.md](docs/api.md) | 全部 20 个接口的详细文档（含鉴权说明、AI 限流与 SSE 事件格式） |
| [docs/database.md](docs/database.md) | 数据库表结构设计 |
| [docs/deployment.md](docs/deployment.md) | 本地部署与上线指南 |
| [docs/git-guide.md](docs/git-guide.md) | Git/GitHub 仓库创建与上传指引 |

## 🔒 安全说明

- **接口鉴权**：除登录接口与公开数据接口外，所有接口都要求携带登录令牌；**用户身份一律取自令牌，客户端传的 `user_id` 会被忽略**，因此无法通过改参数操作他人购物车、订单或资料。资源类接口（改数量、删除购物车、订单支付/取消/完成）还会校验资源归属。
- **管理后台独立鉴权**：后台不复用小程序令牌，而是独立的会话登录（专用 Cookie `TWSESSID`，HttpOnly + SameSite=Lax + 仅 `/admin` 路径有效）。密码以 bcrypt 哈希存储，登录成功与退出时都会更换会话 ID（防会话固定攻击），连续失败 5 次锁定 5 分钟（按 IP 计数，清 Cookie 无法绕过）。
- **AI 接口限流**：`ai/chat.php`、`ai/chat_stream.php`、`dish/recommend.php` 每次调用都真实消耗 DeepSeek 额度，因此做了三层限流（身份每分钟、身份每天、全站每天总量）。**先自增再判断**，被拒绝的请求也计数，攻击者无法靠重试绕过；计数用 `INSERT ... ON DUPLICATE KEY UPDATE` 原子自增，并发下不会漏计。
- **AI 对话长度上限**：客户端传来的 `history` 会在服务端被清洗 —— **只放行 `user` / `assistant` 角色**（否则可以塞 `system` 消息覆盖「小味」的身份约束，属于提示词注入），并限制条数、单条长度与合计长度（第 N 轮要重发前 N-1 轮，成本是 O(N²)，不限制会让一次闲聊烧掉大量额度，而且迟早超出模型上下文窗口直接报错）。
- **后台写操作防护**：所有改变数据的操作一律 POST + 一次性 CSRF 令牌校验；后台页面输出全部经过 HTML 转义（防存储型 XSS —— 菜品名、订单备注都可能被塞入脚本）。
- **错误信息不泄露内部细节**：500 响应走 `Response::serverError()` —— 完整细节（含 SQL 报错、文件路径）**始终写入服务器错误日志**，但只对**本机回环地址**的请求回传；外部请求只得到一句通用提示。避免把表结构、列名这类信息告诉调用方。
- **敏感配置**：`backend/config/` 下的 `apikey.php`（DeepSeek 密钥）、`database.php`（数据库密码）、`security.php`（令牌签名密钥）、`wechat.php`（小程序 AppSecret）、`admin.php`（管理员账号）均已加入 `.gitignore` **不会上传**；仓库中提供对应的 `.example` 模板，克隆后自行填写。
- `frontend/project.private.config.json`（开发者工具私有配置）同样被忽略。
- `backend/phpMyAdmin4.8.5/` 为 phpstudy 自带的第三方数据库管理工具，不属于项目代码，已忽略不提交。
- ⚠️ **上线前仍需处理**：
  1. **修改管理后台密码**（`config/admin.php` 中的 `password_hash`，不要沿用初始密码）；
  2. 更稳妥的做法是**限制后台访问来源**（只允许内网 IP，或用 Apache/nginx 加一层 Basic 认证），别把 `/admin/` 直接暴露在公网；
  3. `config/security.php` 中 `allow_dev_openid_login` 必须改为 `false`（关掉本地调试通道）；
  4. `core/DeepSeek.php` 与 `core/WeChat.php` 中 `CURLOPT_SSL_VERIFYPEER` 恢复为 `true`；
  5. `Response.php` 中的 CORS `Access-Control-Allow-Origin: *` 收紧为具体域名；
  6. 订单支付接入微信支付（当前 `order/pay.php` 为模拟支付，详见 [docs/api.md](docs/api.md)）；
  7. **关闭 PHP 的错误回显**：`display_errors` 必须设为 `Off`、同时 `log_errors` 设为 `On`（本地 phpstudy 默认是 `display_errors=1`，上线后任何 PHP 警告都会把**文件绝对路径、SQL 片段**直接吐给客户端）；
  8. **按实际用户量重新评估 AI 限流阈值**（`core/AiRateLimit.php` 顶部常量，尤其是全站每日总量 —— 它直接决定一天最多花多少 AI 费用）；
  9. 按实际用户量评估**待支付订单超时时间**（`core/OrderExpiry.php` 的 `TIMEOUT_MINUTES`，默认 15 分钟）；
  10. 启用 HTTPS。

## 📄 License

本项目仅用于学习交流，请勿用于商业用途。

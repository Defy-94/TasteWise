# 🍽️ TasteWise 智味 · AI 点餐微信小程序

一个集 **智能推荐** 与 **AI 客服** 于一体的微信点餐小程序，前端使用微信小程序原生框架（TypeScript），后端使用 PHP + MySQL，并深度集成 DeepSeek 大模型，实现「AI 推荐菜品」「AI 点餐助手对话」等特色功能。

> 项目英文名：TasteWise · 中文名：智味 · 小程序内 AI 助手昵称：小味

---

## ✨ 功能特性

| 模块 | 功能说明 |
| --- | --- |
| 🏠 首页 | 菜品分类导航、菜品列表展示（价格/辣度/图片/描述）、AI 推荐入口、一键加入购物车 |
| 🛒 购物车 | 购物车列表、加入/移除商品、数量累加、结算下单（含库存校验与扣减） |
| 📦 订单系统 | 下单（事务保证）、**订单状态流转**（待支付 → 已支付 → 已完成 / 已取消）、取消自动回滚库存、订单列表与状态筛选 |
| 🤖 AI 助手 | 与 DeepSeek 大模型实时对话，动态注入当日菜单防止编造菜品，推荐结果自动生成可点击菜品卡片 |
| 👤 用户中心 | **微信一键登录**（首次登录自动建号，无需注册）、个人信息编辑（昵称/手机/地址）、我的订单 |
| 🔐 登录鉴权 | 微信 `code2Session` 换取 openid + HMAC-SHA256 登录令牌，**防越权、防账号伪造** |

## 🧰 技术栈

- **前端**：微信小程序原生框架（WXML / WXSS / TypeScript）、`miniprogram-api-typings`
- **后端**：PHP 7.3+（原生 PHP，无框架）、PDO 预处理防注入
- **数据库**：MySQL 5.7+（utf8mb4）
- **鉴权**：HMAC-SHA256 签名的自包含登录令牌（有效期 7 天，服务端免存储）
- **AI 能力**：DeepSeek API（`deepseek-chat` 模型，支持切换 `deepseek-reasoner`）
- **本地环境**：phpstudy_pro（Apache + MySQL + PHP）

## 📁 项目结构

```
TasteWise/
├── frontend/                  # 微信小程序前端
│   ├── pages/                 # 页面（7 个）
│   │   ├── index/             #   首页：分类 + 菜品 + AI推荐
│   │   ├── cart/              #   购物车
│   │   ├── aiChat/            #   AI 助手对话
│   │   ├── userCenter/        #   用户中心
│   │   ├── myOrders/          #   我的订单（含支付/取消/完成）
│   │   ├── login/             #   微信一键登录
│   │   └── about/             #   关于
│   ├── utils/
│   │   ├── api.ts             #   HTTP 请求封装（BASE_URL 配置 + 令牌注入 + 401 处理）
│   │   └── types.ts           #   全局 TS 类型定义
│   ├── images/                #  TabBar 图标等静态资源
│   ├── app.ts / app.json / app.wxss
│   └── project.config.json    #   开发者工具项目配置
├── backend/                   # PHP 后端服务
│   ├── api/                   # RESTful 接口（17 个）
│   │   ├── category/          #   分类
│   │   ├── dish/              #   菜品 + AI 推荐
│   │   ├── cart/              #   购物车
│   │   ├── order/             #   订单（创建/列表/支付/取消/完成）
│   │   ├── user/              #   用户（含 ai/ 子目录）
│   │   └── ai/                #   AI 对话
│   ├── core/                  # 核心封装层
│   │   ├── DB.php             #   PDO 单例数据库操作类
│   │   ├── Response.php       #   统一 JSON 返回格式
│   │   ├── Auth.php           #   登录令牌签发与校验（鉴权入口）
│   │   └── DeepSeek.php       #   DeepSeek API 封装（提示词 + 动态菜单注入）
│   ├── config/                # 配置文件（⚠️ 含敏感信息，已被 .gitignore 忽略）
│   │   ├── apikey.php         #   ⚠️ DeepSeek API Key（不提交，见 apikey.example.php）
│   │   ├── database.php       #   ⚠️ 数据库连接（不提交，见 database.example.php）
│   │   └── security.php       #   ⚠️ 令牌签名密钥（不提交，见 security.example.php）
│   ├── sql/init.sql           #   建库建表 + 测试数据
│   └── error/                 #   HTTP 错误页
├── docs/                      # 📚 项目文档
└── README.md                  # 本文件
```

## 🚀 快速开始

### 1. 后端（PHP + MySQL）

1. 安装 [phpstudy_pro](https://www.xp.cn/)，启动 Apache 与 MySQL（PHP ≥ 7.3）。
2. 将 `backend/` 目录部署到站点根目录（如 `localhost/`，Apache 虚拟主机指向 `backend/`）。
3. 创建数据库并导入表结构与测试数据：
   ```sql
   -- 在 phpMyAdmin 或命令行执行
   source backend/sql/init.sql;
   ```
4. 复制并填写三个配置文件（**首次必做**）：
   ```bash
   cp backend/config/database.example.php backend/config/database.php
   cp backend/config/apikey.example.php   backend/config/apikey.php
   cp backend/config/security.example.php backend/config/security.php
   cp backend/config/wechat.example.php   backend/config/wechat.php
   ```
   - `database.php`：数据库连接（phpstudy 默认 `root/root`）
   - `apikey.php`：在 [DeepSeek 开放平台](https://platform.deepseek.com/) 申请 API Key（`sk-` 开头）
   - `security.php`：令牌签名密钥，用 `php -r "echo bin2hex(random_bytes(32));"` 生成一个随机值填进去
   - `wechat.php`：微信小程序 `appid` + `appsecret`（小程序后台「开发管理 → 开发设置」获取，**AppSecret 属机密，只能放服务端**）
5. 访问 `http://localhost/api/category/list.php`，返回 `{"code":200,...}` 即部署成功。

### 2. 前端（微信开发者工具）

1. 打开 [微信开发者工具](https://developers.weixin.qq.com/miniprogram/dev/devtools/download.html)，导入 `frontend/` 目录。
2. 在 `frontend/utils/api.ts` 中修改后端地址：
   ```ts
   const BASE_URL = 'http://localhost';   // 改成你的后端域名/IP
   ```
3. 若使用手机预览，请勾选开发者工具「详情 → 本地设置 → 不校验合法域名」。

> 注意：本仓库未提交 `project.private.config.json`（微信开发者工具私有配置），clone 后首次打开会自动生成。

## 📚 文档导航

| 文档 | 说明 |
| --- | --- |
| [docs/README.md](docs/README.md) | 文档目录总览 |
| [docs/architecture.md](docs/architecture.md) | 系统架构、鉴权设计与订单状态机 |
| [docs/api.md](docs/api.md) | 全部 17 个接口的详细文档（含鉴权说明） |
| [docs/database.md](docs/database.md) | 数据库表结构设计 |
| [docs/deployment.md](docs/deployment.md) | 本地部署与上线指南 |
| [docs/git-guide.md](docs/git-guide.md) | Git/GitHub 仓库创建与上传指引 |

## 🔒 安全说明

- **接口鉴权**：除登录注册与公开数据接口外，所有接口都要求携带登录令牌；**用户身份一律取自令牌，客户端传的 `user_id` 会被忽略**，因此无法通过改参数操作他人购物车、订单或资料。资源类接口（删除购物车、订单支付/取消/完成）还会校验资源归属。
- **敏感配置**：`backend/config/` 下的 `apikey.php`（DeepSeek 密钥）、`database.php`（数据库密码）、`security.php`（令牌签名密钥）均已加入 `.gitignore` **不会上传**；仓库中提供对应的 `.example` 模板，克隆后自行填写。
- `frontend/project.private.config.json`（开发者工具私有配置）同样被忽略。
- `backend/phpMyAdmin4.8.5/` 为 phpstudy 自带的第三方数据库管理工具，不属于项目代码，已忽略不提交。
- ⚠️ **上线前仍需处理**：
  1. `core/DeepSeek.php` 中 `CURLOPT_SSL_VERIFYPEER` 恢复为 `true`；
  2. `Response.php` 中的 CORS `Access-Control-Allow-Origin: *` 收紧为具体域名；
  3. 订单支付接入微信支付（当前 `order/pay.php` 为模拟支付，详见 [docs/api.md](docs/api.md)）；
  4. 启用 HTTPS。

## 📄 License

本项目仅用于学习交流，请勿用于商业用途。

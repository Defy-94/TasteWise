# 部署指南

本项目后端为纯 PHP 项目，无 Composer 依赖，使用 phpstudy_pro 即可一键运行。

## 一、本地开发环境搭建

### 1. 安装 phpstudy_pro

1. 下载 [phpstudy_pro](https://www.xp.cn/) 并安装。
2. 启动 **Apache** 与 **MySQL**。
3. 确认 PHP 版本 ≥ 7.3（面板「软件管理」中可切换；项目实际开发环境为 PHP 7.3.4）。

### 2. 部署后端代码

将 `backend/` 目录放入站点根目录。推荐两种方式：

- **方式 A（推荐）**：在 phpstudy 面板「网站 → 创建网站」中，把域名指向 `backend/` 目录（Apache 虚拟主机根目录），如 `http://localhost`。
- **方式 B**：直接把 `backend/` 下的内容放到默认站点目录 `phpstudy_pro/WWW/` 下。

> 后端目录中包含 `error/`（错误页）、`.htaccess`（Apache 配置入口）。`phpMyAdmin4.8.5/` 为面板自带工具，无需单独部署。

### 3. 初始化数据库

在 phpMyAdmin（或 MySQL 命令行）中执行建库脚本：

```sql
-- 完整执行 backend/sql/init.sql
SOURCE /path/to/backend/sql/init.sql;
```

脚本会自动创建 `wechat_order` 数据库、7 张表并插入测试数据
（`users` / `categories` / `dishes` / `cart` / `orders` / `order_items` / `user_id_counter`）。

> **已有数据的库不要执行这个脚本**：它会先 `DROP TABLE` 再重建，数据会被清空。
> 从旧版本升级上来的库只需要补一个字段，见 [database.md](database.md) 的「注意事项」。

### 4. 配置敏感信息

```bash
# 复制示例配置（首次必做）
cp backend/config/database.example.php backend/config/database.php
cp backend/config/apikey.example.php   backend/config/apikey.php
cp backend/config/security.example.php backend/config/security.php
cp backend/config/wechat.example.php   backend/config/wechat.php
cp backend/config/admin.example.php    backend/config/admin.php
```

编辑五个文件，填入真实值：

- `database.php`：本地 phpstudy 默认 `root/root`，端口 3306
- `apikey.php`：在 [DeepSeek 开放平台](https://platform.deepseek.com/) 注册并创建 API Key
- `security.php`：登录令牌签名密钥，生成一个随机值填入：
  ```bash
  php -r "echo bin2hex(random_bytes(32));"
  ```
  > 该密钥用于签发/校验登录令牌。修改它会让所有用户登录态立即失效（需重新登录）。
  >
  > 其中的 `allow_dev_openid_login` 是**本地调试开关**（默认 `false`）：
  > 未配置微信 AppSecret 时可临时开启，用调试账号登录；**线上必须保持 `false`**。
- `wechat.php`：微信小程序 `appid` + **`appsecret`**
  - AppSecret 位置：小程序后台 →「开发管理 → 开发设置 → 开发者ID」
  - ⚠️ AppSecret 是机密信息，只能放服务端；未填写时微信一键登录会返回 503，前端会提示"微信登录未配置"
- `admin.php`：管理后台账号
  - **最省事：双击项目根目录的 `修改后台密码.bat`**，按提示输入两次新密码即可。
    它会自己找到本机的 PHP，把哈希写回配置文件，并自动备份 + 回读校验。
  - 习惯命令行的话，等价做法是在项目根目录执行（**换任何机器都一样用**，不依赖固定路径）：
    ```bash
    # 把 php 换成你机器上执行 PHP 的方式 ——
    # Linux/macOS 通常是 php；Windows phpstudy 下是 php.exe 的完整路径，需加引号
    php backend/tools/admin_passwd.php
    ```
  - 两种方式都不需要手动生成、粘贴那串 60 位的哈希（也就不会出现抄错一位、
    或把含 `$` 的哈希丢进双引号里被 PHP 当变量解析这两类问题）。
  - 常用变体：
    ```bash
    php backend/tools/admin_passwd.php --show                # 只看当前账号
    php backend/tools/admin_passwd.php --username=新登录名     # 顺便改登录名
    php backend/tools/admin_passwd.php --help                # 全部用法
    ```
  - 备份文件叫 `admin.php.bak-年月日-时分秒`，已被 `.gitignore` 忽略（同样含旧哈希，别上传）。
  - 可选参数：`session_idle_timeout`（后台空闲超时秒数，默认 7200）、
    `max_login_attempts`（失败几次锁定，默认 5）、`lock_seconds`（锁定多少秒，默认 300）

### 5. 验证后端

浏览器访问 `http://localhost/api/category/list.php`，返回：

```json
{ "code": 200, "msg": "ok", "data": [ { "id": 1, "name": "热菜", "sort_order": 10, "icon_url": "" } ] }
```

### 6. 验证管理后台

浏览器打开 **`http://localhost/admin/`**，用 `config/admin.php` 里配置的账号登录。

Windows 下更省事的方式是**双击项目根目录的 `启动管理后台.bat`**：
它会先探测服务是否在运行（没启动会明确提示去开 phpstudy），再自动打开浏览器并显示账号信息。

后台三个页面：

| 页面 | 能做什么 |
| --- | --- |
| 订单管理 | 筛选（状态/关键词/日期）、分页、数据总览、订单详情、一键改状态（取消会自动恢复库存） |
| 菜品管理 | 筛选、**上架/下架**、行内改库存、设为/取消推荐、新增/编辑/删除 |
| 账号与安全 | 只读页：当前管理员信息、**改密码的两种方法**（双击 `修改后台密码.bat` / 命令行）、配置文件位置、注意事项 |

> **改密码不用去翻文档**：**双击项目根目录的 `修改后台密码.bat`** 就行；
> 登录后台后点顶部导航「账号与安全」，那一页也写着同样的方法。
> 改完立即生效、不用重启 Apache。

> **`/admin/` 必须能访问**：如果打开后是 404，检查 Apache 的站点根目录是否指向了 `backend/`
> （后台就在 `backend/admin/`，随 `backend/` 一起部署，不需要额外配置）。

### 7. 运行小程序前端

1. 下载安装 [微信开发者工具](https://developers.weixin.qq.com/miniprogram/dev/devtools/download.html)。
2. 使用自己的小程序 AppID 导入 `frontend/` 目录（仓库内保留的 AppID 可作开发调试，正式发布请替换为你自己的）。
3. 后端地址**按运行环境自动选择**（`frontend/utils/api.ts` 的 `API_BASE_BY_ENV`），不需要改代码：
   ```ts
   const API_BASE_BY_ENV = {
     develop: 'http://localhost',          // 开发者工具；真机调试改成电脑局域网 IP
     trial:   'http://localhost',          // 体验版
     release: 'https://api.example.com',   // 正式版（必须 https 且已在后台配成合法域名）
   };
   ```
   真机调试时手机上的 `localhost` 指向手机自己，要在控制台执行一句临时切换（不必改代码）：
   ```js
   wx.setStorageSync('apiBaseUrl', 'http://192.168.1.5')
   ```
4. 真机预览：勾选「详情 → 本地设置 → 不校验合法域名、web-view（业务域名）、TLS 版本以及 HTTPS 证书」。
5. 编译运行，即可在模拟器中体验完整流程。

## 二、上线部署要点

### 1. 后端上线

- 选择支持 PHP 7.3+ 的云服务器或虚拟主机，将 `backend/` 上传至站点目录。
- 创建生产数据库并执行 `init.sql`，更新 `config/database.php` 为线上数据库账号。
- 申请正式 DeepSeek API Key 填入 `config/apikey.php`，并重新生成 `config/security.php` 的令牌密钥（不要沿用本地密钥）。
- 在线上 `config/admin.php` 里配置**新的管理员密码**（不要沿用本地调试密码）。
- **让 AI 流式输出真正生效**：如果 Web 服务器是 Apache + `mod_fcgid`（phpstudy 就是），
  需要加一行 `FcgidOutputBufferSize 0` 再重启，否则响应会被缓冲到请求结束才发出
  （功能正常，但没有"逐字蹦出来"的效果）。nginx + php-fpm 默认不缓冲，无需配置。
- 调整 **AI 接口限流**：`core/AiRateLimit.php` 顶部的常量是按单店小程序设的，
  上线前按实际用户量重新评估，尤其是全站每日总量（默认 3000）—— 它直接决定
  一天最多花多少 AI 费用。
- 调整**待支付订单超时时间**：`core/OrderExpiry.php` 的 `TIMEOUT_MINUTES`（默认 15 分钟）。
  这个值决定了「顾客下单后不付款」时库存被占用多久。
- 安全加固（必须）：
  - **关闭 PHP 错误回显**：`php.ini` 里设 `display_errors = Off`、`log_errors = On`
    （本地 phpstudy 默认是 `display_errors=1`）。**开着它上线，任何 PHP 警告都会把
    文件绝对路径、SQL 片段直接吐给客户端** —— 这既是信息泄露，也帮攻击者省了探测成本；
  - **限制 `/admin/` 的访问来源**：只允许内网 IP，或用 Apache/nginx 给 `/admin/` 加一层 Basic 认证。
    PHP 代码层的会话登录与 CSRF 已经做了，但一个运营后台长期暴露在公网本身就是不必要的风险面；
  - `core/DeepSeek.php` 与 `core/WeChat.php` 中 `CURLOPT_SSL_VERIFYPEER` 由 `false` 改回 `true`；
  - 将 `core/Response.php` 中 CORS 的 `Access-Control-Allow-Origin: *` 收紧为具体域名；
  - `order/pay.php` 当前为模拟支付，上线前必须接入微信支付（订单状态由支付回调驱动）；
  - 确认 `config/security.php` 的 `allow_dev_openid_login` 为 `false`；
  - 全站启用 HTTPS。

### 2. 小程序上线

1. 微信公众平台注册小程序，获取正式 AppID 与 AppSecret，填入服务端 `config/wechat.php`。
   > 登录走的是微信官方 `code2Session` 流程（`core/WeChat.php`），openid 由微信签发，客户端无法伪造。
   > 上线前请确认 AppSecret 已配置，且 `allow_dev_openid_login` 已关闭。
2. 小程序后台配置服务器域名（request 合法域名必须为 HTTPS 且已备案）。
3. 微信开发者工具「上传」→ 后台提交审核 → 发布。

## 三、常见问题

| 问题 | 排查方法 |
| --- | --- |
| 接口返回 500 / 空白 | 查看 `config/database.php` 账号密码与 MySQL 是否启动 |
| 接口返回 401 | 未登录或令牌过期：重新登录；确认请求头带了 `token`；刚改过 `security.php` 密钥时需重新登录 |
| AI 回复报错 | 检查 `config/apikey.php` 的 Key 是否有效、是否欠费 |
| AI 回复提示「请求太频繁」或「今天的 AI 使用次数已用完」 | 触发了 AI 接口限流（见 [architecture.md](architecture.md) 第 10 节）。限额常量在 `core/AiRateLimit.php` 顶部，本地调试嫌严可以调大；计数表是 `ai_rate_limit`，清空该表可立即重置额度 |
| 流式输出没有「逐字」效果，而是等几秒整段蹦出来 | **Apache + mod_fcgid 的响应缓冲导致的**，不是代码问题。在 Apache 配置里加 `FcgidOutputBufferSize 0` 并重启 Apache。详见 [architecture.md](architecture.md) 第 11 节（phpstudy 用的是 mod_fcgid，默认缓冲 64KB） |
| 流式输出的中文变成乱码 | 检查是否有人改坏了 `frontend/utils/utf8.ts`（它负责把跨分块的半个汉字拼回来），或服务端是否被中间层二次压缩 |
| 待支付订单过一会儿自己变成「已取消」 | **这是设计行为，不是 bug**：下单会立即扣库存，若不付款也不取消，那份库存会被永久占用。所以待支付超过 `core/OrderExpiry.php` 的 `TIMEOUT_MINUTES`（默认 15 分钟）会自动取消并把库存还回去，备注里会留下「（超时未支付，系统自动取消）」。后台订单管理页有「立刻清理」按钮可手动触发 |
| 库存数量和预期对不上 | 按顺序排查：① 是否有超时未支付订单被自动取消后又下单（属正常）；② 后台菜品页可以直接看/改库存，确认没被人手动改过；③ 用「订单明细合计」对账 —— 后台订单详情页会自动比对明细合计与订单金额，不一致会给出警告 |
| 小程序请求失败 | 确认 `BASE_URL` 可达；真机调试需关闭域名校验或配置合法域名 |
| 注册报错「表不存在」 | 未执行过 `backend/sql/init.sql`（该脚本已包含 `user_id_counter` 表），请检查库结构 |
| 后台打开是 404 | 站点根目录没指向 `backend/`；后台在 `backend/admin/`，确认 `http://localhost/admin/login.php` 能打开 |
| 后台登录提示「缺少配置文件」 | 没建 `backend/config/admin.php`，按 [第 4 步](#4-配置敏感信息) 复制模板并填写 |
| 后台登录提示「用户名或密码错误」 | 密码不对。用 `php backend/tools/admin_passwd.php --show` 确认当前账号名，或直接重设密码 |
| 后台登录提示「失败次数过多」 | 连续输错 5 次会锁定 5 分钟（按 IP 计数）。等待即可，或调小 `config/admin.php` 的 `max_login_attempts` 便于调试 |
| 后台登录提示「页面已过期」 | 会话过期或浏览器禁用了 Cookie；刷新登录页重新提交。若浏览器禁用 Cookie，后台无法登录 |
| 忘记后台密码 | 密码是不可逆哈希，找不回来。**双击项目根目录的 `修改后台密码.bat`** 重设一个即可 —— **不需要旧密码，也不需要能登录后台** |
| `修改后台密码.bat` 说找不到 php.exe | 双击后在提示里直接粘上 php.exe 的完整路径（phpstudy 一般在 `...\phpstudy_pro\Extensions\php\版本号\php.exe`）；或改用命令行执行 `php backend/tools/admin_passwd.php` |
| 换电脑后后台进不去 | `config/admin.php` 被 `.gitignore` 忽略，不会随仓库同步。新环境要按 [第 4 步](#4-配置敏感信息) 重新建这个文件 |
| 下架的菜在小程序里还能看到 | 检查是否命中缓存：退出小程序重新进入；菜品列表、详情、AI 推荐均已按 `is_on_sale` 过滤 |

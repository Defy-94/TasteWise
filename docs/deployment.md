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
3. 修改 `frontend/utils/api.ts` 中的 `BASE_URL` 为后端地址：
   ```ts
   const BASE_URL = 'http://localhost';   // 本地调试；真机预览改为局域网 IP 或线上域名
   ```
4. 真机预览：勾选「详情 → 本地设置 → 不校验合法域名、web-view（业务域名）、TLS 版本以及 HTTPS 证书」。
5. 编译运行，即可在模拟器中体验完整流程。

## 二、上线部署要点

### 1. 后端上线

- 选择支持 PHP 7.3+ 的云服务器或虚拟主机，将 `backend/` 上传至站点目录。
- 创建生产数据库并执行 `init.sql`，更新 `config/database.php` 为线上数据库账号。
- 申请正式 DeepSeek API Key 填入 `config/apikey.php`，并重新生成 `config/security.php` 的令牌密钥（不要沿用本地密钥）。
- 在线上 `config/admin.php` 里配置**新的管理员密码**（不要沿用本地调试密码）。
- 安全加固（必须）：
  - **限制 `/admin/` 的访问来源**：只允许内网 IP，或用 Apache/nginx 给 `/admin/` 加一层 Basic 认证。
    PHP 代码层的会话登录与 CSRF 已经做了，但一个运营后台长期暴露在公网本身就是不必要的风险面；
  - `core/DeepSeek.php` 与 `core/WeChat.php` 中 `CURLOPT_SSL_VERIFYPEER` 由 `false` 改回 `true`；
  - 将 `core/Response.php` 中 CORS 的 `Access-Control-Allow-Origin: *` 收紧为具体域名；
  - `order/pay.php` 当前为模拟支付，上线前必须接入微信支付（订单状态由支付回调驱动）；
  - 为 AI 接口（`ai/chat.php`、`dish/recommend.php`）增加限流，防止 Key 被刷产生费用；
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

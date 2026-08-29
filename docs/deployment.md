# 部署指南

本项目后端为纯 PHP 项目，无 Composer 依赖，使用 phpstudy_pro 即可一键运行。

## 一、本地开发环境搭建

### 1. 安装 phpstudy_pro

1. 下载 [phpstudy_pro](https://www.xp.cn/) 并安装。
2. 启动 **Apache** 与 **MySQL**。
3. 确认 PHP 版本 ≥ 7.4（面板「软件管理」中可切换）。

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

脚本会自动创建 `wechat_order` 数据库、6 张表并插入测试数据。

### 4. 配置敏感信息

```bash
# 复制示例配置（首次必做）
cp backend/config/database.example.php backend/config/database.php
cp backend/config/apikey.example.php   backend/config/apikey.php
```

编辑两个文件，填入真实值：

- `database.php`：本地 phpstudy 默认 `root/root`，端口 3306
- `apikey.php`：在 [DeepSeek 开放平台](https://platform.deepseek.com/) 注册并创建 API Key

### 5. 验证后端

浏览器访问 `http://localhost/api/category/list.php`，返回：

```json
{ "code": 200, "msg": "ok", "data": [ { "id": 1, "name": "热菜", "sort_order": 10, "icon_url": "" } ] }
```

### 6. 运行小程序前端

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

- 选择支持 PHP 7.4+ 的云服务器或虚拟主机，将 `backend/` 上传至站点目录。
- 创建生产数据库并执行 `init.sql`，更新 `config/database.php` 为线上数据库账号。
- 申请正式 DeepSeek API Key 填入 `config/apikey.php`。
- 安全加固（必须）：
  - `core/DeepSeek.php` 中 `CURLOPT_SSL_VERIFYPEER` 由 `false` 改回 `true`；
  - 为接口增加 token 鉴权（当前接口仅依赖 user_id，存在越权风险）；
  - 全站启用 HTTPS。

### 2. 小程序上线

1. 微信公众平台注册小程序，获取正式 AppID 与 AppSecret。
2. 后端 `api/user/login.php` 目前为演示实现（直接传 openid），上线前应改为调用微信 `code2Session` 接口用 code 换取 openid。
3. 小程序后台配置服务器域名（request 合法域名必须为 HTTPS 且已备案）。
4. 微信开发者工具「上传」→ 后台提交审核 → 发布。

## 三、常见问题

| 问题 | 排查方法 |
| --- | --- |
| 接口返回 500 / 空白 | 查看 `config/database.php` 账号密码与 MySQL 是否启动 |
| AI 回复报错 | 检查 `config/apikey.php` 的 Key 是否有效、是否欠费 |
| 小程序请求失败 | 确认 `BASE_URL` 可达；真机调试需关闭域名校验或配置合法域名 |
| 注册报错「表不存在」 | 数据库未创建 `user_id_counter` 表，见 [database.md](database.md) 已知问题 |

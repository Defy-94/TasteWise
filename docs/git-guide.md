# Git / GitHub 上传指南

本文说明如何把本项目上传到 GitHub（或 Gitee），以及**哪些敏感文件不会上传**。

## 一、仓库已做的安全处理

项目根目录的 `.gitignore` 已忽略以下内容（**不会上传到远程仓库**）：

| 忽略项 | 原因 |
| --- | --- |
| `backend/config/apikey.php` | ⚠️ 包含 DeepSeek API Key（真实密钥） |
| `backend/config/database.php` | ⚠️ 包含数据库账号密码 |
| `backend/config/security.php` | ⚠️ 包含登录令牌签名密钥 |
| `backend/config/wechat.php` | ⚠️ 包含微信小程序 AppSecret |
| `backend/config/admin.php` | ⚠️ 包含管理后台账号与密码哈希 |
| `backend/phpMyAdmin4.8.5/` | 第三方数据库管理工具（phpstudy 自带），不属于项目代码 |
| `frontend/project.private.config.json` | 微信开发者工具私有配置 |
| `node_modules/`、IDE 配置、系统文件等 | 常规忽略 |

仓库中提供 `backend/config/` 下五个 `.example.php` 模板
（`apikey` / `database` / `security` / `wechat` / `admin`），
克隆后复制为对应真实配置文件并填写即可运行。

## 二、上传前需要准备的信息（清单）

在 GitHub 上创建仓库时需要以下信息：

| 信息 | 说明 | 本项目建议值 |
| --- | --- | --- |
| 1. GitHub 账号 | 注册 https://github.com | Defy-94（与 git 全局用户名一致） |
| 2. 仓库名称 | 项目英文名 | `TasteWise` |
| 3. 仓库描述 | 一句话介绍 | AI 点餐微信小程序（微信小程序 + PHP + MySQL + DeepSeek） |
| 4. 可见性 | Public / Private | 按需选择；含业务代码建议 Private |
| 5. 是否初始化 README | 仓库内已有 README | **不要勾选**（避免冲突），Git 会冲突需 merge |
| 6. 是否添加 .gitignore | 仓库内已有 | **不要勾选** |
| 7. 是否添加 License | 按需 | 可不选 |

本地 Git 身份（已配置，无需重复设置，可用 `git config --global user.name` / `git config --global user.email` 查看）：

```text
user.name  = Defy-94
user.email = 2296649222@qq.com
```

## 三、创建 GitHub 远程仓库（网页方式）

1. 登录 GitHub → 右上角 `+` → **New repository**。
2. 仓库名填 `TasteWise`，按上表填写描述与可见性。
3. **保持所有初始化选项不勾选**（不要勾 Add README / .gitignore / license），直接点 **Create repository**。
4. 创建完成后，页面会显示仓库地址，形如：
   ```
   https://github.com/Defy-94/TasteWise.git
   ```

## 四、本地仓库推送（3 条命令）

在项目根目录 `E:\TasteWise` 打开终端（PowerShell / Git Bash），执行：

```bash
# 1. 关联远程仓库（把 URL 换成你实际的仓库地址）
git remote add origin https://github.com/Defy-94/TasteWise.git

# 2. 推送（首次推送需要 GitHub 登录；Windows 会弹出凭据管理器）
git push -u origin main
```

> 如果本地默认分支是 `master`，可先 `git branch -M main` 再推送。

首次推送时若提示输入账号密码，请使用 **Personal Access Token（PAT）** 作为密码（GitHub 已不支持账号密码推送）：

1. GitHub → 头像 → **Settings → Developer settings → Personal access tokens → Tokens (classic) → Generate new token**。
2. 勾选 `repo` 权限，生成形如 `ghp_xxxx` 的 Token。
3. 推送时用户名填 GitHub 用户名，密码粘贴该 Token。
4. 也可在 Windows 凭据管理器（`control keymgr.dll`）中预先保存。

## 五、验证与后续维护

```bash
git status          # 工作区干净
git log --oneline   # 查看提交历史
git push            # 后续提交直接推送
```

## 六、常见问题

| 问题 | 解决 |
| --- | --- |
| 推送被拒（remote contains work） | 远程仓库被勾选了初始化文件，执行 `git pull origin main --allow-unrelated-histories` 后重新推送 |
| 提示输入密码 | 使用 PAT 代替密码，见上文 |
| 忘记忽略某敏感文件 | 立即追加到 `.gitignore`，并用 `git rm --cached <file>` 从索引移除（已提交的历史需重写，慎重） |
| 想换 Gitee（码云） | 在 Gitee 创建同名仓库，把 `git remote set-url origin https://gitee.com/<用户名>/TasteWise.git` 后重新推送 |

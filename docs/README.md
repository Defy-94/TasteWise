# 📚 TasteWise 项目文档

本项目是「智味」AI 点餐微信小程序（TasteWise）的完整工程文档。前端为微信小程序（TypeScript），后端为 PHP + MySQL，并集成 DeepSeek 大模型实现 AI 推荐与 AI 客服。

## 文档索引

| 文档 | 内容 | 适用读者 |
| --- | --- | --- |
| [architecture.md](architecture.md) | 系统总体架构、模块划分、请求流程、订单状态机、鉴权设计、并发一致性、**管理后台设计** | 架构师 / 新成员入门 |
| [api.md](api.md) | 全部 19 个后端接口：方法、地址、入参、出参、示例 | 前端开发者 / 联调 |
| [database.md](database.md) | 数据库表结构、字段说明、ER 关系、注意事项 | 后端开发者 |
| [deployment.md](deployment.md) | 本地环境搭建（phpstudy）、数据库导入、**管理后台配置**、上线部署 | 部署 / 运维 |
| [git-guide.md](git-guide.md) | Git 仓库创建、敏感文件说明、上传 GitHub 全流程 | 所有人 |

## 快速导航

- 项目总说明见根目录 [README.md](../README.md)
- 前端代码位于 [`frontend/`](../frontend)
- 后端代码位于 [`backend/`](../backend)
- 管理后台位于 [`backend/admin/`](../backend/admin)，浏览器打开 `http://localhost/admin/`
- Windows 下双击根目录 `启动管理后台.bat` 可一键打开后台

## 约定与规范

- **接口返回格式**：统一为 `{"code":200,"msg":"ok","data":...}`，见 [api.md](api.md) 与 `backend/core/Response.php`
- **敏感信息**：`backend/config/*.php` 真实配置不上传 Git，请使用 `.example` 模板复制填写
- **订单状态流转**：唯一实现在 `backend/core/OrderStatus.php`，接口层与管理后台都调用它，禁止另写一份
- **菜品可见性**：`dishes.is_on_sale` 决定小程序端是否可见，所有面向小程序端的菜品查询都必须过滤它
- **编码**：全部源码统一 UTF-8 编码

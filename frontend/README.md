# 微信点餐小程序 - 后端

基于 PHP + MySQL 的微信点餐小程序后端服务，集成 DeepSeek AI 实现智能推荐与客服。

## 技术栈

- **语言**：PHP 7.4+
- **数据库**：MySQL 5.7+
- **AI**：DeepSeek API
- **环境**：phpstudy_pro

## 目录结构

localhost/
**config/ # 配置文件**
  **database.php # 数据库连接配置**
  **apikey.php # API密钥**
**core/ # 核心封装层**
  **DB.php # PDO数据库操作类**
  **Response.php # 统一JSON返回格式**
  **DeepSeek.php # DeepSeek API封装**
**api/ # RESTful API接口**
  **category/ # 菜品分类**
  **dish/ # 菜品管理 + AI推荐**
  **cart/ # 购物车**
  **order/ # 订单**
  **user/ # 用户**
  **ai/ # AI智能对话**
**sql/ # 数据库脚本**

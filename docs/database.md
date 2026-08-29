# 数据库设计

- 数据库名：`wechat_order`
- 字符集：`utf8mb4` / `utf8mb4_unicode_ci`
- 初始化脚本：`backend/sql/init.sql`（建库 + 建表 + 测试数据）
- 访问方式：全部通过 `backend/core/DB.php`（PDO 单例）操作，禁止直接写连接

## 表结构

### 1. users（用户表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 用户 ID |
| openid | VARCHAR(100) UNIQUE | 微信 openid |
| nickname | VARCHAR(50) | 昵称（默认「微信用户」） |
| avatar_url | VARCHAR(500) | 头像 URL |
| phone | VARCHAR(20) | 手机号 |
| address | VARCHAR(200) | 默认地址 |
| created_at / updated_at | DATETIME | 创建 / 更新时间 |
| extra_field1 / extra_field2 | VARCHAR(200) | 预留扩展字段 |

> ⚠️ 代码（`register.php` / `check.php` / `login_phone.php`）中还使用了 `username` 字段，但 `init.sql` 未定义，属已知问题，见文末。

### 2. categories（菜品分类表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 分类 ID |
| name | VARCHAR(50) | 分类名称 |
| sort_order | INT | 排序权重（越大越靠前） |
| icon_url | VARCHAR(500) | 分类图标 |
| extra_field1 / extra_field2 | VARCHAR(200) | 预留扩展字段 |

### 3. dishes（菜品表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 菜品 ID |
| category_id | INT UNSIGNED FK | 所属分类（级联删除） |
| name | VARCHAR(100) | 菜品名称 |
| price | DECIMAL(10,2) | 价格 |
| image_url | VARCHAR(500) | 菜品图片 |
| description | VARCHAR(500) | 描述 |
| spiciness | TINYINT | 辣度 0-5 |
| is_recommend | TINYINT | 是否推荐 0/1 |
| stock | INT | 库存（默认 999） |
| extra_field1 / extra_field2 | VARCHAR(200) | 预留扩展字段 |

### 4. cart（购物车表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 记录 ID |
| user_id | INT UNSIGNED FK | 用户（级联删除） |
| dish_id | INT UNSIGNED FK | 菜品（级联删除） |
| quantity | INT | 数量 |
| created_at | DATETIME | 加入时间 |
| extra_field1 / extra_field2 | VARCHAR(200) | 预留扩展字段 |

### 5. orders（订单表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 订单 ID |
| order_no | VARCHAR(32) UNIQUE | 订单编号 |
| user_id | INT UNSIGNED FK | 用户（级联删除） |
| total_price | DECIMAL(10,2) | 订单总金额 |
| status | TINYINT | 0待支付 / 1已支付 / 2已完成 / 3已取消 |
| remark | VARCHAR(200) | 备注 |
| created_at / updated_at | DATETIME | 创建 / 更新时间 |
| extra_field1 / extra_field2 | VARCHAR(200) | 预留扩展字段 |

### 6. order_items（订单明细表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 明细 ID |
| order_id | INT UNSIGNED FK | 订单（级联删除） |
| dish_id | INT UNSIGNED | 菜品 ID（仅记录，无外键） |
| dish_name | VARCHAR(100) | 菜品名称（冗余，防止菜品删除后订单失真） |
| dish_price | DECIMAL(10,2) | 单价（冗余） |
| quantity | INT | 数量 |
| extra_field1 / extra_field2 | VARCHAR(200) | 预留扩展字段 |

## 关系图

```
users 1 ──── * cart  * ──── 1 dishes
  │                          │
  │                          └── * ──── 1 categories
  └── 1 ──── * orders 1 ──── * order_items
```

- `dishes.category_id → categories.id`
- `cart.user_id → users.id`，`cart.dish_id → dishes.id`
- `orders.user_id → users.id`
- `order_items.order_id → orders.id`（`dish_id` 仅作记录，无外键约束）

## 测试数据（init.sql）

- 分类 5 个：热菜 / 凉菜 / 汤品 / 主食 / 饮品
- 菜品 10 道：宫保鸡丁、麻婆豆腐、糖醋里脊、凉拌黄瓜、皮蛋豆腐、番茄蛋汤、酸辣汤、蛋炒饭、阳春面、冰镇柠檬水

## ⚠️ 已知问题

1. **`user_id_counter` 表缺失**：`register.php` 依赖 `user_id_counter` 表生成用户 ID（从 10001 递增），但 `init.sql` 未创建该表，首次注册会报错。修复方式（任选）：
   - 在 `init.sql` 中补充建表语句：
     ```sql
     DROP TABLE IF EXISTS `user_id_counter`;
     CREATE TABLE `user_id_counter` (
         `counter_name` VARCHAR(50) PRIMARY KEY,
         `current_value` INT NOT NULL DEFAULT 10000
     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户ID计数器';
     INSERT INTO `user_id_counter` (`counter_name`, `current_value`) VALUES ('user_id', 10000);
     ```
   - 或改回 `users.id` 的自增机制，去掉计数器逻辑。
2. **`users.username` 字段缺失**：`register.php` 插入、`check.php` / `login_phone.php` 查询均引用 `username`，但 `init.sql` 的 `users` 表未定义该字段。可在 `users` 表中补充：
   ```sql
   ALTER TABLE `users` ADD COLUMN `username` VARCHAR(50) DEFAULT '' COMMENT '用户名' AFTER `openid`;
   ```
3. **`extra_field1/2` 冗余**：各表均预留了扩展字段，正式项目可移除。

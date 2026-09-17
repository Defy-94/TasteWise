# 数据库设计

- 数据库名：`wechat_order`
- 字符集：`utf8mb4` / `utf8mb4_unicode_ci`
- 初始化脚本：`backend/sql/init.sql`（建库 + 建表 + 演示数据）
- 访问方式：全部通过 `backend/core/DB.php`（PDO 单例）操作，禁止直接写连接
- ✅ **本脚本已与项目实际运行库结构同步**（7 张表逐字段校验通过，仅 `orders.status` 的默认值/注释按下文说明修正）

## 表结构

### 1. users（用户表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 用户 ID |
| openid | VARCHAR(100) | 微信 openid |
| username | VARCHAR(50) | 用户名（默认空串） |
| nickname | VARCHAR(50) | 昵称（默认「微信用户」） |
| avatar_url | VARCHAR(500) | 头像 URL |
| phone | VARCHAR(20) | 手机号（默认空串） |
| address | VARCHAR(200) | 默认地址 |
| created_at / updated_at | DATETIME | 创建 / 更新时间 |
| extra_field1 / extra_field2 | VARCHAR(200) | 预留扩展字段 |

**唯一索引**：`openid`、`idx_username`(username)、`idx_phone`(phone)，另有普通索引 `idx_openid`。

> ⚠️ 因为 `username` 与 `phone` 都有唯一索引、且列默认值是空字符串，
> **插入用户时必须显式赋值**，否则第二个新用户会因 `Duplicate entry '' for key 'idx_phone'` 插入失败。
> 约定：`username` 由 openid 派生（如 `wx_` + md5 前 8 位），`phone` 未填写时写 **`NULL`**
> （MySQL 唯一索引允许多个 NULL，不会互相冲突）。`login.php` 已按此规则实现。

### 2. categories（菜品分类表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 分类 ID |
| name | VARCHAR(50) | 分类名称 |
| sort_order | INT | 排序权重（越大越靠前） |
| icon_url | VARCHAR(500) | 分类图标 |
| extra_field1 / extra_field2 | VARCHAR(200) | 预留扩展字段 |

索引：`idx_sort`(sort_order)

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

索引：`idx_category`(category_id)；外键 `category_id → categories.id ON DELETE CASCADE`

### 4. cart（购物车表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 记录 ID |
| user_id | INT UNSIGNED FK | 用户（级联删除） |
| dish_id | INT UNSIGNED FK | 菜品（级联删除） |
| quantity | INT | 数量（默认 1） |
| created_at | DATETIME | 加入时间 |
| extra_field1 / extra_field2 | VARCHAR(200) | 预留扩展字段 |

索引：`idx_user`(user_id)、`dish_id`(dish_id)；外键 `user_id → users.id`、`dish_id → dishes.id`（均级联删除）

### 5. orders（订单表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 订单 ID |
| order_no | VARCHAR(32) UNIQUE | 订单编号 |
| user_id | INT UNSIGNED FK | 用户（级联删除） |
| total_price | DECIMAL(10,2) | 订单总金额 |
| status | **VARCHAR(20)** | 订单状态，取值为数字字符串 `'0'` `'1'` `'2'` `'3'`（默认 `'0'`） |
| remark | VARCHAR(200) | 备注 |
| created_at / updated_at | DATETIME | 创建 / 更新时间 |
| extra_field1 / extra_field2 | VARCHAR(200) | 预留扩展字段 |

索引：`order_no` UNIQUE、`idx_user`、`idx_order_no`、`idx_user_id`；外键 `user_id → users.id ON DELETE CASCADE`

**状态取值**：`0` 待支付 / `1` 已支付 / `2` 已完成 / `3` 已取消（状态流转见 [api.md](api.md)）

> ⚠️ 该列类型是 `VARCHAR(20)`（不是 `TINYINT`），历史注释曾约定为 `pending/paid/completed/cancelled` 字符串。
> 当前代码统一写入 **数字 0-3**；后端读取时会做兼容归一化（数字与旧字符串写法都能识别），
> 但**请勿手工写入其他字符串值**，以免状态判断出错。

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

索引：`idx_order`(order_id)；外键 `order_id → orders.id ON DELETE CASCADE`

### 7. user_id_counter（用户ID计数器表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 自增主键 |
| counter_name | VARCHAR(50) UNIQUE | 计数器名称（固定 `user_id`） |
| current_value | INT UNSIGNED | 当前值（初始 10000） |
| updated_at | DATETIME | 更新时间 |

- 注册时 `register.php` 用 `SELECT ... FOR UPDATE` 加行锁读取该值并 +1，从而分配从 **10001** 起的用户 ID（防并发重复）。
- 初始数据：`('user_id', 10000)`，因此第一个注册用户的 ID 为 10001。

## 关系图

```
users 1 ──── * cart  * ──── 1 dishes
  │                          │
  │                          └── * ──── 1 categories
  └── 1 ──── * orders 1 ──── * order_items

user_id_counter ──（注册时分配 users.id，无外键）
```

- `dishes.category_id → categories.id`
- `cart.user_id → users.id`，`cart.dish_id → dishes.id`
- `orders.user_id → users.id`
- `order_items.order_id → orders.id`（`dish_id` 仅作记录，无外键约束）

## 演示数据（init.sql）

- 分类 5 个：热菜 / 凉菜 / 汤品 / 主食 / 饮品
- 菜品 **20 道**（含图片 URL 与描述，库存均为 999）：
  宫保鸡丁、麻婆豆腐、糖醋里脊、凉拌黄瓜、皮蛋豆腐、番茄蛋汤、酸辣汤、蛋炒饭、阳春面、冰镇柠檬水、
  鱼香肉丝、红烧茄子、口水鸡、凉拌木耳、玉米排骨汤、紫菜蛋花汤、牛肉炒饭、葱油拌面、酸梅汤、鲜榨西瓜汁
- 用户ID计数器 1 条：`('user_id', 10000)`

> 脚本**不包含**任何用户、订单、购物车数据（那是运行时业务数据，不应作为种子数据分发）。

## 注意事项

1. **`extra_field1/2` 冗余**：各表均预留了扩展字段，正式项目可移除。
2. **`init.sql` 会 DROP 重建所有表**：仅在全新环境初始化时执行，已有数据的库请勿直接运行。
3. **不要手工改 `orders.status` 的定义**：代码按数字 0-3 判断状态。

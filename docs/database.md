# 数据库设计

- 数据库名：`wechat_order`
- 字符集：`utf8mb4` / `utf8mb4_unicode_ci`
- 初始化脚本：`backend/sql/init.sql`（建库 + 建表 + 演示数据）
- 访问方式：全部通过 `backend/core/DB.php`（PDO 单例）操作，禁止直接写连接
- ✅ **表结构以 `backend/sql/init.sql` 为准**（8 张表；字段与索引已按脚本核对）

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

**唯一索引**：`openid`、`idx_username`(username)、`idx_phone`(phone)。

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
| is_on_sale | TINYINT | **是否上架 1/0（默认 1）**，管理后台的「上架/下架」开关 |
| extra_field1 / extra_field2 | VARCHAR(200) | 预留扩展字段 |

索引：`idx_category`(category_id)；外键 `category_id → categories.id ON DELETE CASCADE`

> **`is_on_sale` 的作用范围**：下架菜品在**小程序端完全不可见** —— 菜品列表（`dish/list.php`）、
> 菜品详情（`dish/detail.php` 直接返回 404）、
> AI 对话的菜单注入（`DeepSeek::getDynamicMenu`）与卡片识别（`ai/chat.php`）都做了过滤，
> 加购（`cart/add.php`）与结算（`order/create.php`）也会拦截。
> 这样后台点一下下架，就不会出现「顾客还能看到并买到」的情况。
>
> **下架不影响历史订单**：`order_items` 冗余存了下单当时的菜名与单价，
> 所以下架（甚至删除）菜品都不会让历史订单金额或内容发生变化。
>
> **`is_on_sale` 不出现在小程序接口的返回值里**：它属于运营概念，小程序端只能看到上架菜品，
> 没有任何场景需要它，返回它只会让前端多出一个永远为 1 的字段。
>
> ⚠️ 该字段是后加的（`ALTER TABLE dishes ADD COLUMN is_on_sale TINYINT(4) NOT NULL DEFAULT '1' AFTER stock`），
> 老库升级时执行这条语句即可，已有菜品默认全部上架。

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

索引：`order_no` UNIQUE、`idx_user_id`(user_id)、**`idx_status_created`(status, created_at)**；外键 `user_id → users.id ON DELETE CASCADE`

> **`idx_status_created` 是给「超时未支付订单的懒清理」用的**：
> 那个清理要按 `status = '0' AND created_at < 某时刻` 筛选（见 `core/OrderExpiry.php`），
> 没有这个索引就是全表扫描；加上之后该查询走 `type=range`。
> 单用户维度的清理还能同时用上 `idx_user_id`(user_id)。

**状态取值**：`0` 待支付 / `1` 已支付 / `2` 已完成 / `3` 已取消（状态流转见 [api.md](api.md)）

> ⚠️ 该列类型是 `VARCHAR(20)`，历史注释曾约定为 `pending/paid/completed/cancelled` 字符串。
> 当前代码统一写入 **数字 0-3**；后端读取时由 `core/OrderStatus.php` 的 `normalize()` 做兼容归一化
> （数字与旧字符串写法都能识别），但**请勿手工写入其他字符串值**，以免状态判断出错。

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

索引：`idx_order`(order_id)、`idx_dish`(dish_id)；外键 `order_id → orders.id ON DELETE CASCADE`

### 7. user_id_counter（用户ID计数器表，历史遗留）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 自增主键 |
| counter_name | VARCHAR(50) UNIQUE | 计数器名称（固定 `user_id`） |
| current_value | INT UNSIGNED | 当前值（初始 10000） |
| updated_at | DATETIME | 更新时间 |

- 该表来自早期“注册时分配 10001 起用户 ID”的设计，**当前代码已不再使用它**。
- 现在 `user/login.php` 直接 `INSERT INTO users`，用户 ID 由 `users.id` 的 `AUTO_INCREMENT` 生成；按 `init.sql` 新建库时从 **1** 开始。
- 初始数据 `('user_id', 10000)` 仍保留在脚本中，但只是历史数据，不要依赖它分配 ID。

### 8. ai_rate_limit（AI 接口限流计数表）

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| id | INT UNSIGNED PK AI | 自增主键 |
| bucket_key | VARCHAR(120) | 限流标识：`uid:1` / `ip:1.2.3.4` / `global` |
| window_start | INT UNSIGNED | 窗口起点（Unix 时间戳，按窗口长度对齐） |
| hits | INT UNSIGNED | 本窗口内累计请求次数 |
| updated_at | DATETIME | 更新时间 |

索引：`uniq_bucket_window`(bucket_key, window_start) **唯一**、`idx_window`(window_start)

- **唯一索引是关键**：`INSERT ... ON DUPLICATE KEY UPDATE hits = hits + 1`
  靠它一条语句完成「不存在就插入、存在就自增」，
  换成「先 SELECT 判断再 UPDATE」在并发下会漏计数。
- **固定窗口计数**：window_start 按窗口长度向下取整对齐
  （分钟窗口都是整分钟、天窗口都是整天的零点，以 Unix 时间戳对齐，不依赖服务器时区）。
- **无外键**：刻意与业务表解耦，删用户/订单都不会牵动它，清理也简单。
- **过期行由代码定期清理**：`core/AiRateLimit.php` 里
  平均每 50 次请求触发一次删除，保留 2 天。
  之所以不做成 cron，是因为这个项目跑在 phpstudy 上，没有可靠的定时任务保证。

**用途**：`ai/chat.php`、`ai/chat_stream.php` 两个接口
每次调用都会真实消耗 DeepSeek 额度，限流是防止 API Key 被刷导致费用失控。
限额常量在 `core/AiRateLimit.php` 顶部，改限额不需要动数据库。

## 关系图

```
users 1 ──── * cart  * ──── 1 dishes
  │                          │
  │                          └── * ──── 1 categories
  └── 1 ──── * orders 1 ──── * order_items

user_id_counter（历史遗留表，当前未参与业务，无外键）
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
- 用户ID计数器 1 条：`('user_id', 10000)`（历史遗留，当前未使用）

> 脚本**不包含**任何用户、订单、购物车数据（那是运行时业务数据，不应作为种子数据分发）。

## 注意事项

1. **`extra_field1/2` 冗余**：各表均预留了扩展字段，正式项目可移除。
2. **`init.sql` 会 DROP 重建所有表**：仅在全新环境初始化时执行，已有数据的库请勿直接运行。
3. **不要手工改 `orders.status` 的定义**：代码按数字 0-3 判断状态。
4. **老库升级需要补 `dishes.is_on_sale`**：`init.sql` 是「全新初始化」脚本，不含 `ALTER`；
   已经跑着的库先确认字段不存在，再执行下面的 `ALTER`：
   ```sql
   -- 返回 0 说明还没有该字段
   SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = 'wechat_order'
      AND TABLE_NAME = 'dishes'
      AND COLUMN_NAME = 'is_on_sale';

   ALTER TABLE `dishes`
     ADD COLUMN `is_on_sale` tinyint(4) NOT NULL DEFAULT '1'
     COMMENT '是否上架(1上架0下架)' AFTER `stock`;
   ```
5. **后台没有独立的 admins 表**：管理员账号存在 `backend/config/admin.php`（已被 .gitignore 忽略）。
   单店、单运营角色的场景下用配置文件更简单，也避免为一个账号去动数据库结构。
   将来需要多管理员 / 角色权限 / 操作日志时，再改为表并补上外键关联。

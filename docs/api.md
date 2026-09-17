# API 接口文档

所有接口由 `backend/` 提供，统一返回 JSON 格式：

```json
{ "code": 200, "msg": "ok", "data": { ... } }
```

- `code = 200` 成功；非 200 失败（常见：400 参数错误 / 401 未登录 / 404 不存在 / 409 状态冲突 / 500 服务器错误）。
- 支持 CORS，`OPTIONS` 预检请求由 `Response::handleOptions()` 自动处理。
- 前端封装见 `frontend/utils/api.ts`，请求体均为 `Content-Type: application/json` 的 JSON 字符串。

---

## 鉴权说明（登录令牌）

除「免登录」接口外，所有涉及用户数据的接口都必须携带登录令牌。

### 1. 获取令牌

调用任一登录接口（`login.php` / `login_phone.php` / `register.php`），
返回的用户对象中会额外包含 `token` 字段：

```json
{
  "code": 200,
  "msg": "登录成功",
  "data": { "id": 10001, "openid": "...", "nickname": "...", "token": "eyJ1aWQiOjEwMDAxfQ.xxxx" }
}
```

### 2. 携带令牌

后续请求在请求头中携带（二选一，推荐第一种）：

```
token: <令牌>
```

```
Authorization: Bearer <令牌>
```

前端已统一处理：`frontend/utils/api.ts` 会自动把令牌加到每个请求头。

### 3. 令牌失效

令牌有效期 **7 天**。过期或非法时，后端返回：

```json
{ "code": 401, "msg": "未登录或登录已过期，请重新登录", "data": null }
```

前端收到 401 会自动清除本地登录态并跳转登录页。

### 4. 越权防护（重要）

**用户身份一律取自令牌，客户端传入的 `user_id` 会被完全忽略。**
因此无法通过修改请求参数查看、修改或删除他人数据（购物车、订单、个人资料）。
涉及具体资源的接口（如 `cart/remove.php`、`order/pay.php`）还会额外校验该资源是否属于当前用户。

> 令牌为 HMAC-SHA256 签名的自包含令牌，服务端不存储会话。
> 修改 `config/security.php` 中的 `token_secret` 会让所有已签发令牌立即失效。

---

## 接口总览（19 个）

| # | 方法 | 接口 | 功能 | 鉴权 |
| --- | --- | --- | --- | --- |
| 1 | GET | `/api/category/list.php` | 获取菜品分类 | 免登录 |
| 2 | GET | `/api/dish/list.php` | 获取菜品列表（可按分类筛选） | 免登录 |
| 3 | POST | `/api/dish/recommend.php` | AI 智能推荐菜品 | 免登录 |
| 4 | GET | `/api/cart/list.php` | 获取购物车列表 | 🔒 需令牌 |
| 5 | POST | `/api/cart/add.php` | 加入购物车 | 🔒 需令牌 |
| 6 | POST | `/api/cart/remove.php` | 移除购物车商品 | 🔒 需令牌 |
| 7 | POST | `/api/order/create.php` | 创建订单（结算） | 🔒 需令牌 |
| 8 | GET | `/api/order/list.php` | 查询我的订单 | 🔒 需令牌 |
| 9 | POST | `/api/order/pay.php` | **订单支付**（新增） | 🔒 需令牌 |
| 10 | POST | `/api/order/cancel.php` | **取消订单**（新增） | 🔒 需令牌 |
| 11 | POST | `/api/order/complete.php` | **确认订单完成**（新增） | 🔒 需令牌 |
| 12 | POST | `/api/user/login.php` | 微信 openid 登录 | 免登录（入口） |
| 13 | POST | `/api/user/login_phone.php` | 手机号登录 | 免登录（入口） |
| 14 | POST | `/api/user/register.php` | 用户注册 | 免登录（入口） |
| 15 | GET | `/api/user/check.php` | 校验登录态 / 获取当前用户 | 🔒 需令牌 |
| 16 | POST | `/api/user/update.php` | 修改个人信息 | 🔒 需令牌 |
| 17 | POST | `/api/user/send_sms.php` | 发送短信验证码（预留） | 免登录 |
| 18 | POST | `/api/ai/chat.php` | AI 智能问答（含推荐菜品提取） | 免登录 |
| 19 | POST | `/api/user/ai/chat.php` | AI 智能问答（纯对话） | 免登录 |

---

## 订单状态与流转

订单状态定义（数据库 `orders.status`）：

| 状态值 | 含义 | 可执行操作 |
| --- | --- | --- |
| 0 | 待支付 | 去支付、取消订单 |
| 1 | 已支付 | 确认完成、取消订单 |
| 2 | 已完成 | 无（终态） |
| 3 | 已取消 | 无（终态） |

```
                  pay.php                complete.php
   [0 待支付] ───────────► [1 已支付] ─────────────► [2 已完成]
        │                        │
        └──────── cancel.php ────┘
                     ▼
              [3 已取消]（自动恢复库存）
```

- 所有状态流转都做了**前置状态校验**与**原子更新**（`UPDATE ... WHERE status = ?`），并发重复请求不会重复生效。
- 取消订单会把下单时扣减的库存**逐项恢复**，整个过程在事务内完成。
- 非法流转返回 `409`，例如对已支付订单再次调用 `pay.php`。

---

## 接口详情

### 1. 获取菜品分类

- **地址**：`GET /api/category/list.php`
- **入参**：无
- **返回 data**：
```json
[
  { "id": 1, "name": "热菜", "sort_order": 10, "icon_url": "" },
  { "id": 2, "name": "凉菜", "sort_order": 8,  "icon_url": "" }
]
```

### 2. 获取菜品列表

- **地址**：`GET /api/dish/list.php` 或 `GET /api/dish/list.php?category_id=1`
- **入参**：`category_id`（可选，不传返回全部）
- **返回 data**：
```json
[
  {
    "id": 1, "category_id": 1, "name": "宫保鸡丁", "price": "28.00",
    "image_url": "", "description": "经典川菜，鸡肉嫩滑，花生香脆",
    "spiciness": 3, "is_recommend": 1, "stock": 999
  }
]
```

### 3. AI 智能推荐菜品

- **地址**：`POST /api/dish/recommend.php`
- **入参**：`{"preference": "想吃辣一点的菜"}`
- **返回 data**：
```json
{
  "recommendations": [
    { "id": 1, "name": "宫保鸡丁", "price": "28.00", "ai_reason": "微辣开胃，适合重口味" }
  ],
  "total": 1
}
```
- **说明**：调用 DeepSeek 推荐 3 道菜，后端已过滤不存在于数据库的 ID。

### 4. 获取购物车列表 🔒

- **地址**：`GET /api/cart/list.php`
- **请求头**：`token: <令牌>`
- **入参**：无（用户身份由令牌决定）
- **返回 data**：
```json
[
  { "cart_id": 3, "quantity": 2, "dish_id": 1, "name": "宫保鸡丁",
    "price": "28.00", "image_url": "", "description": "经典川菜，鸡肉嫩滑，花生香脆" }
]
```

### 5. 加入购物车 🔒

- **地址**：`POST /api/cart/add.php`
- **请求头**：`token: <令牌>`
- **入参**：`{"dish_id": 3, "quantity": 2}`（quantity 默认 1）
- **返回 data**：`null`，msg 为「已加入购物车」
- **说明**：购物车已有该菜品则累加数量，否则新增记录。

### 6. 移除购物车商品 🔒

- **地址**：`POST /api/cart/remove.php`
- **请求头**：`token: <令牌>`
- **入参**：`{"cart_id": 5}`
- **返回 data**：`null`，msg 为「已移除」
- **说明**：后端同时校验 `cart_id` 与当前用户，删除他人记录返回 `404`。

### 7. 创建订单（结算下单）🔒

- **地址**：`POST /api/order/create.php`
- **请求头**：`token: <令牌>`
- **入参**：`{"remark": "少放辣"}`
- **返回 data**：
```json
{ "order_id": 10, "order_no": "202401011200001234", "total_price": "56.00", "status": 0 }
```
- **说明**：读取当前用户购物车 → 校验库存 → 生成订单号 → 事务内写入 `orders` + `order_items`、扣减库存、清空购物车。任一环节失败自动回滚。新订单状态为 `0`（待支付）。

### 8. 查询我的订单 🔒

- **地址**：`GET /api/order/list.php`
- **地址**：`GET /api/order/list.php?status=2`
- **请求头**：`token: <令牌>`
- **入参**：`status`（可选）
  - 数字：`0` 待支付 / `1` 已支付 / `2` 已完成 / `3` 已取消（**推荐**）
  - 兼容写法：`pending` / `paid` / `completed` / `cancelled`
  - 不传则返回全部；传非法值返回 `400`（不再静默查出错误数据）
- **返回 data**：
```json
[
  {
    "id": 10, "order_no": "202401011200001234", "total_price": "56.00",
    "status": 0, "status_text": "待支付", "remark": "少放辣",
    "created_at": "2024-01-01 12:00:00",
    "items": [ { "dish_name": "宫保鸡丁", "dish_price": "28.00", "quantity": 2, "image_url": "" } ]
  }
]
```
- **说明**：`id`、`status`、`quantity` 均返回数字类型；`status_text` 由后端返回，前端无需自己维护状态映射。

### 9. 订单支付 🔒（新增）

- **地址**：`POST /api/order/pay.php`
- **请求头**：`token: <令牌>`
- **入参**：`{"order_id": 10}`
- **返回 data**：
```json
{ "order_id": 10, "order_no": "202401011200001234", "status": 1, "status_text": "已支付" }
```
- **状态流转**：`0 待支付` → `1 已支付`
- **错误**：订单不存在或不属于当前用户 → `404`；当前状态不是待支付 → `409`
- **⚠️ 重要**：当前为**模拟支付**（直接把订单标记为已支付，便于本地跑通业务流程）。
  正式上线必须替换为微信支付：本接口只负责统一下单，订单状态变更必须由**微信支付回调**驱动，并在回调中验签、做幂等处理。

### 10. 取消订单 🔒（新增）

- **地址**：`POST /api/order/cancel.php`
- **请求头**：`token: <令牌>`
- **入参**：`{"order_id": 10}`
- **返回 data**：
```json
{ "order_id": 10, "order_no": "...", "status": 3, "status_text": "已取消", "restored_items": 2 }
```
- **状态流转**：`0 待支付` 或 `1 已支付` → `3 已取消`
- **库存回滚**：按订单明细逐项恢复库存（`restored_items` 为恢复的明细条数），事务保证一致性
- **错误**：已完成/已取消的订单不可再取消 → `409`

### 11. 确认订单完成 🔒（新增）

- **地址**：`POST /api/order/complete.php`
- **请求头**：`token: <令牌>`
- **入参**：`{"order_id": 10}`
- **返回 data**：
```json
{ "order_id": 10, "order_no": "...", "status": 2, "status_text": "已完成" }
```
- **状态流转**：`1 已支付` → `2 已完成`
- **错误**：未支付订单不可直接完成 → `409`

### 12. 微信 openid 登录

- **地址**：`POST /api/user/login.php`
- **入参**：`{"openid": "模拟的openid", "nickname": "微信用户", "avatar_url": "..."}`
- **返回 data**：用户完整信息 + `token`
- **说明**：演示环境直接传 openid；正式环境应使用微信 `code2Session` 换取 openid。用户不存在时自动创建。

### 13. 手机号登录

- **地址**：`POST /api/user/login_phone.php`
- **入参**：`{"phone": "13800138000"}`
- **返回 data**：用户信息 + `token`；未注册返回 `code 404`（提示前往注册）

### 14. 用户注册

- **地址**：`POST /api/user/register.php`
- **入参**：`{"username": "张三", "phone": "13800138000", "code": "123456"}`
- **返回 data**：新用户信息（含 `token`，注册即登录）
- **说明**：通过 `user_id_counter` 表生成自增用户 ID；手机号已注册返回 `code 409`。
- **⚠️ 已知问题**：`init.sql` 中未创建 `user_id_counter` 表且 `users` 表无 `username` 字段，详见 [database.md](database.md)。

### 15. 校验登录态 / 获取当前用户 🔒

- **地址**：`GET /api/user/check.php`
- **请求头**：`token: <令牌>`
- **入参**：无（**不再需要也不接受 user_id**）
- **返回 data**：当前登录用户信息；令牌无效返回 `401`，用户不存在返回 `404`

### 16. 修改个人信息 🔒

- **地址**：`POST /api/user/update.php`
- **请求头**：`token: <令牌>`
- **入参**：`{"nickname": "新昵称", "phone": "138xxxx", "address": "收货地址"}`
- **返回 data**：更新后的用户信息
- **说明**：只能修改自己的信息；手机号会做格式与唯一性校验（与他人冲突返回 `409`）。

### 17. 发送短信验证码（预留）

- **地址**：`POST /api/user/send_sms.php`
- **入参**：`{"phone": "13800138000"}`
- **返回**：当前固定返回 `code 501`（「验证码功能暂未开放」），代码中预留了接入腾讯云短信的注释指引。

### 18. AI 智能问答（含推荐菜品提取）

- **地址**：`POST /api/ai/chat.php`
- **入参**：`{"message": "推荐几道辣菜", "history": [{"role":"user","content":"..."}]}`
- **返回 data**：
```json
{
  "reply": "为您推荐麻婆豆腐，麻辣鲜香很下饭~",
  "recommend_dishes": [ { "id": 2, "name": "麻婆豆腐", "ai_reason": "麻辣鲜香" } ],
  "has_recommend": true
}
```
- **说明**：先获取 AI 回复，若消息含推荐意图关键词（推荐/建议/吃什么/辣/清淡…），再调用推荐接口并返回可点击的推荐菜品卡片。对应前端「AI 助手」页面。

### 19. AI 智能问答（纯对话）

- **地址**：`POST /api/user/ai/chat.php`
- **入参**：`{"message": "你好，有什么推荐的菜吗？", "history": []}`
- **返回 data**：`{"reply": "AI 回复内容"}`
- **说明**：简化版对话接口，不解析推荐菜品。

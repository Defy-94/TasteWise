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

调用**微信一键登录**接口 `POST /api/user/login.php`，入参是小程序端 `wx.login()` 取得的临时 `code`：

```json
{ "code": "wx.login 返回的临时 code" }
```

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

## 接口总览（18 个）

| # | 方法 | 接口 | 功能 | 鉴权 |
| --- | --- | --- | --- | --- |
| 1 | GET | `/api/category/list.php` | 获取菜品分类 | 免登录 |
| 2 | GET | `/api/dish/list.php` | 获取菜品列表（可按分类筛选） | 免登录 |
| 3 | POST | `/api/dish/recommend.php` | AI 智能推荐菜品 | 免登录 |
| 4 | GET | `/api/cart/list.php` | 获取购物车列表 | 🔒 需令牌 |
| 5 | POST | `/api/cart/add.php` | 加入购物车 | 🔒 需令牌 |
| 6 | POST | `/api/cart/remove.php` | 移除购物车商品 | 🔒 需令牌 |
| 7 | POST | `/api/cart/update.php` | **调整购物车数量**（设为 0 即移除） | 🔒 需令牌 |
| 8 | POST | `/api/order/create.php` | 创建订单（结算） | 🔒 需令牌 |
| 9 | GET | `/api/order/list.php` | 查询我的订单 | 🔒 需令牌 |
| 10 | POST | `/api/order/pay.php` | **订单支付**（新增） | 🔒 需令牌 |
| 11 | POST | `/api/order/cancel.php` | **取消订单**（新增） | 🔒 需令牌 |
| 12 | POST | `/api/order/complete.php` | **确认订单完成**（新增） | 🔒 需令牌 |
| 13 | POST | `/api/user/login.php` | **微信一键登录**（code 换 openid，登录即注册） | 免登录（入口） |
| 14 | POST | `/api/user/login_phone.php` | ~~手机号登录~~ **已停用**（返回 410） | — |
| 15 | POST | `/api/user/register.php` | ~~用户注册~~ **已停用**（返回 410） | — |
| 16 | GET | `/api/user/check.php` | 校验登录态 / 获取当前用户 | 🔒 需令牌 |
| 17 | POST | `/api/user/update.php` | 修改个人信息 | 🔒 需令牌 |
| 18 | POST | `/api/ai/chat.php` | AI 智能问答（含推荐菜品提取） | 免登录 |

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

### 7. 调整购物车数量 🔒（新增）

- **地址**：`POST /api/cart/update.php`
- **请求头**：`token: <令牌>`
- **入参**：`{"cart_id": 5, "quantity": 3}`
- **返回 data**：

```json
{ "cart_id": 5, "quantity": 3, "removed": false }
```

- **语义**：**绝对设置**（不是增减）——`quantity` 即设置后的最终数量。
  因此接口是**幂等**的：同一请求重试多次结果一致，不会因为网络重试而多加一次。
- **数量为 0**：表示**移除**该条记录，返回 `{"quantity":0,"removed":true}`（前端会先弹窗确认）。
- **错误**：
  - `cart_id` / `quantity` 缺失、非数字、负数、大于 999 → `400`
  - 记录不存在**或不属于当前用户** → `404`（两种情况返回同一个 404，避免被用来枚举他人数据）
- **说明**：不校验库存上限，与 `cart/add.php` 保持一致（库存统一在 `order/create.php` 下单时校验）。
- **实现提示**：MySQL 的 `UPDATE` 在「新值与旧值相同」时受影响行数也是 0，
  所以不能仅凭受影响行数判断记录是否存在，否则"把数量设成原值"会误报 404。

### 8. 创建订单（结算下单）🔒

- **地址**：`POST /api/order/create.php`
- **请求头**：`token: <令牌>`
- **入参**：`{"remark": "少放辣"}`
- **返回 data**：
```json
{ "order_id": 10, "order_no": "202401011200001234", "total_price": "56.00", "status": 0 }
```
- **说明**：在事务内锁定当前用户的购物车 → 读取明细并计算总价 → 写入 `orders` + `order_items`
  → **原子扣减库存**（`UPDATE dishes SET stock = stock - ? WHERE id = ? AND stock >= ?`）
  → 清空购物车 → 提交。任一环节失败自动回滚。新订单状态为 `0`（待支付）。
- **防超卖**：库存的「检查」与「扣减」合并为一条原子 SQL（条件写在 WHERE 里），
  并发下单时后到的请求影响行数为 0 → 回滚并返回 `409`，因此库存不会被扣成负数。
  同理，同一用户重复提交结算也只会生成一笔订单（购物车行有 `FOR UPDATE` 行锁）。

### 9. 查询我的订单 🔒

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

### 10. 订单支付 🔒（新增）

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

### 11. 取消订单 🔒（新增）

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

### 12. 确认订单完成 🔒（新增）

- **地址**：`POST /api/order/complete.php`
- **请求头**：`token: <令牌>`
- **入参**：`{"order_id": 10}`
- **返回 data**：
```json
{ "order_id": 10, "order_no": "...", "status": 2, "status_text": "已完成" }
```
- **状态流转**：`1 已支付` → `2 已完成`
- **错误**：未支付订单不可直接完成 → `409`

### 13. 微信一键登录（登录即注册）

- **地址**：`POST /api/user/login.php`
- **入参**：`{"code": "wx.login 返回的临时 code", "nickname": "昵称(可选)", "avatar_url": "头像(可选)"}`
- **返回 data**：用户完整信息 + `token`
- **说明**：
  - 后端用 `code` 调微信 `code2Session` 换取 `openid`，再按 openid 查用户，**不存在则自动创建**（因此无需注册流程）。
  - `code` 由小程序端 `wx.login()` 获取，5 分钟有效且只能用一次。
  - 未配置 `config/wechat.php` 的 `appsecret` 时返回 `503`（前端提示「微信登录未配置」）。
  - code 无效或已被使用 → `401`（微信返回 40029 / 40163）。
  - **不接受客户端直接传 openid**：那会导致「知道 openid 即可登录成他人账号」。
- **本地调试通道**：同时满足「`config/security.php` 中 `allow_dev_openid_login = true`」与
  「请求来自本机回环地址」时可传 `{"openid": "..."}` 直接登录，仅供本地开发（线上必须关闭）。

### 14. 手机号登录 —— 已停用

- **地址**：`POST /api/user/login_phone.php`
- **返回**：`code 410`，提示改用微信一键登录
- **停用原因**：原实现既无密码也无短信验证码，仅凭手机号即可登录 ——
  任何人知道别人的手机号就能登录成对方账号（账号接管漏洞）。
  而短信验证码需要企业资质申请短信签名，个人开发者无法正规接入。

### 15. 用户注册 —— 已停用

- **地址**：`POST /api/user/register.php`
- **返回**：`code 410`，提示「微信一键登录，首次登录自动创建账号」
- **停用原因**：
  1. 原实现中入参 `code`（短信验证码）从未被校验，任何人都能用任意手机号注册；
  2. 改为微信一键登录后首次登录自动建号，不再需要注册流程；
  3. 若确实需要手机号，可改用微信「手机号快速验证」组件（付费能力，由微信完成验证）。

### 16. 校验登录态 / 获取当前用户 🔒

- **地址**：`GET /api/user/check.php`
- **请求头**：`token: <令牌>`
- **入参**：无（**不再需要也不接受 user_id**）
- **返回 data**：当前登录用户信息；令牌无效返回 `401`，用户不存在返回 `404`

### 17. 修改个人信息 🔒

- **地址**：`POST /api/user/update.php`
- **请求头**：`token: <令牌>`
- **入参**：`{"nickname": "新昵称", "phone": "138xxxx", "address": "收货地址"}`
- **返回 data**：更新后的用户信息
- **说明**：只能修改自己的信息；手机号会做格式与唯一性校验（与他人冲突返回 `409`）。

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
- **说明**：先让 AI 正常回答，然后**从 AI 的回复文本里识别它提到的菜品**，把这些菜品作为可点击卡片返回
  （`ai_reason` 取菜品名后面的那句话，**以下一个菜名或句末标点为界**）。这样做的好处：
  1. **不会漏**：用户说「我有点渴」「西瓜汁」这类不含关键词的消息，只要 AI 回复里提到了菜品名，就有对应卡片；
  2. **更准确**：卡片就是 AI 刚刚说的那几道菜，而不是另一次推荐调用猜出来的；
  3. **更省钱**：绝大多数情况不需要第二次调用 AI。

  只有当「回复里一道菜都没提到、而用户又明显在要推荐」时，才兜底调用一次
  `DeepSeek::recommend()` 获取结构化推荐（此处会多一次 AI 调用）。
  最多返回 4 张卡片，顺序与 AI 描述顺序一致。对应前端「AI 助手」页面。

  > 文本解析逻辑独立在 `backend/core/AiReply.php`（纯文本处理、不依赖数据库，可单独单元测试）。
  > 支持三种回复格式：① 每道菜一行「- 菜名：理由」；② 一行内用括号/顿号并列多道菜；③ 一句话顺带提到。
  > 其中格式 ② 曾出现「第一道菜的理由把后面几道菜一起吞掉」的问题，修复方式是把理由的结束位置
  > 限定在「下一道菜名的起点」，详见该文件头部注释。

# API 接口文档

所有接口由 `backend/` 提供，统一返回 JSON 格式：

```json
{ "code": 200, "msg": "ok", "data": { ... } }
```

- `code = 200` 成功；非 200 失败（常见：400 参数错误 / 404 不存在 / 409 冲突 / 500 服务器错误）。
- 支持 CORS，`OPTIONS` 预检请求由 `Response::handleOptions()` 自动处理。
- 前端封装见 `frontend/utils/api.ts`，请求体均为 `Content-Type: application/json` 的 JSON 字符串。

## 接口总览（16 个）

| # | 方法 | 接口 | 功能 | 模块 |
| --- | --- | --- | --- | --- |
| 1 | GET | `/api/category/list.php` | 获取菜品分类 | category |
| 2 | GET | `/api/dish/list.php` | 获取菜品列表（可按分类筛选） | dish |
| 3 | POST | `/api/dish/recommend.php` | AI 智能推荐菜品 | dish |
| 4 | GET | `/api/cart/list.php` | 获取购物车列表 | cart |
| 5 | POST | `/api/cart/add.php` | 加入购物车 | cart |
| 6 | POST | `/api/cart/remove.php` | 移除购物车商品 | cart |
| 7 | POST | `/api/order/create.php` | 创建订单（结算） | order |
| 8 | GET | `/api/order/list.php` | 查询我的订单 | order |
| 9 | POST | `/api/user/login.php` | 微信 openid 登录 | user |
| 10 | POST | `/api/user/login_phone.php` | 手机号登录 | user |
| 11 | POST | `/api/user/register.php` | 用户注册 | user |
| 12 | GET | `/api/user/check.php` | 校验用户是否有效 | user |
| 13 | POST | `/api/user/update.php` | 修改个人信息 | user |
| 14 | POST | `/api/user/send_sms.php` | 发送短信验证码（预留） | user |
| 15 | POST | `/api/ai/chat.php` | AI 智能问答（含推荐菜品提取） | ai |
| 16 | POST | `/api/user/ai/chat.php` | AI 智能问答（纯对话） | user/ai |

---

## 1. 获取菜品分类

- **地址**：`GET /api/category/list.php`
- **入参**：无
- **返回 data**：
```json
[
  { "id": 1, "name": "热菜", "sort_order": 10, "icon_url": "" },
  { "id": 2, "name": "凉菜", "sort_order": 8,  "icon_url": "" }
]
```

## 2. 获取菜品列表

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

## 3. AI 智能推荐菜品

- **地址**：`POST /api/dish/recommend.php`
- **入参**：`{"preference": "想吃辣一点的菜"}`
- **返回 data**：
```json
{
  "recommendations": [
    { "id": 1, "category_id": 1, "name": "宫保鸡丁", "price": "28.00", "...": "...", "ai_reason": "微辣开胃，适合重口味" }
  ],
  "total": 1
}
```
- **说明**：调用 DeepSeek 推荐 3 道菜，后端过滤了不存在于数据库的 ID。

## 4. 获取购物车列表

- **地址**：`GET /api/cart/list.php?user_id=1`
- **入参**：`user_id`（必填）
- **返回 data**：
```json
[
  { "cart_id": 3, "quantity": 2, "dish_id": 1, "name": "宫保鸡丁",
    "price": "28.00", "image_url": "", "description": "经典川菜，鸡肉嫩滑，花生香脆" }
]
```

## 5. 加入购物车

- **地址**：`POST /api/cart/add.php`
- **入参**：`{"user_id": 1, "dish_id": 3, "quantity": 2}`（quantity 默认 1）
- **返回 data**：`null`，msg 为「已加入购物车」
- **说明**：购物车已有该菜品则累加数量，否则新增记录。

## 6. 移除购物车商品

- **地址**：`POST /api/cart/remove.php`
- **入参**：`{"cart_id": 5}`
- **返回 data**：`null`，msg 为「已移除」

## 7. 创建订单（结算下单）

- **地址**：`POST /api/order/create.php`
- **入参**：`{"user_id": 1, "remark": "少放辣"}`
- **返回 data**：
```json
{ "order_id": 10, "order_no": "202401011200001234", "total_price": "56.00" }
```
- **说明**：读取该用户购物车 → 校验库存 → 生成订单号 → 事务内写入 `orders` + `order_items`、扣减库存、清空购物车。任一环节失败自动回滚。

## 8. 查询我的订单

- **地址**：`GET /api/order/list.php?user_id=1&status=completed`
- **入参**：`user_id`（必填）、`status`（可选，订单状态：0待支付 / 1已支付 / 2已完成 / 3已取消）
- **返回 data**：
```json
[
  {
    "id": 10, "order_no": "202401011200001234", "total_price": "56.00",
    "status": 0, "remark": "少放辣", "created_at": "2024-01-01 12:00:00",
    "items": [ { "dish_name": "宫保鸡丁", "dish_price": "28.00", "quantity": 2, "image_url": "" } ]
  }
]
```

## 9. 微信 openid 登录

- **地址**：`POST /api/user/login.php`
- **入参**：`{"openid": "模拟的openid", "nickname": "微信用户", "avatar_url": "..."}`
- **返回 data**：用户完整信息（id / openid / nickname / avatar_url / ...）
- **说明**：演示环境直接传 openid；正式环境应使用微信 `code2Session` 换取 openid。

## 10. 手机号登录

- **地址**：`POST /api/user/login_phone.php`
- **入参**：`{"phone": "13800138000"}`
- **返回 data**：用户信息；未注册返回 `code 404`（提示前往注册）

## 11. 用户注册

- **地址**：`POST /api/user/register.php`
- **入参**：`{"username": "张三", "phone": "13800138000", "code": "123456"}`
- **返回 data**：新用户信息（id 从 10001 起）
- **说明**：通过 `user_id_counter` 表生成自增用户 ID；手机号已注册返回 `code 409`。
- **⚠️ 已知问题**：`init.sql` 中未创建 `user_id_counter` 表且 `users` 表无 `username` 字段，详见 [database.md](database.md)。

## 12. 校验用户是否有效

- **地址**：`GET /api/user/check.php?user_id=10001`
- **入参**：`user_id`（必填）
- **返回 data**：用户信息；不存在返回 `code 404`

## 13. 修改个人信息

- **地址**：`POST /api/user/update.php`
- **入参**：`{"user_id": 1, "nickname": "新昵称", "phone": "138xxxx", "address": "收货地址"}`
- **返回 data**：更新后的用户信息

## 14. 发送短信验证码（预留）

- **地址**：`POST /api/user/send_sms.php`
- **入参**：`{"phone": "13800138000"}`
- **返回**：当前固定返回 `code 501`（「验证码功能暂未开放」），代码中预留了接入腾讯云短信的注释指引。

## 15. AI 智能问答（含推荐菜品提取）

- **地址**：`POST /api/ai/chat.php`
- **入参**：`{"message": "推荐几道辣菜", "history": [{"role":"user","content":"..."}]}`
- **返回 data**：
```json
{
  "reply": "为您推荐麻婆豆腐，麻辣鲜香很下饭~",
  "recommend_dishes": [ { "id": 2, "name": "麻婆豆腐", "...": "...", "ai_reason": "麻辣鲜香" } ],
  "has_recommend": true
}
```
- **说明**：先获取 AI 回复，若消息含推荐意图关键词（推荐/建议/吃什么/辣/清淡…），再调用推荐接口并返回可点击的推荐菜品卡片。对应前端「AI 助手」页面。

## 16. AI 智能问答（纯对话）

- **地址**：`POST /api/user/ai/chat.php`
- **入参**：`{"message": "你好，有什么推荐的菜吗？", "history": []}`
- **返回 data**：`{"reply": "AI 回复内容"}`
- **说明**：简化版对话接口，不解析推荐菜品。

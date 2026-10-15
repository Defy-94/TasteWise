<?php
/**
 * 接口：调整购物车商品数量
 *
 * 请求头：token: <登录令牌>
 * 入参JSON：{"cart_id":5,"quantity":3}
 * 请求地址：POST /api/cart/update.php
 */
require_once __DIR__ . '/../../core/bootstrap.php';

Response::handleOptions();

Request::requirePost();

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

$cartIdRaw   = Request::raw('cart_id');
$quantityRaw = Request::raw('quantity');

// ===== 参数校验 =====
// ⚠️ 必须先判断"参数是否传了"，再判断"是否为合法数字"。
//    若直接 intval()，会把「没传 quantity」和「quantity=0」都变成 0，
//    也会把 "abc" 静默当成 0，从而误删用户的购物车记录。
//    所以这里刻意用 Request::raw() 取原始值，而不是省事地用 Request::int()。
if ($cartIdRaw === null || $cartIdRaw === '') {
    Response::error('参数错误：cart_id必填');
}
if ($quantityRaw === null || $quantityRaw === '') {
    Response::error('参数错误：quantity必填');
}
if (!is_numeric($cartIdRaw) || intval($cartIdRaw) <= 0) {
    Response::error('参数错误：cart_id必须为正整数');
}
if (!is_numeric($quantityRaw)) {
    Response::error('参数错误：quantity必须为数字');
}

$cartId   = intval($cartIdRaw);
$quantity = intval($quantityRaw);

if ($quantity < 0) {
    Response::error('参数错误：quantity不能为负数');
}
if ($quantity > 999) {
    Response::error('参数错误：quantity不能大于999');
}

try {
    $db = DB::getInstance();

    // ===== 情况一：数量为 0 → 移除该条记录 =====
    if ($quantity === 0) {
        $affected = $db->execute(
            'DELETE FROM cart WHERE id = ? AND user_id = ?',
            [$cartId, $userId]
        );
        if ($affected <= 0) {
            Response::error('购物车记录不存在或无权操作', 404);
        }
        Response::success([
            'cart_id'  => $cartId,
            'quantity' => 0,
            'removed'  => true,
        ], '已移除');
    }

    // ===== 情况二：数量大于 0 → 更新为指定值 =====
    // WHERE 同时限定 id 与 user_id：别人的 cart_id 改不动
    $affected = $db->execute(
        'UPDATE cart SET quantity = ? WHERE id = ? AND user_id = ?',
        [$quantity, $cartId, $userId]
    );

    // ⚠️ 坑：MySQL 的 UPDATE 在「新值与旧值相同」时受影响行数也是 0，
    //    因此不能仅凭 $affected <= 0 就判定记录不存在。
    //    这里再查一次，用于区分「记录不存在/不属于你」与「值本来就没变」。
    if ($affected <= 0) {
        $exists = $db->selectOne(
            'SELECT id FROM cart WHERE id = ? AND user_id = ?',
            [$cartId, $userId]
        );
        if (!$exists) {
            Response::error('购物车记录不存在或无权操作', 404);
        }
    }

    Response::success([
        'cart_id'  => $cartId,
        'quantity' => $quantity,
        'removed'  => false,
    ], '已更新');

} catch (Exception $e) {
    Response::serverError('操作失败', $e);
}

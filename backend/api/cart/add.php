<?php
/**
 * 接口：加入购物车
 * 请求地址：POST /api/cart/add.php
 * 请求头：token: <登录令牌>
 * 入参JSON：{"dish_id":3,"quantity":2}
 * 对应前端：菜品卡片上"加入购物车"按钮
 * 
 * 【鉴权说明】购物车归属用户取自令牌，客户端传入的 user_id 一律忽略，
 *            因此无法往他人购物车里加东西。
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';
require_once __DIR__ . '/../../core/Auth.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

// 校验令牌并取得当前登录用户ID（未登录直接返回401）
$userId = Auth::requireLogin();

$input    = json_decode(file_get_contents('php://input'), true);
$dishId   = isset($input['dish_id']) ? intval($input['dish_id']) : 0;
$quantity = isset($input['quantity']) ? intval($input['quantity']) : 1;

if ($dishId <= 0) {
    Response::error('参数错误：dish_id必填');
}

if ($quantity < 1) {
    $quantity = 1;
}

try {
    $db = DB::getInstance();
    
    // 检查菜品是否存在，以及是否已下架
    $dish = $db->selectOne('SELECT id, name, stock, is_on_sale FROM dishes WHERE id = ?', [$dishId]);
    if (!$dish) {
        Response::error('菜品不存在');
    }

    // 下架菜品不允许加入购物车（正常入口看不到，但可能是加购后后台才下架的，
    // 或者用户从旧链接/分享进入了详情页）
    if ((int) $dish['is_on_sale'] !== 1) {
        Response::error('「' . $dish['name'] . '」已下架，暂时无法加入购物车', 409);
    }
    
    // 检查购物车是否已有该菜品，有则更新数量，无则插入
    $exist = $db->selectOne(
        'SELECT id, quantity FROM cart WHERE user_id = ? AND dish_id = ?',
        [$userId, $dishId]
    );
    
    if ($exist) {
        $newQty = $exist['quantity'] + $quantity;
        $db->execute('UPDATE cart SET quantity = ? WHERE id = ?', [$newQty, $exist['id']]);
    } else {
        $db->insert(
            'INSERT INTO cart (user_id, dish_id, quantity) VALUES (?, ?, ?)',
            [$userId, $dishId, $quantity]
        );
    }
    
    Response::success(null, '已加入购物车');
} catch (Exception $e) {
    Response::error('操作失败：' . $e->getMessage(), 500);
}

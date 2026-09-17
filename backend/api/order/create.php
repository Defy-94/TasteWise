<?php
/**
 * 接口：创建订单（下单结算）
 * 请求地址：POST /api/order/create.php
 * 请求头：token: <登录令牌>
 * 入参JSON：{"remark":"少放辣"}
 * 返回：{"order_id":10,"order_no":"...","total_price":"56.00","status":0}
 * 对应前端：购物车页面"结算"按钮
 * 
 * 【鉴权说明】下单用户取自令牌，客户端传入的 user_id 一律忽略，
 *            因此无法替他人下单或清空他人购物车。
 * 
 * 【订单状态】新建订单状态为 0（待支付），
 *            后续通过 order/pay.php（支付）、order/cancel.php（取消）、
 *            order/complete.php（完成）流转。
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

$input  = json_decode(file_get_contents('php://input'), true);
$remark = isset($input['remark']) ? trim($input['remark']) : '';

try {
    $db = DB::getInstance();
    
    // 1. 查询购物车商品
    $cartItems = $db->select(
        'SELECT c.id as cart_id, c.quantity, d.id as dish_id, d.name, d.price, d.stock
         FROM cart c
         JOIN dishes d ON c.dish_id = d.id
         WHERE c.user_id = ?',
        [$userId]
    );
    
    if (empty($cartItems)) {
        Response::error('购物车为空，无法下单');
    }
    
    // 2. 计算总价并检查库存
    $totalPrice = 0;
    foreach ($cartItems as $item) {
        if ($item['stock'] < $item['quantity']) {
            Response::error("「{$item['name']}」库存不足，当前库存：{$item['stock']}");
        }
        $totalPrice += $item['price'] * $item['quantity'];
    }
    
    // 3. 生成订单编号
    $orderNo = date('YmdHis') . rand(1000, 9999);
    
    // 4. 开启事务处理
    $db->beginTransaction();
    
    try {
        // 4.1 插入订单主表（状态 0 = 待支付）
        $orderId = $db->insert(
            'INSERT INTO orders (order_no, user_id, total_price, status, remark) VALUES (?, ?, ?, 0, ?)',
            [$orderNo, $userId, $totalPrice, $remark]
        );
        
        // 4.2 插入订单明细
        foreach ($cartItems as $item) {
            $db->insert(
                'INSERT INTO order_items (order_id, dish_id, dish_name, dish_price, quantity) VALUES (?, ?, ?, ?, ?)',
                [$orderId, $item['dish_id'], $item['name'], $item['price'], $item['quantity']]
            );
            
            // 4.3 扣减库存
            $db->execute(
                'UPDATE dishes SET stock = stock - ? WHERE id = ?',
                [$item['quantity'], $item['dish_id']]
            );
        }
        
        // 4.4 清空购物车
        $db->execute('DELETE FROM cart WHERE user_id = ?', [$userId]);
        
        // 5. 提交事务
        $db->commit();
        
        Response::success([
            'order_id'    => $orderId,
            'order_no'    => $orderNo,
            'total_price' => $totalPrice,
            'status'      => 0,          // 0 = 待支付
        ], '下单成功');
        
    } catch (Exception $e) {
        // 回滚事务
        $db->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    Response::error('下单失败：' . $e->getMessage(), 500);
}

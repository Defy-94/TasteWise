<?php
/**
 * 接口：创建订单（下单结算）
 * 请求地址：POST /api/order/create.php
 * 入参JSON：{"user_id":1,"remark":"少放辣"}
 * 对应前端：购物车页面"结算"按钮
 */
require_once __DIR__ . '/../../core/DB.php';
require_once __DIR__ . '/../../core/Response.php';

Response::handleOptions();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('仅支持POST请求');
}

$input  = json_decode(file_get_contents('php://input'), true);
$userId = isset($input['user_id']) ? intval($input['user_id']) : 0;
$remark = isset($input['remark']) ? trim($input['remark']) : '';

if ($userId <= 0) {
    Response::error('参数错误：user_id必填');
}

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
        // 4.1 插入订单主表
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
        ], '下单成功');
        
    } catch (Exception $e) {
        // 回滚事务
        $db->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    Response::error('下单失败：' . $e->getMessage(), 500);
}
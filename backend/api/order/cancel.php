<?php
/**
 * 接口：取消订单
 * 请求地址：POST /api/order/cancel.php
 * 请求头：token: <登录令牌>
 * 入参JSON：{"order_id":10}
 * 返回：{"order_id":10,"status":3,"status_text":"已取消","restored_items":2}
 * 对应前端：我的订单页"取消订单"按钮
 * 
 * 【状态流转】0（待支付）或 1（已支付） → 3（已取消）
 *            已完成（2）的订单不可取消
 * 
 * 【库存回滚】下单时已扣减库存，取消时按订单明细逐项恢复库存，
 *            整个过程在事务内完成，任一步失败则整体回滚。
 * 
 * 【鉴权说明】取消时同时校验 order_id 与当前登录用户（防越权）。
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

$input   = json_decode(file_get_contents('php://input'), true);
$orderId = isset($input['order_id']) ? intval($input['order_id']) : 0;

if ($orderId <= 0) {
    Response::error('参数错误：order_id必填');
}

$statusTextMap = [0 => '待支付', 1 => '已支付', 2 => '已完成', 3 => '已取消'];

try {
    $db = DB::getInstance();
    
    $db->beginTransaction();
    
    try {
        // 1. 加行锁查询订单，且必须属于当前登录用户（防止并发下重复取消/重复回滚库存）
        $order = $db->selectOne(
            'SELECT id, order_no, status FROM orders WHERE id = ? AND user_id = ? FOR UPDATE',
            [$orderId, $userId]
        );
        
        if (!$order) {
            $db->rollback();
            Response::error('订单不存在或无权操作', 404);
        }
        
        $status = (int) $order['status'];
        
        // 2. 状态校验：仅待支付、已支付可取消
        if ($status !== 0 && $status !== 1) {
            $db->rollback();
            $current = isset($statusTextMap[$status]) ? $statusTextMap[$status] : '未知';
            Response::error('订单当前状态不可取消（当前：' . $current . '）', 409);
        }
        
        // 3. 恢复库存：按订单明细逐项加回
        $items = $db->select(
            'SELECT dish_id, quantity FROM order_items WHERE order_id = ?',
            [$orderId]
        );
        
        foreach ($items as $item) {
            // dish_id 对应的菜品可能已被删除，此时影响行数为 0，属正常情况
            $db->execute(
                'UPDATE dishes SET stock = stock + ? WHERE id = ?',
                [(int) $item['quantity'], $item['dish_id']]
            );
        }
        
        // 4. 更新订单状态为已取消
        $db->execute(
            'UPDATE orders SET status = 3 WHERE id = ? AND user_id = ?',
            [$orderId, $userId]
        );
        
        $db->commit();
        
        Response::success([
            'order_id'       => $orderId,
            'order_no'       => $order['order_no'],
            'status'         => 3,
            'status_text'    => '已取消',
            'restored_items' => count($items),    // 已恢复库存的明细条数
        ], '订单已取消');
        
    } catch (Exception $e) {
        // 回滚事务（注意：Response::error 内部已 exit，不会走到这里）
        if ($db->getPdo()->inTransaction()) {
            $db->rollback();
        }
        throw $e;
    }
    
} catch (Exception $e) {
    Response::error('取消失败：' . $e->getMessage(), 500);
}

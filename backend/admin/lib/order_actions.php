<?php
/**
 * 管理后台的订单操作封装
 *
 * 后台改订单状态，本质上就是调用 core/OrderStatus.php 里那套状态机，
 * 只是这里把「异常转成一句给管理员看的话」，让页面代码保持干净。
 */

if (!defined('TW_ADMIN')) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * 后台订单操作名 → 目标状态
 */
function admin_order_action_map()
{
    return [
        'pay'      => OrderStatus::PAID,       // 确认收款
        'complete' => OrderStatus::DONE,       // 标记完成
        'cancel'   => OrderStatus::CANCELED,   // 取消订单（会自动恢复库存）
    ];
}

/**
 * 执行一个订单状态操作
 *
 * @param int    $orderId
 * @param string $action  pay | complete | cancel
 * @return array [bool $ok, string $message]
 */
function admin_apply_order_action($orderId, $action)
{
    $map = admin_order_action_map();

    if (!isset($map[$action])) {
        return [false, '未知操作：' . $action];
    }

    $orderId = (int) $orderId;
    if ($orderId <= 0) {
        return [false, '参数错误：缺少订单ID'];
    }

    try {
        // 第三个参数传 null：管理后台不限定订单归属（可以操作任何用户的订单）。
        // 这正是它和小程序接口的区别 —— 小程序那边必须传用户ID，防止越权。
        $result = OrderStatus::transition($orderId, $map[$action], null);

        $message = '订单 ' . $result['order_no'] . ' 已标记为「' . $result['status_text'] . '」';

        // 取消订单会恢复库存，把这件事明确告诉管理员，避免他以为系统偷偷改了库存
        if (isset($result['restored_items'])) {
            $message .= '，已按订单明细恢复 ' . (int) $result['restored_items'] . ' 项菜品的库存';
        }

        return [true, $message];

    } catch (OrderStatusException $e) {
        // 业务错误（状态冲突、订单不存在等），原样把原因告诉管理员
        return [false, $e->getMessage()];

    } catch (Exception $e) {
        return [false, '操作失败：' . $e->getMessage()];
    }
}

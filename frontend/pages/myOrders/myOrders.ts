/**
 * 我的订单页面
 * 功能：查看订单列表、订单详情
 */
import { get } from '../../utils/api';
import { Order } from '../../utils/types';

const app = getApp<IAppOption>();

// 订单状态映射
const STATUS_MAP: Record<number, string> = {
  0: '待支付',
  1: '已支付',
  2: '已完成',
  3: '已取消',
};

Page({
  data: {
    orders: [] as Order[],
    isEmpty: true,
  },

  onShow() {
    this.loadOrders();
  },

  /**
   * 加载我的订单
   * 接口：GET /api/order/list.php?user_id=1
   * 对应页面：我的订单页
   */
  async loadOrders() {
    try {
      const orders = await get<Order[]>('/api/order/list.php', {
        user_id: app.globalData.userId,
      });
      
      // 添加状态文本
      orders.forEach(o => {
        o.status_text = STATUS_MAP[o.status] || '未知';
      });
      
      this.setData({
        orders,
        isEmpty: orders.length === 0,
      });
    } catch (err) {
      console.error('加载订单失败：', err);
    }
  },
});
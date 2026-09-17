/**
 * 我的订单页面
 * 功能：查看订单列表、支付/取消/完成订单
 * 
 * 【鉴权说明】请求自动携带登录令牌（见 utils/api.ts），
 *            未登录时页面提示登录，令牌过期会自动跳转登录页。
 * 
 * 【订单状态】0待支付 1已支付 2已完成 3已取消
 *            后端已返回 status_text，本页仅根据 status 计算可执行操作。
 */
import { get, post, getToken } from '../../utils/api';
import { Order, OrderStatusMap } from '../../utils/types';

// 订单状态常量（与数据库 orders.status 一致）
const STATUS_PENDING   = 0;   // 待支付
const STATUS_PAID      = 1;   // 已支付
const STATUS_COMPLETED = 2;   // 已完成
const STATUS_CANCELLED = 3;   // 已取消

/** 页面内使用的订单视图类型（在订单基础上附加可执行操作标记） */
interface OrderView extends Order {
  can_pay?: boolean;
  can_cancel?: boolean;
  can_complete?: boolean;
}

Page({
  data: {
    orders: [] as OrderView[],
    isEmpty: true,
    needLogin: false,     // 未登录时显示登录提示
  },

  onShow() {
    // 未登录时不发请求，直接展示登录提示（避免无意义的 401 跳转）
    if (!getToken()) {
      this.setData({ orders: [], isEmpty: true, needLogin: true });
      return;
    }

    this.setData({ needLogin: false });
    this.loadOrders();
  },

  /**
   * 加载我的订单
   * 接口：GET /api/order/list.php（用户身份由令牌决定，无需传 user_id）
   */
  async loadOrders() {
    try {
      const orders = await get<OrderView[]>('/api/order/list.php');

      // 整理状态：统一为数字，并计算当前状态可执行哪些操作
      orders.forEach((o) => {
        o.status = Number(o.status);
        o.status_text = o.status_text || OrderStatusMap[o.status] || '未知';
        o.can_pay      = o.status === STATUS_PENDING;
        o.can_cancel   = o.status === STATUS_PENDING || o.status === STATUS_PAID;
        o.can_complete = o.status === STATUS_PAID;
      });

      this.setData({
        orders,
        isEmpty: orders.length === 0,
      });
    } catch (err) {
      console.error('加载订单失败：', err);
    }
  },

  /**
   * 弹窗确认（Promise 化，便于 async/await 使用）
   */
  confirm(title: string, content: string): Promise<boolean> {
    return new Promise((resolve) => {
      wx.showModal({
        title,
        content,
        success: (res) => resolve(!!res.confirm),
        fail: () => resolve(false),
      });
    });
  },

  /**
   * 执行订单状态流转操作
   * @param url          接口地址（pay/cancel/complete）
   * @param orderId      订单ID
   * @param successText  成功提示文案
   */
  async doAction(url: string, orderId: number, successText: string) {
    wx.showLoading({ title: '处理中...', mask: true });
    try {
      await post(url, { order_id: orderId });
      wx.hideLoading();
      wx.showToast({ title: successText, icon: 'success' });
    } catch (err) {
      // 错误提示已由请求层统一处理（如状态冲突、越权）
      wx.hideLoading();
      console.error('订单操作失败：', err);
    } finally {
      // 无论成功失败都刷新列表，保证状态与后端一致
      this.loadOrders();
    }
  },

  /**
   * 支付订单
   * 接口：POST /api/order/pay.php
   * 说明：当前为模拟支付，正式环境应接入微信支付（见接口内注释）
   */
  async onPay(e: any) {
    const orderId = Number(e.currentTarget.dataset.id);
    if (!orderId) return;

    const ok = await this.confirm('确认支付', '确定要支付该订单吗？');
    if (!ok) return;

    await this.doAction('/api/order/pay.php', orderId, '支付成功');
  },

  /**
   * 取消订单
   * 接口：POST /api/order/cancel.php
   * 说明：取消后已扣减的库存会自动恢复
   */
  async onCancel(e: any) {
    const orderId = Number(e.currentTarget.dataset.id);
    if (!orderId) return;

    const ok = await this.confirm('确认取消', '取消后订单不可恢复，确定要取消吗？');
    if (!ok) return;

    await this.doAction('/api/order/cancel.php', orderId, '订单已取消');
  },

  /**
   * 确认订单完成（取餐/收货完成）
   * 接口：POST /api/order/complete.php
   */
  async onComplete(e: any) {
    const orderId = Number(e.currentTarget.dataset.id);
    if (!orderId) return;

    const ok = await this.confirm('确认完成', '确认已取餐/收货完成吗？');
    if (!ok) return;

    await this.doAction('/api/order/complete.php', orderId, '订单已完成');
  },

  /** 跳转登录页 */
  onGoLogin() {
    wx.navigateTo({ url: '/pages/login/login' });
  },
});

/**
 * 我的订单页面
 * 功能：按状态筛选订单、支付/取消/完成订单
 * 
 * 【鉴权说明】请求自动携带登录令牌（见 utils/api.ts），
 *            未登录时页面提示登录，令牌过期会自动跳转登录页。
 * 
 * 【订单状态】0待支付 1已支付 2已完成 3已取消（-1 表示不筛选）
 *            后端已返回 status_text，本页仅根据 status 计算可执行操作。
 */
import { get, post, getToken } from '../../utils/api';
import { Order, OrderStatusMap } from '../../utils/types';

// 订单状态常量（与数据库 orders.status 一致）
const STATUS_PENDING   = 0;   // 待支付
const STATUS_PAID      = 1;   // 已支付
const STATUS_COMPLETED = 2;   // 已完成
const STATUS_CANCELLED = 3;   // 已取消

/**
 * 筛选标签
 * 注意：用 -1 表示「全部」而不是 null —— WXML 的 data-* 属性里 null 会被转成空字符串，
 *      用数字可以避免这种隐式转换带来的歧义。
 */
const FILTERS = [
  { label: '全部',   value: -1 },
  { label: '待支付', value: STATUS_PENDING },
  { label: '已支付', value: STATUS_PAID },
  { label: '已完成', value: STATUS_COMPLETED },
  { label: '已取消', value: STATUS_CANCELLED },
];

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
    needLogin: false,                 // 未登录时显示登录提示
    loading: false,
    filters: FILTERS,
    activeStatus: -1,                 // 当前筛选：-1 全部 / 0-3 具体状态
  },

  /**
   * 支持从其他页面带状态进入，如：
   *   wx.navigateTo({ url: '/pages/myOrders/myOrders?status=0' })
   */
  onLoad(options: any) {
    if (options && options.status !== undefined && options.status !== '') {
      const s = Number(options.status);
      if (!isNaN(s) && s >= 0 && s <= 3) {
        this.setData({ activeStatus: s });
      }
    }
  },

  onShow() {
    // 未登录时不发请求，直接展示登录提示（避免无意义的 401 跳转）
    if (!getToken()) {
      this.setData({ orders: [], isEmpty: true, needLogin: true });
      return;
    }

    this.setData({ needLogin: false });
    this.loadOrders(this.data.activeStatus);
  },

  /**
   * 切换筛选标签
   * 纯前端筛选：后端接口支持 status 参数，无需重新设计数据
   */
  onFilterTap(e: any) {
    const status = Number(e.currentTarget.dataset.status);
    if (isNaN(status) || status === this.data.activeStatus) return;

    this.setData({ activeStatus: status });
    this.loadOrders(status);
  },

  /**
   * 加载订单列表
   * 接口：GET /api/order/list.php?status=2（不传 status 即返回全部）
   * 
   * @param status -1 表示不筛选；0-3 表示按状态筛选
   */
  async loadOrders(status: number = -1) {
    this.setData({ loading: true });

    try {
      const params: any = {};
      if (status >= 0) {
        params.status = status;
      }

      const orders = await get<OrderView[]>('/api/order/list.php', params);

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
    } finally {
      this.setData({ loading: false });
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
      this.loadOrders(this.data.activeStatus);
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

  /** 去点餐（空状态时的引导） */
  onGoIndex() {
    wx.switchTab({ url: '/pages/index/index' });
  },

  /** 跳转登录页 */
  onGoLogin() {
    wx.navigateTo({ url: '/pages/login/login' });
  },
});

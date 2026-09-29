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
import { errorMessage, logError } from '../../utils/error';

// 订单状态常量（与数据库 orders.status 一致）
const STATUS_PENDING   = 0;   // 待支付
const STATUS_PAID      = 1;   // 已支付
const STATUS_COMPLETED = 2;   // 已完成
const STATUS_CANCELLED = 3;   // 已取消

/** 每页加载多少条订单（上拉加载时按这个粒度追加） */
const PER_PAGE = 10;

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
    page: 1,                          // 已加载到第几页
    hasMore: true,                    // 是否还有下一页（触底时据此决定要不要继续加载）
    loadingMore: false,               // 是否正在上拉加载（防止重复触发）
    loadError: '',                    // 加载失败原因（非空时显示错误态而不是"还没有订单"）
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
    // 每次进入页面都从第 1 页重新加载：期间可能刚下过单或改过状态
    this.loadOrders(this.data.activeStatus, 1);
  },

  /**
   * 切换筛选标签
   * 纯前端筛选：后端接口支持 status 参数，无需重新设计数据
   */
  onFilterTap(e: any) {
    const status = Number(e.currentTarget.dataset.status);
    if (isNaN(status) || status === this.data.activeStatus) return;

    this.setData({ activeStatus: status });
    // 换筛选条件相当于换了一份列表，必须从第 1 页重新开始
    this.loadOrders(status, 1);
  },

  /** 触底加载下一页（配合 wxml 外层容器与 onReachBottom） */
  onReachBottom() {
    if (!this.data.hasMore || this.data.loadingMore || this.data.loading) {
      return;
    }

    this.loadOrders(this.data.activeStatus, this.data.page + 1, true);
  },

  /**
   * 加载订单列表（分页）
   * 接口：GET /api/order/list.php?status=2&page=1&per_page=10
   *
   * @param status     -1 表示不筛选；0-3 表示按状态筛选
   * @param page       要加载第几页（从 1 开始）
   * @param append     true 表示追加到现有列表（上拉加载），false 表示替换
   *
   * 判断「还有下一页」用的是「本页返回条数 == per_page」——
   * 后端刻意保持返回数组（而不是 {list,total}），所以只能这样判断。
   */
  async loadOrders(status: number = -1, page: number = 1, append: boolean = false) {
    const perPage = PER_PAGE;

    this.setData(append ? { loadingMore: true } : { loading: true });

    try {
      const params: any = { page: page, per_page: perPage };
      if (status >= 0) {
        params.status = status;
      }

      const list = await get<OrderView[]>('/api/order/list.php', params, { silentError: true });

      // 整理状态：统一为数字，并计算当前状态可执行哪些操作
      list.forEach((o) => {
        o.status = Number(o.status);
        o.status_text = o.status_text || OrderStatusMap[o.status] || '未知';
        o.can_pay      = o.status === STATUS_PENDING;
        o.can_cancel   = o.status === STATUS_PENDING || o.status === STATUS_PAID;
        o.can_complete = o.status === STATUS_PAID;
      });

      const orders = append ? this.data.orders.concat(list) : list;

      this.setData({
        orders,
        isEmpty: orders.length === 0,
        loadError: '',
        page,
        hasMore: list.length >= perPage,
      });
    } catch (err) {
      logError('加载订单失败', err);

      // 关键：把「加载失败」和「一单都没有」区分开 ——
      // 否则网络一断，用户看到的是「还没有订单」，会以为自己的订单丢了
      // 上拉加载失败时不要清空已有列表，只停止继续加载
      this.setData({
        orders: append ? this.data.orders : [],
        isEmpty: append ? this.data.isEmpty : true,
        loadError: errorMessage(err, '订单加载失败，请检查网络后重试'),
        hasMore: false,
      });
    } finally {
      this.setData({ loading: false, loadingMore: false });
    }
  },

  /** 加载失败后重新加载 */
  onRetryLoad() {
    this.loadOrders(this.data.activeStatus, 1);
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
      // 无论成功失败都刷新列表，保证状态与后端一致。
      // 从第 1 页开始：状态变了之后，这笔订单在当前筛选下可能已经不该出现
      this.loadOrders(this.data.activeStatus, 1);
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

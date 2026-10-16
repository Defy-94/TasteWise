/**
 * 我的订单页：按状态筛选、支付/取消/完成
 * 订单状态 0待支付 1已支付 2已完成 3已取消，-1 表示不筛选
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
 * 订单状态变化时小味的反馈：file 对应 assets/xiaowei/diffs 里的差分素材
 * 三种状态分别用 开心 / 为难 / 致谢，与对话页那套是同一批图
 */
const STATUS_FEEDBACK: Record<string, { file: string; title: string; desc: string; toast?: string }> = {
  pay: {
    file: 'xiaowei-happy.png',
    title: '收到啦',
    desc: '已经帮你记下这单，后厨马上开始做',
  },
  cancel: {
    file: 'xiaowei-sorry.png',
    title: '订单已取消',
    desc: '库存已经还回去了，随时可以重新点',
    toast: '订单已取消',
  },
  complete: {
    file: 'xiaowei-goodbye.png',
    title: '用餐愉快',
    desc: '期待下次再见到你',
    toast: '订单已完成',
  },
};

/** 反馈卡片停留时长（毫秒） */
const FEEDBACK_MS = 2000;

/** 筛选标签：用 -1 表示「全部」而不是 null（data-* 里 null 会变成空字符串） */
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
    feedback: null as null | { file: string; title: string; desc: string },  // 小味的操作反馈
  },

  /** 支持带状态进入：/pages/myOrders/myOrders?status=0 */
  onLoad(options: any) {
    if (options && options.status !== undefined && options.status !== '') {
      const s = Number(options.status);
      if (!isNaN(s) && s >= 0 && s <= 3) {
        this.setData({ activeStatus: s });
      }
    }
  },

  onUnload() {
    // 离开页面时清掉反馈计时器，避免回调打到已销毁的页面上
    if (this.feedbackTimer) {
      clearTimeout(this.feedbackTimer);
      this.feedbackTimer = null;
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

  /** 切换筛选标签 */
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
   * 加载订单列表（分页）：GET /api/order/list.php?status=&page=&per_page=
   * @param status -1 不筛选；0-3 按状态筛选
   * @param append true 追加到现有列表（上拉加载），false 替换
   * 后端返回的是数组而非 {list,total}，所以用「本页条数 == per_page」判断还有下一页
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
   * 执行订单状态流转：pay / cancel / complete
   * @param action 与 STATUS_FEEDBACK 的键对应，成功后的提示与小味表情都由它决定
   */
  async doAction(url: string, orderId: number, action: string) {
    wx.showLoading({ title: '处理中...', mask: true });
    try {
      await post(url, { order_id: orderId });
      wx.hideLoading();

      // 支付只由小味卡片反馈（toast 说的是同一件事，重复且多余）；
      // 取消/完成仍保留 toast，提醒更明确
      const toast = STATUS_FEEDBACK[action] && STATUS_FEEDBACK[action].toast;
      if (toast) wx.showToast({ title: toast, icon: 'success' });

      this.showFeedback(action);
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

  /** 支付订单：POST /api/order/pay.php（当前为模拟支付） */
  async onPay(e: any) {
    const orderId = Number(e.currentTarget.dataset.id);
    if (!orderId) return;

    const ok = await this.confirm('确认支付', '确定要支付该订单吗？');
    if (!ok) return;

    await this.doAction('/api/order/pay.php', orderId, 'pay');
  },

  /** 取消订单：POST /api/order/cancel.php（已扣减的库存会自动恢复） */
  async onCancel(e: any) {
    const orderId = Number(e.currentTarget.dataset.id);
    if (!orderId) return;

    const ok = await this.confirm('确认取消', '取消后订单不可恢复，确定要取消吗？');
    if (!ok) return;

    await this.doAction('/api/order/cancel.php', orderId, 'cancel');
  },

  /** 确认订单完成：POST /api/order/complete.php */
  async onComplete(e: any) {
    const orderId = Number(e.currentTarget.dataset.id);
    if (!orderId) return;

    const ok = await this.confirm('确认完成', '确认已取餐/收货完成吗？');
    if (!ok) return;

    await this.doAction('/api/order/complete.php', orderId, 'complete');
  },

  /** 状态变化后弹一下小味的反馈，2 秒自动收起 */
  showFeedback(action: string) {
    const conf = STATUS_FEEDBACK[action];
    if (!conf) return;

    this.setData({ feedback: { file: conf.file, title: conf.title, desc: conf.desc } });

    if (this.feedbackTimer) clearTimeout(this.feedbackTimer);
    this.feedbackTimer = setTimeout(() => {
      this.feedbackTimer = null;
      this.setData({ feedback: null });
    }, FEEDBACK_MS);
  },

  /** 点一下提前收掉反馈 */
  onFeedbackTap() {
    if (this.feedbackTimer) {
      clearTimeout(this.feedbackTimer);
      this.feedbackTimer = null;
    }
    this.setData({ feedback: null });
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

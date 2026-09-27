/**
 * 用户中心页面（「我的」）
 * 功能：个人信息展示与编辑、订单概览（各状态数量）、订单入口、退出登录
 * 
 * 【鉴权说明】用户身份完全由登录令牌决定（见 utils/api.ts），
 *            因此不再需要向接口传 user_id，也无法查看他人数据。
 */
import { get, post, saveAuth, clearAuth } from '../../utils/api';
import { UserInfo, Order, OrderStatusMap } from '../../utils/types';
import { clearCartBadge, refreshCartBadge } from '../../utils/cartBadge';

/** 各状态订单数量 */
interface OrderCounts {
  pending: number;
  paid: number;
  completed: number;
  cancelled: number;
}

// 下标即订单状态值：0待支付 1已支付 2已完成 3已取消
const STATUS_KEYS: (keyof OrderCounts)[] = ['pending', 'paid', 'completed', 'cancelled'];

const EMPTY_COUNTS: OrderCounts = { pending: 0, paid: 0, completed: 0, cancelled: 0 };

Page({
  data: {
    isLoggedIn: false,                  // 是否已登录
    userInfo: null as UserInfo | null,
    orderCounts: EMPTY_COUNTS,          // 订单概览：各状态数量
    latestOrder: null as Order | null,  // 最近一笔订单
    isEditing: false,
    editNickname: '',
    editPhone: '',
    editAddress: '',
  },

  onShow() {
    this.checkLoginStatus();
  },

  /**
   * 检查登录状态
   * 先看本地缓存，再调用接口校验登录态是否仍有效
   */
  async checkLoginStatus() {
    const cachedUser = wx.getStorageSync('userInfo');

    if (!cachedUser) {
      // 未登录
      this.setData({
        isLoggedIn: false,
        userInfo: null,
        orderCounts: EMPTY_COUNTS,
        latestOrder: null,
      });
      return;
    }

    try {
      // 校验登录态（令牌有效时返回当前用户信息；无效则返回401并自动跳转登录页）
      const user = await get<UserInfo>('/api/user/check.php');

      // 用户有效，更新缓存
      saveAuth(user);
      this.setData({
        isLoggedIn: true,
        userInfo: user,
        editNickname: user.nickname,
        editPhone: user.phone,
        editAddress: user.address,
      });

      // 加载订单概览
      this.loadOrderSummary();

      // 同步购物车角标（登录后才知道有几件商品）
      refreshCartBadge();

    } catch (err) {
      // 登录态失效，清除本地登录信息
      clearAuth();
      clearCartBadge();
      this.setData({
        isLoggedIn: false,
        userInfo: null,
        orderCounts: EMPTY_COUNTS,
        latestOrder: null,
      });
    }
  },

  /**
   * 加载订单概览：各状态数量 + 最近一笔订单
   * 接口：GET /api/order/list.php（不传 status 即返回全部）
   * 
   * 说明：接口一次性返回全部订单，因此各状态数量直接在前端统计，
   *      不必为每个状态各发一次请求（省请求，也不会出现数量不一致）。
   */
  async loadOrderSummary() {
    try {
      const orders = await get<Order[]>('/api/order/list.php');

      const counts: OrderCounts = { pending: 0, paid: 0, completed: 0, cancelled: 0 };

      orders.forEach(o => {
        const s = Number(o.status);
        const key = STATUS_KEYS[s];
        if (key !== undefined) {
          counts[key] += 1;
        }
      });

      // 最近一笔（后端已按创建时间倒序）
      let latest: Order | null = orders.length > 0 ? orders[0] : null;
      if (latest) {
        latest.status = Number(latest.status);
        latest.status_text = latest.status_text || OrderStatusMap[latest.status] || '未知';
        latest.created_at = this.formatTime(latest.created_at);
      }

      this.setData({ orderCounts: counts, latestOrder: latest });
    } catch (err) {
      console.error('加载订单概览失败：', err);
    }
  },

  /**
   * 格式化时间
   * 入参：'2025-01-15 10:30:00'
   * 返回：'2025-01-15 10:30'
   */
  formatTime(dateStr: string): string {
    if (!dateStr) return '';
    const date = new Date(dateStr.replace(/-/g, '/'));
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hour = String(date.getHours()).padStart(2, '0');
    const minute = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day} ${hour}:${minute}`;
  },

  /** 跳转登录页（微信一键登录，登录即注册，无需单独注册） */
  onGoLogin() {
    wx.navigateTo({ url: '/pages/login/login' });
  },

  /**
   * 跳转「我的订单」页
   * 
   * 支持从订单概览的数字直接带状态进入（data-status="0" → 只看待支付）：
   *   /pages/myOrders/myOrders?status=0
   * 
   * 注意：myOrders 不是 tabBar 页面，必须用 navigateTo（switchTab 会静默失败）
   */
  onGoMyOrders(e: any) {
    const raw = e && e.currentTarget ? e.currentTarget.dataset.status : undefined;
    const status = (raw === undefined || raw === null || raw === '') ? -1 : Number(raw);

    const url = (status >= 0)
      ? `/pages/myOrders/myOrders?status=${status}`
      : '/pages/myOrders/myOrders';

    wx.navigateTo({ url });
  },

  /** 退出登录 */
  onLogout() {
    wx.showModal({
      title: '确认退出',
      content: '确定要退出登录吗？',
      success: (res) => {
        if (res.confirm) {
          // 清除令牌与用户信息，并清掉购物车角标
          clearAuth();
          clearCartBadge();
          this.setData({
            isLoggedIn: false,
            userInfo: null,
            orderCounts: EMPTY_COUNTS,
            latestOrder: null,
          });
          wx.showToast({ title: '已退出登录', icon: 'success' });
        }
      },
    });
  },

  /** 进入编辑模式 */
  onEdit() {
    this.setData({ isEditing: true });
  },

  /** 取消编辑 */
  onCancelEdit() {
    this.setData({ isEditing: false });
    this.checkLoginStatus();
  },

  /**
   * 保存修改
   * 接口：POST /api/user/update.php（只能修改当前登录用户自己的信息）
   */
  async onSave() {
    if (!this.data.userInfo) return;

    wx.showLoading({ title: '保存中...' });
    try {
      const user = await post<UserInfo>('/api/user/update.php', {
        nickname: this.data.editNickname,
        phone: this.data.editPhone,
        address: this.data.editAddress,
      });

      // 保留登录令牌，仅更新用户信息
      saveAuth(user);
      this.setData({
        userInfo: user,
        isEditing: false,
      });
      wx.hideLoading();
      wx.showToast({ title: '保存成功', icon: 'success' });
    } catch (err) {
      wx.hideLoading();
      console.error('保存失败：', err);
    }
  },

  /** 输入处理 */
  onNickInput(e: any) { this.setData({ editNickname: e.detail.value }); },
  onPhoneInput(e: any) { this.setData({ editPhone: e.detail.value }); },
  onAddrInput(e: any) { this.setData({ editAddress: e.detail.value }); },

  /** 跳转关于页面 */
  onGoAbout() {
    wx.navigateTo({ url: '/pages/about/about' });
  },
});

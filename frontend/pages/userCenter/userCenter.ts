/**
 * 用户中心页面
 * 功能：显示/修改个人信息、跳转订单、关于页面、登录态判断、订单展示
 * 
 * 【鉴权说明】用户身份完全由登录令牌决定（见 utils/api.ts），
 *            因此不再需要向接口传 user_id，也无法查看他人数据。
 */
import { get, post, saveAuth, clearAuth } from '../../utils/api';
import { UserInfo, Order, OrderStatusMap } from '../../utils/types';

Page({
  data: {
    isLoggedIn: false,        // 是否已登录
    userInfo: null as UserInfo | null,
    recentOrders: [] as Order[],   // 最近订单（含待支付，下单后可立即看到）
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
   * 先检查本地缓存，再调用接口校验登录态是否仍有效
   */
  async checkLoginStatus() {
    const cachedUser = wx.getStorageSync('userInfo');
    
    if (!cachedUser) {
      // 未登录
      this.setData({
        isLoggedIn: false,
        userInfo: null,
        recentOrders: [],
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
      
      // 加载最近订单
      this.loadRecentOrders();
      
    } catch (err) {
      // 登录态失效，清除本地登录信息
      clearAuth();
      this.setData({
        isLoggedIn: false,
        userInfo: null,
        recentOrders: [],
      });
    }
  },

  /**
   * 加载最近订单（只取最近 3 条预览，完整列表在「我的订单」页）
   * 接口：GET /api/order/list.php（不传 status 即返回全部状态）
   * 
   * 【修复记录】原来这里只查"已完成"（status=2），并且传的是字符串 'completed'，
   *            MySQL 隐式转成 0，导致刚下单的"待支付"订单在用户中心完全看不到。
   *            现改为展示全部状态的最近订单，并把状态交给后端返回的 status_text。
   */
  async loadRecentOrders() {
    try {
      const orders = await get<Order[]>('/api/order/list.php');
      
      // 只展示最近 3 条
      const recent = orders.slice(0, 3);
      
      recent.forEach(o => {
        o.status = Number(o.status);
        o.status_text = o.status_text || OrderStatusMap[o.status] || '未知';
        o.created_at = this.formatTime(o.created_at);
      });
      
      this.setData({ recentOrders: recent });
    } catch (err) {
      console.error('加载订单失败：', err);
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
   * 跳转我的订单页
   * 注意：myOrders 不是 tabBar 页面，必须用 navigateTo（switchTab 会静默失败）
   */
  onGoMyOrders() {
    wx.navigateTo({ url: '/pages/myOrders/myOrders' });
  },

  /** 退出登录 */
  onLogout() {
    wx.showModal({
      title: '确认退出',
      content: '确定要退出登录吗？',
      success: (res) => {
        if (res.confirm) {
          // 清除令牌与用户信息
          clearAuth();
          this.setData({
            isLoggedIn: false,
            userInfo: null,
            recentOrders: [],
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

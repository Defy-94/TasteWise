/**
 * 用户中心页面
 * 功能：显示/修改个人信息、跳转订单、关于页面、登录态判断、订单展示
 */
import { get, post } from '../../utils/api';
import { UserInfo, Order } from '../../utils/types';

const app = getApp<IAppOption>();

Page({
  data: {
    isLoggedIn: false,        // 是否已登录
    userInfo: null as UserInfo | null,
    completedOrders: [] as Order[],  // 已完成的订单
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
   * 先检查本地缓存，再调用接口校验用户有效性
   */
  async checkLoginStatus() {
    const cachedUser = wx.getStorageSync('userInfo');
    
    if (!cachedUser) {
      // 未登录
      this.setData({
        isLoggedIn: false,
        userInfo: null,
        completedOrders: [],
      });
      return;
    }

    try {
      // 校验用户是否仍存在于数据库
      const user = await get<UserInfo>('/api/user/check.php', {
        user_id: cachedUser.id,
      });
      
      // 用户有效，更新缓存
      wx.setStorageSync('userInfo', user);
      this.setData({
        isLoggedIn: true,
        userInfo: user,
        editNickname: user.nickname,
        editPhone: user.phone,
        editAddress: user.address,
      });
      
      // 加载已完成的订单
      this.loadCompletedOrders(user.id);
      
    } catch (err) {
      // 用户不存在，清除缓存
      wx.removeStorageSync('userInfo');
      this.setData({
        isLoggedIn: false,
        userInfo: null,
        completedOrders: [],
      });
    }
  },

  /**
   * 加载已完成的订单
   * 接口：GET /api/order/list.php?user_id=xx&status=completed
   */
  async loadCompletedOrders(userId: number) {
    try {
      const orders = await get<Order[]>('/api/order/list.php', {
        user_id: userId,
        status: 'completed',
      });
      
      // 格式化订单时间
      orders.forEach(o => {
        o.created_at = this.formatTime(o.created_at);
      });
      
      this.setData({ completedOrders: orders });
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

  /** 跳转注册页（纯页面跳转） */
  onGoRegister() {
    wx.navigateTo({ url: '/pages/register/register' });
  },

  /** 跳转登录页（纯页面跳转） */
  onGoLogin() {
    wx.navigateTo({ url: '/pages/login/login' });
  },

  /** 退出登录 */
  onLogout() {
    wx.showModal({
      title: '确认退出',
      content: '确定要退出登录吗？',
      success: (res) => {
        if (res.confirm) {
          wx.removeStorageSync('userInfo');
          this.setData({
            isLoggedIn: false,
            userInfo: null,
            completedOrders: [],
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
   * 接口：POST /api/user/update.php
   */
  async onSave() {
    if (!this.data.userInfo) return;
    
    wx.showLoading({ title: '保存中...' });
    try {
      const user = await post<UserInfo>('/api/user/update.php', {
        user_id: this.data.userInfo.id,
        nickname: this.data.editNickname,
        phone: this.data.editPhone,
        address: this.data.editAddress,
      });
      
      wx.setStorageSync('userInfo', user);
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
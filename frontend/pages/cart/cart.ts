/**
 * 购物车页面
 * 功能：购物车列表、移除商品、结算下单
 * 
 * 【鉴权说明】请求自动携带登录令牌（见 utils/api.ts），
 *            用户身份由令牌决定，因此不再向接口传 user_id。
 */
import { get, post, getToken } from '../../utils/api';
import { CartItem } from '../../utils/types';

Page({
  data: {
    cartList: [] as CartItem[],
    totalPrice: 0,
    isEmpty: true,
    needLogin: false,     // 未登录时显示登录提示
  },

  onShow() {
    // 未登录时不发请求，直接展示登录提示
    if (!getToken()) {
      this.setData({
        cartList: [],
        totalPrice: 0,
        isEmpty: true,
        needLogin: true,
      });
      return;
    }

    this.setData({ needLogin: false });
    this.loadCart();
  },

  /**
   * 加载购物车
   * 接口：GET /api/cart/list.php（用户身份由令牌决定）
   */
  async loadCart() {
    try {
      const list = await get<CartItem[]>('/api/cart/list.php');
      const total = list.reduce((sum, item) => sum + item.price * item.quantity, 0);
      this.setData({
        cartList: list,
        totalPrice: total,
        isEmpty: list.length === 0,
      });
    } catch (err) {
      console.error('加载购物车失败：', err);
    }
  },

  /**
   * 删除购物车项
   * 接口：POST /api/cart/remove.php
   * 说明：后端会同时校验该记录是否属于当前登录用户（防越权删除他人数据）
   */
  async onRemove(e: any) {
    const cartId = e.currentTarget.dataset.id;
    
    if (!cartId) {
      wx.showToast({ title: '获取购物车ID失败', icon: 'none' });
      return;
    }

    wx.showModal({
      title: '确认删除',
      content: '确定要移除这个商品吗？',
      success: async (res) => {
        if (res.confirm) {
          try {
            await post('/api/cart/remove.php', { cart_id: cartId });
            wx.showToast({ title: '已移除', icon: 'success' });
            this.loadCart();
          } catch (err) {
            console.error('移除失败：', err);
          }
        }
      },
    });
  },

  /**
   * 结算下单
   * 接口：POST /api/order/create.php
   * 说明：下单后订单状态为「待支付」，可在订单页继续支付
   */
  async onCheckout() {
    if (this.data.cartList.length === 0) {
      wx.showToast({ title: '购物车为空', icon: 'none' });
      return;
    }

    wx.showLoading({ title: '正在下单...' });
    
    try {
      const result = await post('/api/order/create.php', {
        remark: '',
      });
      
      // ✅ 成功时隐藏loading
      wx.hideLoading();
      
      wx.showToast({ title: '下单成功', icon: 'success' });
      
      // 跳转到订单页（可在订单页完成支付）
      setTimeout(() => {
        wx.switchTab({ url: '/pages/myOrders/myOrders' });
      }, 1500);
      
    } catch (err) {
      // ✅ 失败时也要隐藏loading
      wx.hideLoading();
      console.error('下单失败：', err);
    }
  },

  /** 跳转登录页 */
  onGoLogin() {
    wx.navigateTo({ url: '/pages/login/login' });
  },
});

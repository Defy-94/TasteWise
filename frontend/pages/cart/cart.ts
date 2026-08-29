import { get, post } from '../../utils/api';
import { CartItem } from '../../utils/types';

const app = getApp<IAppOption>();

Page({
  data: {
    cartList: [] as CartItem[],
    totalPrice: 0,
    isEmpty: true,
  },

  onShow() {
    this.loadCart();
  },

  /**
   * 加载购物车
   */
  async loadCart() {
    try {
      const list = await get<CartItem[]>('/api/cart/list.php', {
        user_id: app.globalData.userId,
      });
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
   */
  async onCheckout() {
    if (this.data.cartList.length === 0) {
      wx.showToast({ title: '购物车为空', icon: 'none' });
      return;
    }

    wx.showLoading({ title: '正在下单...' });
    
    try {
      const result = await post('/api/order/create.php', {
        user_id: app.globalData.userId,
        remark: '',
      });
      
      // ✅ 成功时隐藏loading
      wx.hideLoading();
      
      wx.showToast({ title: '下单成功', icon: 'success' });
      
      // 跳转到订单页
      setTimeout(() => {
        wx.switchTab({ url: '/pages/myOrders/myOrders' });
      }, 1500);
      
    } catch (err) {
      // ✅ 失败时也要隐藏loading
      wx.hideLoading();
      console.error('下单失败：', err);
    }
  },
});
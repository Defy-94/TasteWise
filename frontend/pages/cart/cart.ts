/**
 * 购物车页面
 * 功能：购物车列表、调整数量、移除商品、填写备注、结算下单
 * 
 * 【鉴权说明】请求自动携带登录令牌（见 utils/api.ts），
 *            用户身份由令牌决定，因此不再向接口传 user_id。
 */
import { get, post, getToken } from '../../utils/api';
import { CartItem } from '../../utils/types';
import { setCartBadge } from '../../utils/cartBadge';
import { errorMessage, logError } from '../../utils/error';

Page({
  data: {
    cartList: [] as CartItem[],
    totalPrice: 0,
    isEmpty: true,
    needLogin: false,     // 未登录时显示登录提示
    updating: false,      // 数量调整中：防止快速连点导致并发请求乱序
    remark: '',           // 订单备注（结算时提交给后端）
    loadError: '',        // 加载失败原因（非空时显示错误态而不是"购物车为空"）
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
      setCartBadge(0);
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
    this.setData({ loadError: '' });

    try {
      // silentError：加载失败时本页会渲染错误态，请求层不必再弹一次提示
      const list = await get<CartItem[]>('/api/cart/list.php', {}, { silentError: true });
      const total = list.reduce((sum, item) => sum + item.price * item.quantity, 0);
      this.setData({
        cartList: list,
        totalPrice: total,
        isEmpty: list.length === 0,
      });

      // 顺便刷新 TabBar 角标（列表已经在手，无需额外请求）
      const count = list.reduce((sum, item) => sum + Number(item.quantity), 0);
      setCartBadge(count);
    } catch (err) {
      logError('加载购物车失败', err);

      // 关键：把「加载失败」和「购物车为空」区分开 ——
      // 原来两种情况都是购物车空着，用户无法判断是没加过菜还是网络坏了
      this.setData({
        cartList: [],
        totalPrice: 0,
        isEmpty: true,
        loadError: errorMessage(err, '购物车加载失败，请检查网络后重试'),
      });
    }
  },

  /** 加载失败后重新加载 */
  onRetryLoad() {
    this.loadCart();
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
   * 调整数量（− / + 按钮）
   * 接口：POST /api/cart/update.php
   * 
   * 设计说明：后端采用「绝对设置」语义，目标数量由按钮的 data-num 算好传入；
   *          num 为 0 时后端会移除该条记录（此处先弹窗确认）。
   * 
   * ⚠️ 为什么要 updating 开关：用户快速连点会发出多个请求，
   *    而响应返回顺序不确定（后发的可能先回），最终数量会和用户预期不一致。
   */
  async onChangeQty(e: any) {
    const cartId = Number(e.currentTarget.dataset.id);
    const num    = Number(e.currentTarget.dataset.num);

    if (!cartId || isNaN(num)) return;

    // 上一次调整还没结束，先忽略本次点击
    if (this.data.updating) return;

    // 减到 0：先确认再移除
    if (num <= 0) {
      const ok = await this.confirmRemove();
      if (!ok) return;
    }

    this.setData({ updating: true });
    try {
      await post('/api/cart/update.php', { cart_id: cartId, quantity: num });
      await this.loadCart();
      if (num <= 0) {
        wx.showToast({ title: '已移除', icon: 'success', duration: 1000 });
      }
    } catch (err) {
      console.error('调整数量失败：', err);
    } finally {
      this.setData({ updating: false });
    }
  },

  /** 减到 0 时的确认弹窗（Promise 化，便于 await） */
  confirmRemove(): Promise<boolean> {
    return new Promise((resolve) => {
      wx.showModal({
        title: '确认移除',
        content: '数量已减到 0，确定要移除这个商品吗？',
        success: (res) => resolve(!!res.confirm),
        fail: () => resolve(false),
      });
    });
  },

  /** 订单备注输入 */
  onRemarkInput(e: any) {
    this.setData({ remark: e.detail.value });
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
        remark: this.data.remark.trim(),
      });
      
      // ✅ 成功时隐藏loading
      wx.hideLoading();
      
      // 购物车已被清空：同步角标与备注输入
      setCartBadge(0);
      this.setData({ remark: '' });
      
      wx.showToast({ title: '下单成功', icon: 'success' });
      
      // 跳转到订单页完成支付
      // ⚠️ myOrders 不是 tabBar 页面，必须用 navigateTo。
      //    原来用的是 switchTab，它只能跳 tabBar 页面（首页/购物车/AI助手/我的），
      //    跳非 tab 页会静默失败（无任何提示），表现为"点了结算没反应"。
      setTimeout(() => {
        wx.navigateTo({
          url: '/pages/myOrders/myOrders?status=0',   // 直接定位到"待支付"
          fail: (err: any) => {
            console.error('跳转订单页失败：', err);
            wx.showToast({
              title: '下单成功，请到「我的 → 我的订单」查看',
              icon: 'none',
              duration: 2500,
            });
          },
        });
      }, 1200);
      
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

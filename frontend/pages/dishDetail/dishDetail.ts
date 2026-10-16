/**
 * 菜品详情页：GET /api/dish/detail.php?id=x
 * 入口：首页菜品卡片、AI 助手推荐卡片
 * 下架菜品接口返回 404，页面进入错误态并显示后端给的原因，避免旧链接打开是空白页
 */
import { get, post } from '../../utils/api';
import { DishDetail } from '../../utils/types';
import { refreshCartBadge } from '../../utils/cartBadge';
import { errorMessage, logError } from '../../utils/error';
import { supplyLevel, supplyLabel, supplyDesc } from '../../utils/supply';

/** 辣度文字：0 不显示，1-5 显示对应数量的辣椒 */
function buildSpicyText(level: number): string {
  let text = '';
  const count = Math.max(0, Math.min(5, level));
  for (let i = 0; i < count; i++) {
    text += '🌶️';
  }
  return text;
}

Page({
  data: {
    dishId: 0,                             // 从 URL 带过来的菜品ID（重试时要用）
    dish: null as DishDetail | null,       // 菜品详情
    priceText: '',                         // 格式化后的价格文本，例如 ￥28.00
    spicyText: '',                         // 辣度可视化
    quantity: 1,                           // 本次要加入购物车的份数
    loading: true,                         // 是否正在加载
    loadError: '',                         // 加载失败原因（非空时显示错误态）
    // 供应情况：后端给的是份数，对外只呈现「充足 / 紧张 / 已售完」两档措辞
    supplyText: '',                        // 供应充足 / 供应紧张 / 已售完
    supplyDesc: '',                        // 面板里的一句说明
    supplyLevel: 'ok' as 'ok' | 'tight' | 'out',
  },

  onLoad(options: Record<string, string | undefined>) {
    const dishId = Number(options.id) || 0;

    if (dishId <= 0) {
      this.setData({ loading: false, loadError: '缺少菜品ID，无法打开详情' });
      return;
    }

    this.setData({ dishId });
    this.loadDish(dishId);
  },

  /** 拉取菜品详情：GET /api/dish/detail.php?id=x */
  async loadDish(dishId: number) {
    this.setData({ loading: true, loadError: '' });

    try {
      // silentError：本页会把失败原因渲染成持续的「错误态 + 重新加载」，
      // 请求层再弹一次 toast 就是同一件事提示两遍
      const dish = await get<DishDetail>('/api/dish/detail.php', { id: dishId }, { silentError: true });

      this.setData({
        dish,
        loading: false,
        // 价格在后端是数字，这里统一格式化成两位小数再显示，
        // 免得 28 显示成「¥28」而 28.5 显示成「¥28.5」，一行里价格长短不一
        priceText: '¥' + dish.price.toFixed(2),
        spicyText: buildSpicyText(dish.spiciness),
        // 份数不展示给顾客，只转成供应档位（见 utils/supply.ts）
        supplyText: supplyLabel(Number(dish.stock)),
        supplyDesc: supplyDesc(Number(dish.stock)),
        supplyLevel: supplyLevel(Number(dish.stock)),
      });

      // 把导航栏标题换成菜名，比一直显示"菜品详情"信息量大
      wx.setNavigationBarTitle({ title: dish.name });

    } catch (err: any) {
      logError('加载菜品详情失败', err);

      // 后端在下架/不存在时返回 404，msg 里已写明原因，直接展示给顾客
      this.setData({
        loading: false,
        loadError: errorMessage(err, '菜品加载失败，请稍后重试'),
      });
    }
  },

  /** 减少份数（最少 1 份） */
  onMinus() {
    const quantity = this.data.quantity;
    if (quantity > 1) {
      this.setData({ quantity: quantity - 1 });
    }
  },

  /** 增加份数（不超过库存） */
  onPlus() {
    const dish = this.data.dish;
    if (!dish) {
      return;
    }

    const quantity = this.data.quantity;
    if (quantity >= dish.stock) {
      wx.showToast({ title: '已达库存上限', icon: 'none' });
      return;
    }

    this.setData({ quantity: quantity + 1 });
  },

  /** 加入购物车：POST /api/cart/add.php */
  async onAddToCart() {
    const dish = this.data.dish;
    if (!dish) {
      return;
    }

    // 先在本地拦一道。本地判断不能替代后端校验（并发下单时库存随时会变），
    // 只是为了让「明明已售完还让顾客点一下再报错」这种体验少发生一次。
    if (dish.stock <= 0) {
      wx.showToast({ title: '该菜品已售完', icon: 'none' });
      return;
    }

    const quantity = this.data.quantity;

    try {
      wx.showLoading({ title: '加入中...' });
      await post('/api/cart/add.php', {
        dish_id: dish.id,
        quantity: quantity,
      });
      wx.hideLoading();

      wx.showToast({ title: '已加入购物车', icon: 'success' });

      // 同步底部购物车角标
      refreshCartBadge();

      // 库存已经被扣掉一部分，重新拉一次详情，
      // 否则页面上显示的还是加购之前的库存数，顾客会以为还能再加那么多
      this.setData({ quantity: 1 });
      await this.loadDish(dish.id);

    } catch (err) {
      wx.hideLoading();
      console.error('加入购物车失败：', err);
    }
  },

  /** 去购物车：tabBar 页面只能用 switchTab */
  onGoCart() {
    wx.switchTab({ url: '/pages/cart/cart' });
  },

  /** 错误态下重新加载 */
  onRetry() {
    const dishId = this.data.dishId;
    if (dishId > 0) {
      this.loadDish(dishId);
    }
  },

  /**
   * 主图加载失败：清掉 image_url，让模板的 wx:else 兜底图接管
   *
   * 菜品图是第三方 CDN 的热链，随时可能失效；没有这一步就是一块空白。
   */
  onHeroImgError() {
    const dish = this.data.dish;

    if (!dish || !dish.image_url) return;

    this.setData({ dish: { ...dish, image_url: '' } });
  },
});

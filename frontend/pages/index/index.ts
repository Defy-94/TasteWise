/**
 * 首页 - 菜品列表
 * 接口：/api/category/list.php、/api/dish/list.php、/api/cart/add.php
 */
import { get, post } from '../../utils/api';
import { Category, Dish } from '../../utils/types';
import { refreshCartBadge } from '../../utils/cartBadge';
import { errorMessage, logError } from '../../utils/error';
import { supplyLabel } from '../../utils/supply';

/** 搜索输入防抖（毫秒）：每个按键都发请求既浪费也会让结果闪烁 */
const SEARCH_DEBOUNCE_MS = 350;

Page({
  data: {
    categories: [] as Category[],      // 分类列表
    dishes: [] as Dish[],              // 菜品列表
    activeCategoryId: 0,               // 当前选中分类(0=全部)
    searchKeyword: '',                 // 当前搜索关键词
    loadingDishes: false,              // 是否正在加载菜品（用来区分「空结果」和「还没加载完」）
    loadError: '',                     // 加载失败原因（非空时显示错误态而不是空结果）
  },

  onLoad() {
    this.loadCategories();
    this.loadDishes();
    this.cleanupLegacyTipFlag();
  },

  /** 清掉早期版本的浮窗显示标记（已不再使用） */
  cleanupLegacyTipFlag() {
    try {
      wx.removeStorageSync('xiaowei_tip_shown');
    } catch (e) {
      // 存储不可用时忽略即可，不影响任何功能
    }
  },

  onShow() {
    // 每次回到首页同步一次购物车角标
    // （内部用 silent401 请求，未登录或令牌失效都不会把用户弹到登录页）
    refreshCartBadge();
  },

  onUnload() {
    // 离开页面时清掉未触发的防抖定时器，避免它打到已销毁的页面上
    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
      this.searchTimer = null;
    }
  },

  /** 点击小味浮窗：进 AI 助手页 */
  onTapXiaoweiTip() {
    wx.switchTab({ url: '/pages/aiChat/aiChat' });
  },

  /** 加载分类列表：GET /api/category/list.php */
  async loadCategories() {
    try {
      const categories = await get<Category[]>('/api/category/list.php');
      // 添加"全部"选项
      categories.unshift({ id: 0, name: '全部', sort_order: 999, icon_url: '' });
      this.setData({ categories });
    } catch (err) {
      console.error('加载分类失败：', err);
    }
  },

  /**
   * 加载菜品列表：GET /api/dish/list.php?category_id=xx&keyword=xx
   * 分类与关键词都从入参取（读 this.data 容易在切分类时丢关键词）；
   * 不回写 searchKeyword，否则返回比打字慢时会覆盖掉用户刚输入的内容。
   */
  async loadDishes(categoryId: number = 0, keyword: string = '') {
    this.setData({ loadingDishes: true, loadError: '' });

    try {
      const params: any = {};
      if (categoryId > 0) {
        params.category_id = categoryId;
      }
      if (keyword !== '') {
        params.keyword = keyword;
      }

      // silentError：加载失败时本页会渲染「加载失败 + 重新加载」的错误态，
      // 请求层再弹一次 toast 就是同一件事提示两遍
      const dishes = await get<Dish[]>('/api/dish/list.php', params, { silentError: true });

      // 给每道菜补一个「供应充足 / 供应紧张 / 已售完」短标签，
      // 视图层只显示这个措辞，不暴露具体份数（见 utils/supply.ts 的说明）
      this.setData({
        dishes: (dishes || []).map((d) => ({ ...d, supplyLabel: supplyLabel(Number(d.stock)) })),
        activeCategoryId: categoryId,
        loadingDishes: false,
      });
    } catch (err) {
      logError('加载菜品失败', err);

      // 关键：把「加载失败」和「空结果」区分开。
      // 否则网络一断，用户看到的是「该分类下暂时没有菜品」——
      // 会以为店里没菜，而不是「网络有问题，可以重试」。
      this.setData({
        dishes: [],
        activeCategoryId: categoryId,
        loadingDishes: false,
        loadError: errorMessage(err, '菜品加载失败，请检查网络后重试'),
      });
    }
  },

  /** 加载失败后重新加载（错误态里的按钮） */
  onRetryLoad() {
    this.loadDishes(this.data.activeCategoryId, this.data.searchKeyword);
  },

  /**
   * 搜索框输入：防抖后自动搜索
   */
  onSearchInput(e: any) {
    const keyword = (e.detail.value || '').trim();

    // 先把输入框内容回写，保证受控输入不卡顿
    this.setData({ searchKeyword: keyword });

    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
    }

    this.searchTimer = setTimeout(() => {
      this.searchTimer = null;
      this.applySearch(keyword);
    }, SEARCH_DEBOUNCE_MS);
  },

  /** 键盘上点「搜索」：立即出结果，不等防抖 */
  onSearchConfirm() {
    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
      this.searchTimer = null;
    }
    this.applySearch(this.data.searchKeyword);
  },

  /** 清空搜索，回到全部菜品 */
  onClearSearch() {
    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
      this.searchTimer = null;
    }
    this.setData({ searchKeyword: '' });
    this.loadDishes(0, '');
  },

  /** 执行搜索：同时把分类重置为「全部」，否则会因两个条件叠加而搜出空列表 */
  applySearch(keyword: string) {
    this.setData({ activeCategoryId: 0 });
    this.loadDishes(0, keyword);
  },

  /** 切换分类：带上当前关键词（分类与搜索两个条件同时生效） */
  onCategoryTap(e: any) {
    const categoryId = e.currentTarget.dataset.id;
    this.loadDishes(categoryId, this.data.searchKeyword);
  },

  /** 打开菜品详情：pages/dishDetail */
  onDishTap(e: any) {
    const dishId = e.currentTarget.dataset.id;
    if (!dishId) {
      return;
    }

    wx.navigateTo({
      url: '/pages/dishDetail/dishDetail?id=' + dishId,
    });
  },

  /** 加入购物车：POST /api/cart/add.php */
  async onAddToCart(e: any) {
    const dishId = e.currentTarget.dataset.id;

    // 防御性检查：视图层已经把售罄的菜换成不可点的「已售完」，
    // 这里再拦一道，避免以后改版式时又漏掉（详情页也是同样的双重保险）
    const dish = this.data.dishes.find((d) => d.id === Number(dishId));
    if (dish && Number(dish.stock) <= 0) {
      wx.showToast({ title: '该菜品已售完', icon: 'none' });
      return;
    }

    try {
      await post('/api/cart/add.php', {
        dish_id: dishId,
        quantity: 1,
      });
      wx.showToast({ title: '已加入购物车', icon: 'success' });
      // 同步底部购物车角标
      refreshCartBadge();
    } catch (err) {
      logError('加入购物车失败', err);
    }
  },

});
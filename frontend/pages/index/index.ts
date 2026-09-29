/**
 * 首页 - 菜品列表
 * 功能：分类导航、菜品搜索、菜品展示、AI推荐、加入购物车
 */
import { get, post } from '../../utils/api';
import { Category, Dish, AIRecommendResult } from '../../utils/types';
import { refreshCartBadge } from '../../utils/cartBadge';
import { errorMessage, logError } from '../../utils/error';

/**
 * 搜索输入的防抖时间（毫秒）
 *
 * 边打字边搜体验更好，但每个按键都发一次请求既浪费也会让结果闪烁。
 * 350ms 是「停下来想下一个字」和「感觉是实时的」之间的常见折中。
 */
const SEARCH_DEBOUNCE_MS = 350;

Page({
  data: {
    categories: [] as Category[],      // 分类列表
    dishes: [] as Dish[],              // 菜品列表
    activeCategoryId: 0,               // 当前选中分类(0=全部)
    searchKeyword: '',                 // 当前搜索关键词
    loadingDishes: false,              // 是否正在加载菜品（用来区分「空结果」和「还没加载完」）
    loadError: '',                     // 加载失败原因（非空时显示错误态而不是空结果）
    recommendDishes: [] as Dish[],     // AI推荐菜品
    showRecommend: false,              // 是否显示推荐弹窗
    preferenceInput: '',               // 用户偏好输入
  },

  onLoad() {
    this.loadCategories();
    this.loadDishes();
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

  /**
   * 加载分类列表
   * 接口：GET /api/category/list.php
   */
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
   * 加载菜品列表
   * 接口：GET /api/dish/list.php?category_id=xx&keyword=xx
   *
   * 分类与关键词是两个独立条件，会同时生效 —— 所以这里都从入参取，
   * 而不是在函数里读 this.data（避免「切分类时把关键词丢了」这类问题）。
   *
   * 注意：这里**不**回写 searchKeyword。否则请求返回得比用户打字慢时，
   * 会把搜索框里的新内容覆盖回旧值，表现为「打字被打断」。
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
      this.setData({ dishes, activeCategoryId: categoryId, loadingDishes: false });
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

  /**
   * 执行搜索
   *
   * 搜索时会把分类重置成「全部」：否则用户在「热菜」分类里搜「汤」，
   * 会因为分类与关键词同时生效而得到空列表 ——
   * 而他并不知道"当前分类也在参与筛选"，只会以为搜索坏了。
   */
  applySearch(keyword: string) {
    this.setData({ activeCategoryId: 0 });
    this.loadDishes(0, keyword);
  },

  /**
   * 切换分类
   *
   * 关键词会一起带过去 —— 分类和搜索是两个独立条件，同时生效。
   * （搜索时我们主动把分类重置成「全部」，但用户之后仍可以再选分类，
   *   那时应该在他的关键词范围内筛选，而不是悄悄把关键词丢掉。）
   */
  onCategoryTap(e: any) {
    const categoryId = e.currentTarget.dataset.id;
    this.loadDishes(categoryId, this.data.searchKeyword);
  },

  /**
   * 打开菜品详情
   * 对应页面：pages/dishDetail
   * 触发位置：菜品卡片、AI推荐弹窗里的推荐项
   */
  onDishTap(e: any) {
    const dishId = e.currentTarget.dataset.id;
    if (!dishId) {
      return;
    }

    wx.navigateTo({
      url: '/pages/dishDetail/dishDetail?id=' + dishId,
    });
  },

  /**
   * 加入购物车
   * 对应后端接口：POST /api/cart/add.php
   * 对应按钮：菜品卡片"加入购物车"
   */
  async onAddToCart(e: any) {
    const dishId = e.currentTarget.dataset.id;
    try {
      await post('/api/cart/add.php', {
        dish_id: dishId,
        quantity: 1,
      });
      wx.showToast({ title: '已加入购物车', icon: 'success' });
      // 同步底部购物车角标
      refreshCartBadge();
    } catch (err) {
      console.error('加入购物车失败：', err);
    }
  },

  /**
   * AI智能推荐
   * 对应后端接口：POST /api/dish/recommend.php
   * 对应按钮：首页顶部"AI推荐"按钮
   */
  async onAIRecommend() {
    const preference = this.data.preferenceInput.trim();
    if (!preference) {
      wx.showToast({ title: '请描述您的口味偏好', icon: 'none' });
      return;
    }

    wx.showLoading({ title: 'AI正在为您推荐...' });
    try {
      const result = await post<AIRecommendResult>('/api/dish/recommend.php', {
        preference: preference,
      });
      this.setData({
        recommendDishes: result.recommendations || [],
        showRecommend: true,
      });
      wx.hideLoading();
    } catch (err) {
      wx.hideLoading();
      console.error('AI推荐失败：', err);
    }
  },

  /** 关闭推荐弹窗 */
  onCloseRecommend() {
    this.setData({ showRecommend: false });
  },

  /** 偏好输入框变化 */
  onPreferenceInput(e: any) {
    this.setData({ preferenceInput: e.detail.value });
  },
});
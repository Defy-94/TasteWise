/**
 * 首页 - 菜品列表
 * 功能：分类导航、菜品展示、AI推荐、加入购物车
 */
import { get, post } from '../../utils/api';
import { Category, Dish, AIRecommendResult } from '../../utils/types';
import { refreshCartBadge } from '../../utils/cartBadge';

Page({
  data: {
    categories: [] as Category[],      // 分类列表
    dishes: [] as Dish[],              // 菜品列表
    activeCategoryId: 0,               // 当前选中分类(0=全部)
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
   * 接口：GET /api/dish/list.php?category_id=xx
   */
  async loadDishes(categoryId: number = 0) {
    try {
      const params: any = {};
      if (categoryId > 0) {
        params.category_id = categoryId;
      }
      const dishes = await get<Dish[]>('/api/dish/list.php', params);
      this.setData({ dishes, activeCategoryId: categoryId });
    } catch (err) {
      console.error('加载菜品失败：', err);
    }
  },

  /**
   * 切换分类
   * 纯页面逻辑，无接口
   */
  onCategoryTap(e: any) {
    const categoryId = e.currentTarget.dataset.id;
    this.loadDishes(categoryId);
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
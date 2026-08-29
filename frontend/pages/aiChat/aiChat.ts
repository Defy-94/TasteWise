/**
 * AI助手页面 - DeepSeek智能客服对话
 * 功能：发送消息、显示AI回复
 * 支持AI推荐菜品卡片展示 + 快捷加购物车
 */
import { post } from '../../utils/api';
import { ChatMessageExtended, RecommendDish, AIChatResult } from '../../utils/types';

const app = getApp<IAppOption>();

Page({
  data: {
    messages: [] as ChatMessageExtended[],
    inputText: '',
    scrollToView: '',
  },

  onLoad() {
    this.setData({
      messages: [
        { 
          role: 'assistant', 
          content: '您好！我是AI点餐助手，可以帮您推荐菜品、解答食材口味问题。试试说"推荐几道辣菜"吧~' 
        }
      ]
    });
  },

  /**
   * 发送消息
   * 接口：POST /api/ai/chat.php
   * 对应按钮：底部"发送"按钮
   */
  async onSend() {
    const text = this.data.inputText.trim();
    if (!text) return;

    // 添加用户消息
    const userMsg: ChatMessageExtended = { role: 'user', content: text };
    const messages = [...this.data.messages, userMsg];
    
    this.setData({
      messages,
      inputText: '',
      scrollToView: 'msg-' + (messages.length - 1),
    });

    wx.showLoading({ title: '思考中...' });
    
    try {
      // 构建历史对话（排除第一条欢迎语）
      const history = messages.slice(1).map(m => ({
        role: m.role,
        content: m.content,
      }));

      const result = await post<AIChatResult>('/api/ai/chat.php', {
        message: text,
        history: history,
      });

      // 构建AI回复消息（含推荐菜品）
      const aiMsg: ChatMessageExtended = {
        role: 'assistant',
        content: result.reply,
        recommendDishes: result.recommend_dishes || [],
      };
      
      const newMessages = [...this.data.messages, aiMsg];
      
      this.setData({
        messages: newMessages,
        scrollToView: 'msg-' + (newMessages.length - 1),
      });

      wx.hideLoading();
    } catch (err) {
      wx.hideLoading();
      console.error('AI回复失败：', err);
    }
  },

  /**
   * 快捷加入购物车（从AI推荐的菜品卡片）
   * 接口：POST /api/cart/add.php
   * 对应按钮：菜品卡片右下角"加入"按钮
   */
  async onQuickAddCart(e: any) {
    const dishId = e.currentTarget.dataset.id;
    const dishName = e.currentTarget.dataset.name;
    
    if (!dishId) return;

    try {
      await post('/api/cart/add.php', {
        user_id: app.globalData.userId,
        dish_id: dishId,
        quantity: 1,
      });
      
      wx.showToast({ title: `「${dishName}」已加入购物车`, icon: 'success' });
    } catch (err) {
      console.error('加入购物车失败：', err);
    }
  },

  /**
   * 查看菜品详情（预留，可跳转详情页或显示弹窗）
   */
  onDishDetail(e: any) {
    const dishName = e.currentTarget.dataset.name;
    wx.showToast({ title: `查看「${dishName}」详情`, icon: 'none' });
    // 后续可跳转: wx.navigateTo({ url: '/pages/dishDetail/dishDetail?id=' + dishId })
  },

  /** 输入框变化 */
  onInputChange(e: any) {
    this.setData({ inputText: e.detail.value });
  },
});
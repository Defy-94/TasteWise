/**
 * AI助手页面 - DeepSeek 智能点餐助手对话
 * 功能：对话、推荐菜品卡片、快捷加入购物车
 * 
 * 【接口说明】
 *   - /api/ai/chat.php 为公开接口（无需登录）
 *   - 「加入购物车」需要登录，未登录时会由请求层统一跳转登录页
 */
import { post } from '../../utils/api';
import { ChatMessageExtended, AIChatResult } from '../../utils/types';
import { refreshCartBadge } from '../../utils/cartBadge';

// 开场白
const WELCOME = '您好！我是 AI 点餐助手「小味」，可以帮您推荐菜品、解答食材口味问题。试试说「推荐几道辣菜」吧~';

// 快捷提问（仅在对话刚开始时显示，点一下即发送）
const QUICK_QUESTIONS = [
  '推荐几道辣菜',
  '有什么清淡的菜',
  '帮我搭配一荤一素',
  '有什么汤品推荐',
];

Page({
  data: {
    messages: [] as ChatMessageExtended[],
    inputText: '',
    scrollToView: '',
    sending: false,                 // AI 正在回复（显示"正在输入"气泡）
    quickQuestions: QUICK_QUESTIONS,
  },

  onLoad() {
    this.setData({
      messages: [{ role: 'assistant', content: WELCOME }],
    });
  },

  /** 点击「发送」按钮 / 键盘发送 */
  async onSend() {
    const text = this.data.inputText.trim();
    if (!text) return;

    this.setData({ inputText: '' });
    await this.sendText(text);
  },

  /** 快捷提问：点击直接发送 */
  async onQuickAsk(e: any) {
    const text = e.currentTarget.dataset.text;
    if (!text) return;

    await this.sendText(text);
  },

  /**
   * 发送一条消息并获取 AI 回复
   * 接口：POST /api/ai/chat.php
   * 
   * 说明：不再使用 wx.showLoading 的模态遮罩，而是显示「正在输入」气泡，
   *      这样等待期间界面不会被锁住、用户也能继续看到历史对话。
   */
  async sendText(text: string) {
    // 上一条还在回复中：忽略这次发送，避免并发请求
    if (this.data.sending) return;

    const userMsg: ChatMessageExtended = { role: 'user', content: text };
    const messages = [...this.data.messages, userMsg];

    this.setData({
      messages,
      sending: true,
      scrollToView: 'msg-typing',
    });

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

      const aiMsg: ChatMessageExtended = {
        role: 'assistant',
        content: result.reply,
        recommendDishes: result.recommend_dishes || [],
      };

      const newMessages = [...this.data.messages, aiMsg];

      this.setData({
        messages: newMessages,
        sending: false,
        scrollToView: 'msg-' + (newMessages.length - 1),
      });
    } catch (err) {
      // 失败也要结束"正在输入"，并给用户一句明确回复，避免界面一直转圈
      console.error('AI回复失败：', err);

      const failMsg: ChatMessageExtended = {
        role: 'assistant',
        content: '抱歉，我这边出了点小问题，请稍后再试一次~',
      };
      const newMessages = [...this.data.messages, failMsg];

      this.setData({
        messages: newMessages,
        sending: false,
        scrollToView: 'msg-' + (newMessages.length - 1),
      });
    }
  },

  /**
   * 打开菜品详情（点击 AI 推荐卡片）
   * 对应页面：pages/dishDetail
   */
  onDishTap(e: any) {
    const dishId = e.currentTarget.dataset.id;
    if (!dishId) return;

    wx.navigateTo({
      url: '/pages/dishDetail/dishDetail?id=' + dishId,
    });
  },

  /**
   * 快捷加入购物车（从AI推荐的菜品卡片）
   * 接口：POST /api/cart/add.php
   */
  async onQuickAddCart(e: any) {
    const dishId = e.currentTarget.dataset.id;
    const dishName = e.currentTarget.dataset.name;

    if (!dishId) return;

    try {
      await post('/api/cart/add.php', {
        dish_id: dishId,
        quantity: 1,
      });

      wx.showToast({ title: `「${dishName}」已加入购物车`, icon: 'success' });
      // 同步底部购物车角标
      refreshCartBadge();
    } catch (err) {
      console.error('加入购物车失败：', err);
    }
  },

  /** 输入框变化 */
  onInputChange(e: any) {
    this.setData({ inputText: e.detail.value });
  },
});

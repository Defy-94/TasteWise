/**
 * AI助手页面 - DeepSeek 智能点餐助手对话
 * 功能：对话（流式输出）、推荐菜品卡片、快捷加入购物车
 *
 * 【接口说明】
 *   - /api/ai/chat_stream.php 流式版（SSE），基础库 ≥ 2.20.2 时优先使用
 *   - /api/ai/chat.php        非流式版，作为不支持分块接收时的回退
 *   - 两者都用同一个 AI 额度，限流也是同一套（见 core/AiRateLimit.php）
 *   - 「加入购物车」需要登录，未登录时会由请求层统一跳转登录页
 */
import { post } from '../../utils/api';
import { ChatMessageExtended, ChatMessage, AIChatResult, RecommendDish } from '../../utils/types';
import { refreshCartBadge } from '../../utils/cartBadge';
import { streamChat, supportsChunked, StreamTask } from '../../utils/sse';

// 开场白
const WELCOME = '您好！我是 AI 点餐助手「小味」，可以帮您推荐菜品、解答食材口味问题。试试说「推荐几道辣菜」吧~';

// 小味头像路径（将来图片搬去 CDN，只改这一行）
const AVATAR_SRC = '/assets/xiaowei/ui/xiaowei-avatar.png';

/** 「道谢」「抱歉」这类收尾表情停留多久后收回 idle */
const GOODBYE_HOLD_MS = 2400;
const SORRY_HOLD_MS = 2600;

/** 用户话里出现这些词，认为是结束语 */
const GOODBYE_WORDS = ['再见', '拜拜', '谢谢', '多谢', '感谢', 'bye', 'thanks', 'thank you'];

// 快捷提问（仅在对话刚开始时显示，点一下即发送）
const QUICK_QUESTIONS = [
  '推荐几道辣菜',
  '有什么清淡的菜',
  '帮我搭配一荤一素',
  '有什么汤品推荐',
];

/**
 * 流式输出的刷新间隔（毫秒）
 *
 * 为什么要节流：AI 可能每几十毫秒就吐一个词，如果每来一个字就 setData 一次，
 * 一次回复会触发上百次视图更新，在低端机上会明显卡顿。
 * 合并成每 80ms 刷新一次，肉眼看上去依旧是"逐字蹦出来"的连续效果。
 */
const STREAM_FLUSH_MS = 80;

/**
 * 最多携带多少条历史消息（一问一答算 2 条）
 *
 * 为什么要有上限：第 N 轮请求要把前面 N-1 轮的内容全部重发一遍，
 * 成本是 O(N²)，而且迟早会超出模型的上下文窗口、请求直接失败。
 * 服务端也会再洗一遍（不能只信客户端），这里截断主要是为了少传数据。
 */
const MAX_HISTORY_MESSAGES = 12;

Page({
  data: {
    messages: [] as ChatMessageExtended[],
    inputText: '',
    scrollToView: '',
    sending: false,                 // 等待 AI 首个响应（显示"正在输入"三点动画）
    streamingText: '',              // 流式输出中的文本（非空时优先显示它）
    quickQuestions: QUICK_QUESTIONS,

    // ---- 小味舞台区 ----
    avatarSrc: AVATAR_SRC,          // 消息头像 / 顶部信息条头像
    avatarFailed: false,            // 头像加载失败 → 回落成占位表情
    stageState: 'greeting',         // 舞台区状态：见 components/xiaowei-stage
  },

  onLoad() {
    this.setData({
      messages: [{ role: 'assistant', content: WELCOME }],
      stageState: 'greeting',       // 首次进入先举手打招呼
    });
  },

  onUnload() {
    // 离开页面时中断流式请求，避免后台还在拉数据、回调打到已销毁的页面上
    this.abortStream();
    this.clearStageTimers();
  },

  /* ============================================================
     小味舞台区状态机
     ============================================================ */

  /**
   * 切换小味的表情状态
   *
   * 状态名与素材文件一一对应（components/xiaowei-stage/index.js 里有完整表）：
   *   greeting 首次招呼 / thinking 思考 / happy 开心（有推荐或加购成功）
   *   sorry 出错 / goodbye 道谢 / idle 待命
   *   注：searching（翻菜单）、proud（得意）两个状态已按需求删除，
   *       点餐场景响应快，不需要"长时间翻菜单"，加购成功直接用 happy 表达。
   *
   * 组件内部还有"最短展示时长"保护，所以这里可以放心地连续调用，
   * 不会出现 thinking 一闪而过的情况。
   */
  setStageState(state: string) {
    if (this.data.stageState !== state) {
      this.setData({ stageState: state });
    }
  },

  /** 延迟把表情收回 idle（收尾类表情用） */
  holdStageState(state: string, ms: number) {
    this.setStageState(state);
    if (this.stageRevertTimer) clearTimeout(this.stageRevertTimer);
    this.stageRevertTimer = setTimeout(() => {
      this.stageRevertTimer = null;
      // 期间如果又发起了新请求，就不要把状态抢回来
      if (!this.data.sending && !this.data.streamingText) {
        this.setStageState('idle');
      }
    }, ms);
  },

  /** 清理舞台区的计时器（离开页面、开始新一轮请求时调用） */
  clearStageTimers() {
    if (this.stageRevertTimer) {
      clearTimeout(this.stageRevertTimer);
      this.stageRevertTimer = null;
    }
  },

  /** 头像加载失败：所有头像位一起回落成占位表情 */
  onAvatarError() {
    if (!this.data.avatarFailed) {
      this.setData({ avatarFailed: true });
    }
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
   *
   * 优先走流式接口（回复逐字蹦出来），
   * 基础库不支持分块接收时自动回退到非流式接口 —— 功能完全一样，只是没有逐字效果。
   */
  async sendText(text: string) {
    // 上一条还在回复中：忽略这次发送，避免并发请求
    if (this.data.sending || this.data.streamingText) return;

    // 历史对话 = 本条消息之前的消息（排除开场白，且只保留最近若干条）。
    // ⚠️ 不要把本条消息也塞进 history：服务端会自己把 message 追加到对话末尾，
    //    带上就会让同一句话出现两次（这是原来就存在的一个小毛病）。
    const history: ChatMessage[] = this.data.messages
      .slice(1)
      .slice(-MAX_HISTORY_MESSAGES)
      .map((m) => ({
        role: m.role,
        content: m.content,
      }));

    const userMsg: ChatMessageExtended = { role: 'user', content: text };

    // ---- 小味表情：进入思考（点餐场景响应很快，不再切"翻菜单"状态）----
    this.clearStageTimers();
    const isFarewell = GOODBYE_WORDS.some((w) => text.toLowerCase().indexOf(w) >= 0);
    this.setStageState(isFarewell ? 'goodbye' : 'thinking');

    this.setData({
      messages: [...this.data.messages, userMsg],
      sending: true,
      streamingText: '',
      scrollToView: 'msg-typing',
    });

    try {
      if (supportsChunked()) {
        await this.streamReply(text, history);
      } else {
        await this.requestReply(text, history);
      }
    } catch (err: any) {
      console.error('AI回复失败：', err);
      this.failReply(err);
    }
  },

  /**
   * 流式获取回复
   *
   * 增量文本先写进 data.streamingText（单个字符串），结束后再作为一条正式消息追加。
   * 不每次重建 messages 数组的原因：那会把整段对话反复发给视图层，
   * 一次回复下来开销很大，低端机上明显卡。
   */
  streamReply(text: string, history: ChatMessage[]): Promise<void> {
    return new Promise((resolve, reject) => {
      let streamed = '';
      let lastFlushAt = 0;
      let flushTimer: any = null;
      let finished = false;

      const cleanup = () => {
        if (flushTimer) {
          clearTimeout(flushTimer);
          flushTimer = null;
        }
        this.streamTask = null;
      };

      this.streamTask = streamChat(text, history, {
        onDelta: (delta) => {
          if (finished) return;

          streamed += delta;

          const now = Date.now();

          // 第一段立刻显示（让用户马上看到反馈），之后按间隔合并刷新
          if (lastFlushAt === 0 || now - lastFlushAt >= STREAM_FLUSH_MS) {
            if (flushTimer) {
              clearTimeout(flushTimer);
              flushTimer = null;
            }
            lastFlushAt = now;
            this.setData({ streamingText: streamed, sending: false });
            return;
          }

          if (!flushTimer) {
            flushTimer = setTimeout(() => {
              flushTimer = null;
              lastFlushAt = Date.now();
              this.setData({ streamingText: streamed });
            }, STREAM_FLUSH_MS);
          }
        },

        onDone: (payload) => {
          if (finished) return;
          finished = true;
          cleanup();
          this.appendReply(payload.reply || streamed, payload.recommend_dishes || []);
          resolve();
        },

        onError: (msg) => {
          if (finished) return;
          finished = true;
          cleanup();
          reject(new Error(msg));
        },
      });
    });
  },

  /**
   * 非流式回退：一次拿到完整回复
   * 接口：POST /api/ai/chat.php
   */
  async requestReply(text: string, history: ChatMessage[]) {
    const result = await post<AIChatResult>('/api/ai/chat.php', {
      message: text,
      history: history,
    });

    this.appendReply(result.reply, result.recommend_dishes || []);
  },

  /** 把 AI 回复作为一条正式消息追加进去，并结束「正在输入」状态 */
  appendReply(reply: string, dishes: RecommendDish[]) {
    const aiMsg: ChatMessageExtended = {
      role: 'assistant',
      content: reply,
      recommendDishes: dishes,
    };

    const newMessages = [...this.data.messages, aiMsg];

    this.setData({
      messages: newMessages,
      sending: false,
      streamingText: '',
      scrollToView: 'msg-' + (newMessages.length - 1),
    });

    // ---- 小味表情：有推荐就开心，普通回答回到待命 ----
    this.clearStageTimers();
    if (dishes && dishes.length > 0) {
      this.holdStageState('happy', 2000);
    } else {
      this.setStageState('idle');
    }
  },

  /**
   * 失败时的收尾：结束「正在输入」，并给用户一句明确的话，避免界面一直转圈
   *
   * 限流（429）、AI 服务异常这类后端已经给出可读原因的，直接转达；
   * 其余情况用一句兜底话术，不把技术细节抛给顾客。
   */
  failReply(err?: any) {
    const raw = err ? String((err && err.message) || (err && err.msg) || '') : '';

    const explainable = raw !== '' && (
      raw.indexOf('频繁') >= 0 ||
      raw.indexOf('次数') >= 0 ||
      raw.indexOf('上限') >= 0
    );

    this.appendReply(
      explainable ? raw : '抱歉，我这边出了点小问题，请稍后再试一次~',
      []
    );

    // ---- 小味表情：道歉一下再收回待命 ----
    this.holdStageState('sorry', SORRY_HOLD_MS);
  },

  /** 中断当前流式请求（离开页面时调用） */
  abortStream() {
    if (this.streamTask) {
      this.streamTask.abort();
      this.streamTask = null;
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

      // 菜名可能很长，toast 标题太长会被截断，这里只报一句固定的短提示
      wx.showToast({ title: '已加入购物车', icon: 'success' });
      // 同步底部购物车角标
      refreshCartBadge();
      // ---- 小味表情：推荐被采纳了，开心一下 ----
      this.holdStageState('happy', 2000);
    } catch (err) {
      console.error('加入购物车失败：', err);
    }
  },

  /** 输入框变化 */
  onInputChange(e: any) {
    this.setData({ inputText: e.detail.value });
  },
});

/**
 * 小味舞台区组件
 *
 * 职责：把「小味当前处于什么状态」这一个输入，变成正确的差分图 + 动效。
 * 页面只需要传 state / variant，不需要关心图片路径、切换时机、兜底逻辑。
 *
 * 【为什么状态切换要有最少展示时长】
 *   AI 走流式接口时首个响应可能 300ms 内就回来，如果直接切状态，
 *   thinking 会一闪而过，看起来像卡了一下而不是「在思考」。
 *   所以每个状态都有 dwell（最短展示时长），期间来的新状态先排队。
 *
 * 【为什么点击弹一下要分两层】
 *   呼吸动效和点击/入场缩放都用 transform: scale，放在同一个节点上会互相覆盖。
 *   这里拆成两层：外层 .stage-inner 负责呼吸（scale 1 → 1.014），
 *   内层 .pop 负责入场动画与点击弹性。
 *
 * 【点击弹一下的实现】
 *   不用 JS 逐帧，直接用 CSS keyframes 做「放大后弹回」：
 *   0% 1 → 35% 1.14 → 60% 0.975 → 80% 1.035 → 100% 1，
 *   配 cubic-bezier 的过冲手感。每次点击把 popKey 加一，
 *   popKey 出现在 class 里，视图层重建节点 → 动画自然重播。
 */

const STAGE_BASE = '/assets/xiaowei/diffs/';
const AVATAR_SRC = '/assets/xiaowei/ui/xiaowei-avatar.png';
const FALLBACK_SRC = '/assets/xiaowei/ui/xiaowei-fallback.png';

/** 点击后切到开心表情，并保持这么久再收回（毫秒） */
const TAP_HAPPY_MS = 2000;

/**
 * 连点限制
 *
 * 为什么要限制：每次点击都会重播动画（重建节点），连点会让视图层一直在重建。
 * 这里放两个闸门：
 *   - 最短间隔 TAP_MIN_GAP_MS：太快连点直接忽略，避免动画看起来"抽搐"
 *   - 窗口内次数上限 TAP_MAX_IN_WINDOW / 窗口 TAP_WINDOW_MS：防止长时间狂点
 * 到上限后有一段冷却，冷却结束自动恢复，不需要用户做任何事。
 */
const TAP_MIN_GAP_MS = 140;
const TAP_MAX_IN_WINDOW = 6;
const TAP_WINDOW_MS = 3000;

/**
 * 弹性动画的样式轮换表
 *
 * 【为什么不用"换类名"来重播】
 *   试过给每次点击生成一个新类名（pop--play-11、-12…），在小程序里动画直接不播了 ——
 *   带数字/连字符的类名容易在样式编译环节被丢掉。所以改回固定类名，
 *   改用**内联 style 轮换 animation-name**：
 *     - 内联 style 不经过样式编译器，不会被丢
 *     - animation-name 每次换一个，浏览器视为"新动画"，即使上一次还在播也会从头开始
 *   两张关键帧内容完全一样，轮换只是为了强制重播。
 */
const POP_ANIMS = ['xw-pop-a', 'xw-pop-b', 'xw-pop-c', 'xw-pop-d'];
const POP_STYLE_TPL = 'animation: %s 560ms cubic-bezier(0.34, 1.56, 0.64, 1) 1;';

/** 入场动画（内容与弹性不同：淡入 + 上浮落定） */
const ENTER_ANIM = 'xw-enter-a';

/** 每个状态的差分文件 + 最短展示时长（毫秒）
 *
 * 说明：项目是点餐场景，AI 响应很快，不存在"长时间翻菜单"，
 * 所以没有 searching（翻菜单）这个状态；
 * 也没有单独的"得意"状态 —— 加购成功就用 happy 表达。
 */
const STATE_MAP = {
  // state      文件                              最短展示   备注
  idle:      { file: 'xiaowei-idle.png',      dwell: 0,    label: '微笑待命' },
  greeting:  { file: 'xiaowei-greeting.png',  dwell: 600,  label: '举手招呼' },
  thinking:  { file: 'xiaowei-thinking.png',  dwell: 700,  label: '思考中' },
  happy:     { file: 'xiaowei-happy.png',     dwell: 600,  label: '开心' },
  sorry:     { file: 'xiaowei-sorry.png',     dwell: 900,  label: '抱歉' },
  goodbye:   { file: 'xiaowei-goodbye.png',   dwell: 900,  label: '道谢' },
};

/** 兼容旧调用：传 searching / proud 时统一映射过去，避免页面写错状态就没表情 */
const STATE_ALIAS = {
  searching: 'thinking',
  proud: 'happy',
};

Component({
  properties: {
    /** 逻辑状态：idle / greeting / thinking / happy / sorry / goodbye
     *  （searching、proud 已废弃，传进来会自动映射为 thinking、happy） */
    state: {
      type: String,
      value: 'idle',
      observer(next) {
        this._applyState(next);
      },
    },
    /**
     * 布局形态：
     *   dock  —— 方案 A 的常驻舞台区（固定高度，在消息区上方）
     *   empty —— 方案 B 的欢迎态（对话为空时放大铺满，底部渐隐与消息区衔接）
     */
    variant: {
      type: String,
      value: 'dock',
    },
    /** 是否可点（页面想临时禁用时传 false） */
    tappable: {
      type: Boolean,
      value: true,
    },
  },

  data: {
    /** 实际显示的差分 key（受最短展示时长约束） */
    shown: 'idle',
    /** 渲染中的图片地址 */
    imgSrc: STAGE_BASE + 'xiaowei-idle.png',
    /** 图片加载失败时置 true，改走兜底图 */
    failed: false,
    /** 每次点击自增，用于重播弹性动画（0 = 未点击过） */
    popKey: 0,
    /** 弹性动画的内联样式（轮换 animation-name，强制每次点击从头播） */
    popStyle: '',
    /** 内层节点的基础类名 + 入场动画类名 */
    enterClass: '',
  },

  lifetimes: {
    attached() {
      this._dwellTimer = null;
      this._tapTimer = null;
      this._pending = null;
      this._tapReverted = true;
      this._tapTimes = [];
      this._lastTapAt = 0;
      this._tapCooling = false;
      this._appliedAt = Date.now();
      this._applyState(this.data.state, true);

      // 首屏入场：从下方淡入 + 轻微放大落定（只在欢迎态播，避免切换布局时重播）
      if (this.data.variant === 'empty') {
        this._playEnter();
      }
    },
    detached() {
      if (this._dwellTimer) clearTimeout(this._dwellTimer);
      if (this._tapTimer) clearTimeout(this._tapTimer);
    },
  },

  methods: {
    /** 把状态名（含别名）解析成 STATE_MAP 里真实存在的 key */
    _resolve(name) {
      const aliased = STATE_ALIAS[name] || name;
      return STATE_MAP[aliased] ? aliased : 'idle';
    },

    /** 首屏入场动画：加类（类名固定、经过验证可用） */
    _playEnter() {
      this.setData({ enterClass: 'pop--enter' });
    },

    /**
     * 切换状态：受最短展示时长约束
     * @param {string} next 目标状态
     * @param {boolean} force 忽略约束立即生效（初始化用）
     */
    _applyState(next, force) {
      const key = this._resolve(next);

      if (force) {
        this._commit(key);
        return;
      }

      if (key === this.data.shown) return;

      const current = STATE_MAP[this.data.shown] || STATE_MAP.idle;
      const elapsed = Date.now() - this._appliedAt;
      const wait = Math.max(0, current.dwell - elapsed);

      if (wait === 0) {
        this._commit(key);
        return;
      }

      // 还在最短展示期内：排队，到时间再切
      this._pending = key;
      if (this._dwellTimer) clearTimeout(this._dwellTimer);
      this._dwellTimer = setTimeout(() => {
        this._dwellTimer = null;
        const queued = this._pending;
        this._pending = null;
        if (queued) this._commit(queued);
      }, wait);
    },

    /** 真正落到视图层 */
    _commit(key) {
      this._appliedAt = Date.now();
      this.setData({
        shown: key,
        imgSrc: STAGE_BASE + STATE_MAP[key].file,
        failed: false,
      });
    },

    /**
     * 点击小味：弹一下（放大后缩回）+ 切到开心表情并保持 2 秒
     *
     * 每次点击都从头重播：内联 style 里轮换 animation-name（xw-pop-a/b/c/d），
     * animation-name 一变，动画即使上一次还在播也会从头开始 —— 连点就能一直弹。
     * 另有两道闸门（最短间隔 + 窗口内次数上限），避免狂点把视图层拖垮。
     */
    onTap() {
      if (!this.data.tappable) return;

      // ---- 闸门 1：最短间隔 ----
      const now = Date.now();
      if (this._lastTapAt && now - this._lastTapAt < TAP_MIN_GAP_MS) return;

      // ---- 闸门 2：窗口内次数上限 ----
      if (!this._tapTimes) this._tapTimes = [];
      this._tapTimes = this._tapTimes.filter((t) => now - t < TAP_WINDOW_MS);
      if (this._tapTimes.length >= TAP_MAX_IN_WINDOW) {
        // 冷却中：提示一次就够了，别每次点击都弹 toast
        if (!this._tapCooling) {
          this._tapCooling = true;
          wx.showToast({ title: '小味歇一会儿~', icon: 'none', duration: 1200 });
          setTimeout(() => { this._tapCooling = false; }, TAP_WINDOW_MS);
        }
        return;
      }
      this._tapTimes.push(now);
      this._lastTapAt = now;

      // 清掉上一轮点击可能还挂着的收回计时器
      if (this._tapTimer) {
        clearTimeout(this._tapTimer);
        this._tapTimer = null;
      }

      // 轮换 animation-name，让弹性动画每次都从头播（连点也能一直弹）
      const popKey = this.data.popKey + 1;
      this._tapReverted = false;
      this.setData({
        popKey,
        popStyle: POP_STYLE_TPL.replace('%s', POP_ANIMS[popKey % POP_ANIMS.length]),
      });

      // 立刻换成开心表情：点击反馈要跟手，不能等动画播完再切
      this._commit('happy');

      // 从"切到开心"这一刻开始算 2 秒，之后收回页面要求的状态
      this._scheduleTapRevert();
    },

    /** 2 秒后把点击表情收回页面要求的状态 */
    _scheduleTapRevert() {
      if (this._tapTimer) clearTimeout(this._tapTimer);
      this._tapTimer = setTimeout(() => {
        this._tapTimer = null;
        this._tapReverted = true;
        this._commit(this._resolve(this.data.state));
      }, TAP_HAPPY_MS);
    },

    /**
     * 动画播完的处理
     *
     * 弹性动画只负责"弹一下"这个视觉效果，不再牵扯表情切换
     * （表情在点击瞬间就换成 happy 了）。
     * 这里唯一要做的是清掉内联动画样式，让节点回到静止的 scale(1)，
     * 避免动画属性一直挂在节点上。
     * 入场动画则清掉类名。
     */
    onAnimationEnd(e) {
      const animName = (e && e.detail && e.detail.animationName) || '';

      if (animName.indexOf('xw-enter') === 0) {
        if (this.data.enterClass) this.setData({ enterClass: '' });
        return;
      }

      if (animName.indexOf('xw-pop') === 0) {
        if (this.data.popStyle) this.setData({ popStyle: '' });
      }
    },

    /** 图片加载成功：留一条日志，便于排查"图不显示"这类问题 */
    onImgLoad(e) {
      const d = (e && e.detail) || {};
      console.log('[xiaowei-stage] 差分图加载成功', this.data.shown, d.width + 'x' + d.height, this.data.imgSrc);
    },

    /** 图片加载失败 → 回落兜底图；兜底图也失败 → 显示占位表情 */
    onImgError(e) {
      const d = (e && e.detail) || {};
      console.warn('[xiaowei-stage] 差分图加载失败', this.data.imgSrc, d.errMsg || '');
      if (!this.data.failed) {
        this.setData({ failed: true, imgSrc: FALLBACK_SRC });
      }
    },
  },
});

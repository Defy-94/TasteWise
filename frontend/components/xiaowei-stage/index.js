/**
 * 小味舞台区组件
 *
 * 页面只需传 state / variant，图片路径、切换时机、兜底逻辑都在组件内。
 * state: idle / greeting / thinking / happy / sorry / goodbye
 * variant: dock（常驻舞台区）| empty（对话为空时的欢迎态）
 *
 * 两个实现要点：
 * - 每个状态有最短展示时长（dwell），避免流式响应太快导致 thinking 一闪而过
 * - 呼吸动效在外层、点击弹性在内层，避免两个 transform 互相覆盖
 */

const STAGE_BASE = '/assets/xiaowei/diffs/';
const AVATAR_SRC = '/assets/xiaowei/ui/xiaowei-avatar.png';
const FALLBACK_SRC = '/assets/xiaowei/ui/xiaowei-fallback.png';

/** 点击后切到开心表情，并保持这么久再收回（毫秒） */
const TAP_HAPPY_MS = 2000;

/** 连点闸门：最短间隔 + 窗口内次数上限，防止狂点把视图层拖垮 */
const TAP_MIN_GAP_MS = 140;
const TAP_MAX_IN_WINDOW = 6;
const TAP_WINDOW_MS = 3000;

/**
 * 弹性动画的样式轮换表
 *
 * 不能用"每次换一个类名"来重播：带数字的类名在小程序样式编译环节会被丢掉。
 * 这里改成内联 style 轮换 animation-name —— 内联样式不过编译器，
 * 且动画名一变就会被当成新动画，即使上一次还在播也会从头开始。
 */
const POP_ANIMS = ['xw-pop-a', 'xw-pop-b', 'xw-pop-c', 'xw-pop-d'];
const POP_STYLE_TPL = 'animation: %s 560ms cubic-bezier(0.34, 1.56, 0.64, 1) 1;';

/** 入场动画（淡入 + 上浮落定） */
const ENTER_ANIM = 'xw-enter-a';

/** 每个状态的差分文件与最短展示时长 */
const STATE_MAP = {
  idle:      { file: 'xiaowei-idle.png',      dwell: 0,    label: '微笑待命' },
  greeting:  { file: 'xiaowei-greeting.png',  dwell: 600,  label: '举手招呼' },
  thinking:  { file: 'xiaowei-thinking.png',  dwell: 700,  label: '思考中' },
  happy:     { file: 'xiaowei-happy.png',     dwell: 600,  label: '开心' },
  sorry:     { file: 'xiaowei-sorry.png',     dwell: 900,  label: '抱歉' },
  goodbye:   { file: 'xiaowei-goodbye.png',   dwell: 900,  label: '道谢' },
};

/** 兼容旧调用：传入已废弃的状态名时映射到现有状态 */
const STATE_ALIAS = {
  searching: 'thinking',
  proud: 'happy',
};

Component({
  properties: {
    /** 逻辑状态，见文件头说明 */
    state: {
      type: String,
      value: 'idle',
      observer(next) {
        this._applyState(next);
      },
    },
    /** 布局形态：dock 常驻 / empty 欢迎态 */
    variant: {
      type: String,
      value: 'dock',
    },
    /** 是否可点 */
    tappable: {
      type: Boolean,
      value: true,
    },
  },

  data: {
    shown: 'idle',
    imgSrc: STAGE_BASE + 'xiaowei-idle.png',
    failed: false,
    popKey: 0,
    popStyle: '',
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

      // 首屏入场只在欢迎态播，避免切换布局时重播
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
    _resolve(name) {
      const aliased = STATE_ALIAS[name] || name;
      return STATE_MAP[aliased] ? aliased : 'idle';
    },

    _playEnter() {
      this.setData({ enterClass: 'pop--enter' });
    },

    /** 切换状态（受最短展示时长约束，未到时间的先排队） */
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

      this._pending = key;
      if (this._dwellTimer) clearTimeout(this._dwellTimer);
      this._dwellTimer = setTimeout(() => {
        this._dwellTimer = null;
        const queued = this._pending;
        this._pending = null;
        if (queued) this._commit(queued);
      }, wait);
    },

    _commit(key) {
      this._appliedAt = Date.now();
      this.setData({
        shown: key,
        imgSrc: STAGE_BASE + STATE_MAP[key].file,
        failed: false,
      });
    },

    /** 点击：弹一下 + 立刻切开心表情，2 秒后收回 */
    onTap() {
      if (!this.data.tappable) return;

      const now = Date.now();
      if (this._lastTapAt && now - this._lastTapAt < TAP_MIN_GAP_MS) return;

      if (!this._tapTimes) this._tapTimes = [];
      this._tapTimes = this._tapTimes.filter((t) => now - t < TAP_WINDOW_MS);
      if (this._tapTimes.length >= TAP_MAX_IN_WINDOW) {
        if (!this._tapCooling) {
          this._tapCooling = true;
          wx.showToast({ title: '小味歇一会儿~', icon: 'none', duration: 1200 });
          setTimeout(() => { this._tapCooling = false; }, TAP_WINDOW_MS);
        }
        return;
      }
      this._tapTimes.push(now);
      this._lastTapAt = now;

      if (this._tapTimer) {
        clearTimeout(this._tapTimer);
        this._tapTimer = null;
      }

      const popKey = this.data.popKey + 1;
      this._tapReverted = false;
      this.setData({
        popKey,
        popStyle: POP_STYLE_TPL.replace('%s', POP_ANIMS[popKey % POP_ANIMS.length]),
      });

      this._commit('happy');
      this._scheduleTapRevert();
    },

    _scheduleTapRevert() {
      if (this._tapTimer) clearTimeout(this._tapTimer);
      this._tapTimer = setTimeout(() => {
        this._tapTimer = null;
        this._tapReverted = true;
        this._commit(this._resolve(this.data.state));
      }, TAP_HAPPY_MS);
    },

    /** 动画结束：清理内联样式与入场类名，让节点回到静止态 */
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

    onImgLoad(e) {
      const d = (e && e.detail) || {};
      console.log('[xiaowei-stage] 差分图加载成功', this.data.shown, d.width + 'x' + d.height, this.data.imgSrc);
    },

    /** 加载失败 → 回落兜底图 */
    onImgError(e) {
      const d = (e && e.detail) || {};
      console.warn('[xiaowei-stage] 差分图加载失败', this.data.imgSrc, d.errMsg || '');
      if (!this.data.failed) {
        this.setData({ failed: true, imgSrc: FALLBACK_SRC });
      }
    },
  },
});

/**
 * 登录页 —— 微信一键登录
 * wx.login() 取 code → 后端 code2Session 换 openid → 查/建用户 → 签发令牌
 * 后端未配置 AppSecret 返回 503 时，若开启本地调试通道则改用调试账号登录
 */
import { post, saveAuth } from '../../utils/api';
import { AuthUser } from '../../utils/types';
import { refreshCartBadge } from '../../utils/cartBadge';

// 本地调试用的 openid
// 仅当后端 config/security.php 中 allow_dev_openid_login = true
// 且请求来自本机回环地址（127.0.0.1 / ::1）时才会被后端接受
const DEV_OPENID = 'dev_local_openid';

Page({
  data: {
    logging: false,   // 防重复点击
  },

  /** 返回上一页 */
  onBack() {
    wx.navigateBack();
  },

  /** 微信一键登录：POST /api/user/login.php（入参 code） */
  async onWxLogin() {
    if (this.data.logging) return;
    this.setData({ logging: true });

    wx.showLoading({ title: '登录中...', mask: true });

    try {
      const user = await this.doLogin();
      wx.hideLoading();
      this.onLoginSuccess(user);
    } catch (err: any) {
      wx.hideLoading();
      console.error('登录失败：', err);
      wx.showToast({
        title: (err && err.msg) || '登录失败，请重试',
        icon: 'none',
        duration: 2500,
      });
    } finally {
      this.setData({ logging: false });
    }
  },

  /** 执行登录：优先微信登录，仅后端未配置 AppSecret 时退回本地调试通道 */
  async doLogin(): Promise<AuthUser> {
    // 1) wx.login 获取临时 code
    let code = '';
    try {
      code = await this.getLoginCode();
    } catch (e) {
      console.warn('wx.login 未取得 code，尝试本地调试通道：', e);
    }

    // 2) 正式流程：用 code 换取登录态
    if (code) {
      try {
        return await post<AuthUser>('/api/user/login.php', { code });
      } catch (err: any) {
        // 503 = 后端未配置微信 AppSecret；只有这种情况才允许使用调试通道
        if (!err || err.code !== 503) {
          throw err;
        }
        console.warn('后端未配置微信登录，改用本地调试通道：', err.msg);
      }
    }

    // 3) 本地调试通道（后端会校验开关 + 本机回环地址，线上会被拒绝）
    const user = await post<AuthUser>('/api/user/login.php', {
      openid: DEV_OPENID,
      nickname: '本地调试用户',
    });
    wx.showToast({
      title: '本地调试登录（尚未配置 AppSecret）',
      icon: 'none',
      duration: 2500,
    });
    return user;
  },

  /** 把 wx.login 封装成 Promise，返回临时 code */
  getLoginCode(): Promise<string> {
    return new Promise((resolve, reject) => {
      wx.login({
        success: (res: any) => {
          if (res && res.code) {
            resolve(res.code);
          } else {
            reject(new Error('wx.login 未返回 code'));
          }
        },
        fail: (err: any) => reject(err),
      });
    });
  },

  /** 登录成功的公共处理：保存登录态并返回「我的」页 */
  onLoginSuccess(user: AuthUser) {
    // 保存登录令牌与用户信息（后续请求自动携带令牌）
    saveAuth(user);

    // 同步购物车角标（用户可能在登录前就已经加过购物车）
    refreshCartBadge();

    const app = getApp<IAppOption>();
    app.globalData.userId = user.id;
    app.globalData.openid = user.openid;

    wx.showToast({ title: '登录成功', icon: 'success' });

    setTimeout(() => {
      wx.switchTab({ url: '/pages/userCenter/userCenter' });
    }, 1200);
  },
});

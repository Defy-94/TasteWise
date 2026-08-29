/**
 * 注册页面
 */
import { post } from '../../utils/api';
import { UserInfo } from '../../utils/types';

const app = getApp<IAppOption>();

Page({
  data: {
    username: '',
    phone: '',
    smsCode: '',
    countdown: 0,        // 倒计时秒数
  },

  onLoad(options: any) {
    // 如果从登录页跳转过来的手机号，预填
    if (options.phone) {
      this.setData({ phone: options.phone });
    }
  },

  /** 返回上一页 */
  onBack() {
    wx.navigateBack();
  },

  /** 用户名输入 */
  onUsernameInput(e: any) {
    this.setData({ username: e.detail.value });
  },

  /** 手机号输入 */
  onPhoneInput(e: any) {
    this.setData({ phone: e.detail.value });
  },

  /** 验证码输入 */
  onCodeInput(e: any) {
    this.setData({ smsCode: e.detail.value });
  },

  /**
   * 发送短信验证码（预留）
   * ============================================
   * 【短信验证码发送逻辑 - 预留位置】
   * 后续接入腾讯云短信或第三方服务时，在此处添加：
   * 1. 调用后端接口 /api/user/send_sms.php
   * 2. 传入手机号
   * 3. 启动60秒倒计时
   * ============================================
   */
  onSendSmsCode() {
    const phone = this.data.phone;
    
    if (!phone) {
      wx.showToast({ title: '请先输入手机号', icon: 'none' });
      return;
    }

    if (!/^1[3-9]\d{9}$/.test(phone)) {
      wx.showToast({ title: '手机号格式不正确', icon: 'none' });
      return;
    }

    // ============================================
    // 此处预留短信验证码发送逻辑
    // 后续接入腾讯云短信或第三方服务
    // 示例：
    // await post('/api/user/send_sms.php', { phone });
    // 成功后启动60秒倒计时
    // ============================================
    
    // 临时反馈
    wx.showToast({ title: '验证码功能暂未开放', icon: 'none' });
  },

  /**
   * 提交注册
   * 接口：POST /api/user/register.php
   */
  async onSubmitRegister() {
    const { username, phone, smsCode } = this.data;
    
    // 基本校验
    if (!username.trim()) {
      wx.showToast({ title: '请输入用户名', icon: 'none' });
      return;
    }

    if (!phone.trim()) {
      wx.showToast({ title: '请输入手机号', icon: 'none' });
      return;
    }

    if (!/^1[3-9]\d{9}$/.test(phone)) {
      wx.showToast({ title: '手机号格式不正确', icon: 'none' });
      return;
    }

    // ============================================
    // 【接入短信后启用此校验】
    // if (!smsCode) {
    //   wx.showToast({ title: '请输入短信验证码', icon: 'none' });
    //   return;
    // }
    // ============================================

    wx.showLoading({ title: '注册中...' });
    
    try {
      const user = await post<UserInfo>('/api/user/register.php', {
        username: username.trim(),
        phone: phone.trim(),
        code: smsCode,  // 验证码（接入短信后生效）
      });
      
      wx.hideLoading();
      
      // 注册成功，自动登录
      wx.setStorageSync('userInfo', user);
      app.globalData.userId = user.id;
      app.globalData.openid = user.openid;
      
      wx.showToast({ title: '注册成功', icon: 'success' });
      
      // 跳转回用户主页
      setTimeout(() => {
        wx.switchTab({ url: '/pages/userCenter/userCenter' });
      }, 1500);
      
    } catch (err: any) {
      wx.hideLoading();
      
      // 根据错误信息做不同处理
      const errorMsg = err.msg || '';
      
      if (errorMsg.includes('用户名已存在')) {
        wx.showToast({ title: '用户名已存在，请更换', icon: 'none' });
      } else if (errorMsg.includes('手机号已注册') || err.code === 409) {
        // 手机号已注册，弹窗询问是否跳转登录
        wx.showModal({
          title: '提示',
          content: '该手机号已注册，是否前往登录？',
          confirmText: '前往登录',
          cancelText: '取消',
          success: (res) => {
            if (res.confirm) {
              // 跳转登录页，并带入手机号
              wx.redirectTo({
                url: '/pages/login/login?phone=' + encodeURIComponent(phone),
              });
            }
          },
        });
      } else {
        wx.showToast({ title: errorMsg || '注册失败', icon: 'none' });
      }
    }
  },
});
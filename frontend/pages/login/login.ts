/**
 * 登录页面
 */
import { post, saveAuth } from '../../utils/api';
import { AuthUser } from '../../utils/types';

const app = getApp<IAppOption>();

Page({
  data: {
    phone: '',
  },

  onLoad(options: any) {
    // 如果从注册页跳转过来的手机号，预填
    if (options.phone) {
      this.setData({ phone: decodeURIComponent(options.phone) });
    }
  },

  /** 返回上一页 */
  onBack() {
    wx.navigateBack();
  },

  /** 手机号输入 */
  onPhoneInput(e: any) {
    this.setData({ phone: e.detail.value });
  },

  /**
   * 提交登录
   * 接口：POST /api/user/login_phone.php
   */
  async onSubmitLogin() {
    const phone = this.data.phone.trim();
    
    if (!phone) {
      wx.showToast({ title: '请输入手机号', icon: 'none' });
      return;
    }

    if (!/^1[3-9]\d{9}$/.test(phone)) {
      wx.showToast({ title: '手机号格式不正确', icon: 'none' });
      return;
    }

    wx.showLoading({ title: '登录中...' });
    
    try {
      const user = await post<AuthUser>('/api/user/login_phone.php', {
        phone: phone,
      });
      
      wx.hideLoading();
      
      // 登录成功：保存登录令牌与用户信息（后续请求自动携带令牌）
      saveAuth(user);
      app.globalData.userId = user.id;
      app.globalData.openid = user.openid;
      
      wx.showToast({ title: '登录成功', icon: 'success' });
      
      // 跳转回用户主页
      setTimeout(() => {
        wx.switchTab({ url: '/pages/userCenter/userCenter' });
      }, 1500);
      
    } catch (err: any) {
      wx.hideLoading();
      
      const errorMsg = err.msg || '';
      
      if (errorMsg.includes('未注册') || err.code === 404) {
        // 未注册，弹窗询问是否跳转注册
        wx.showModal({
          title: '提示',
          content: '该手机号未注册，请前往注册',
          confirmText: '前往注册',
          cancelText: '取消',
          success: (res) => {
            if (res.confirm) {
              wx.redirectTo({
                url: '/pages/register/register?phone=' + encodeURIComponent(phone),
              });
            }
          },
        });
      } else {
        wx.showToast({ title: errorMsg || '登录失败', icon: 'none' });
      }
    }
  },
});
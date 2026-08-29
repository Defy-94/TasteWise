/**
 * 小程序全局入口
 * 初始化登录态、全局数据
 */

interface IAppOption {
    globalData: {
      userInfo?: any;
      userId: number;       // 默认为0，登录后更新
      openid: string;
    };
  }
  
  App<IAppOption>({
    globalData: {
      userId: 0,               // 初始为0，登录后更新为用户自增ID
      openid: '',              // 初始为空
    },
  
    onLaunch() {
      // 启动时检查本地缓存中的用户信息
      const cachedUser = wx.getStorageSync('userInfo');
      if (cachedUser && cachedUser.id) {
        this.globalData.userId = cachedUser.id;
        this.globalData.openid = cachedUser.openid;
      }
    },
  });
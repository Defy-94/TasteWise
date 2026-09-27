/**
 * 小程序全局入口
 * 初始化登录态、全局数据
 * 
 * 【类型说明】
 *   App<T> 的泛型 T 是 **globalData 的类型**（不是整个 App 选项的类型）；
 *   而页面里 getApp<IAppOption>() 拿到的是 { globalData: <该类型> }。
 *   两者描述的是同一份数据，改这里时记得同步 typings/index.d.ts 里的 IAppOption。
 */

/** 全局数据类型 */
interface AppGlobalData {
  userInfo?: any;
  userId: number;       // 默认为 0，登录后更新
  openid: string;
}

App<AppGlobalData>({
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

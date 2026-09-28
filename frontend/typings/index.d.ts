declare var console: {
    log(...args: any[]): void;
    warn(...args: any[]): void;
    error(...args: any[]): void;
    info(...args: any[]): void;
    debug(...args: any[]): void;
  };
  
declare namespace WechatMiniprogram {

    interface GeneralCallbackResult {
      errMsg: string;
      errno?: number;
    }
  
    interface RequestSuccessCallbackResult {
      data: any;
      statusCode: number;
      header: Record<string, string>;
      cookies?: string[];
    }
  
    interface RequestOption {
      url: string;
      data?: string | object | ArrayBuffer;
      header?: Record<string, string>;
      timeout?: number;
      method?: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'OPTIONS' | 'HEAD' | 'TRACE' | 'CONNECT';
      dataType?: 'json' | 'text';
      responseType?: 'text' | 'arraybuffer';
      enableChunked?: boolean;
      success?: (result: RequestSuccessCallbackResult) => void;
      fail?: (res: GeneralCallbackResult) => void;
      complete?: (res: GeneralCallbackResult) => void;
    }
  
    interface ShowToastOption {
      title: string;
      icon?: 'success' | 'error' | 'loading' | 'none';
      image?: string;
      duration?: number;
      mask?: boolean;
      success?: (res: GeneralCallbackResult) => void;
      fail?: (res: GeneralCallbackResult) => void;
      complete?: (res: GeneralCallbackResult) => void;
    }
  
    interface ShowLoadingOption {
      title: string;
      mask?: boolean;
      success?: (res: GeneralCallbackResult) => void;
      fail?: (res: GeneralCallbackResult) => void;
      complete?: (res: GeneralCallbackResult) => void;
    }
  
    interface ShowModalOption {
      title?: string;
      content?: string;
      showCancel?: boolean;
      cancelText?: string;
      cancelColor?: string;
      confirmText?: string;
      confirmColor?: string;
      success?: (result: ShowModalSuccessCallbackResult) => void;
      fail?: (res: GeneralCallbackResult) => void;
      complete?: (res: GeneralCallbackResult) => void;
    }
  
    interface ShowModalSuccessCallbackResult extends GeneralCallbackResult {
      confirm: boolean;
      cancel: boolean;
    }
  
    interface LoginOption {
      timeout?: number;
      success?: (res: LoginSuccessCallbackResult) => void;
      fail?: (res: GeneralCallbackResult) => void;
      complete?: (res: GeneralCallbackResult) => void;
    }
  
    interface LoginSuccessCallbackResult extends GeneralCallbackResult {
      /** 临时登录凭证，5 分钟内有效且只能用一次，交给服务端 code2Session 换 openid */
      code: string;
    }
  
    interface SetTabBarBadgeOption {
      /** tabBar 项的下标（从 0 开始：首页0 / 购物车1 / AI助手2 / 我的3） */
      index: number;
      /** 显示的文本，超过 4 个字符会显示为 "..." */
      text: string;
      success?: (res: GeneralCallbackResult) => void;
      fail?: (res: GeneralCallbackResult) => void;
      complete?: (res: GeneralCallbackResult) => void;
    }
  
    interface RemoveTabBarBadgeOption {
      index: number;
      success?: (res: GeneralCallbackResult) => void;
      fail?: (res: GeneralCallbackResult) => void;
      complete?: (res: GeneralCallbackResult) => void;
    }

    interface SetNavigationBarTitleOption {
      /** 新的导航栏标题 */
      title: string;
      success?: (res: GeneralCallbackResult) => void;
      fail?: (res: GeneralCallbackResult) => void;
      complete?: (res: GeneralCallbackResult) => void;
    }
  
    interface NavigateToOption {
      url: string;
      events?: any;
      success?: (res: GeneralCallbackResult) => void;
      fail?: (res: GeneralCallbackResult) => void;
      complete?: (res: GeneralCallbackResult) => void;
    }
  
    interface SwitchTabOption {
      url: string;
      success?: (res: GeneralCallbackResult) => void;
      fail?: (res: GeneralCallbackResult) => void;
      complete?: (res: GeneralCallbackResult) => void;
    }
  
    interface SystemInfo {
      model: string;
      pixelRatio: number;
      windowWidth: number;
      windowHeight: number;
      language: string;
      version: string;
      platform: string;
      system: string;
      brand: string;
      fontSizeSetting: number;
      SDKVersion: string;
      benchmarkLevel: number;
    }
  
    namespace Page {
      interface Options<TData extends Record<string, any>, TCustom extends Record<string, any>> {
        // ⚠️ 必须是必填项：若声明为可选（data?: TData），
        //    则页面里所有 this.data.xxx 都会被判为「可能为 undefined」而报 TS2532 假错误
        data: TData;
        onLoad?: (query: Record<string, string | undefined>) => void;
        onReady?: () => void;
        onShow?: () => void;
        onHide?: () => void;
        onUnload?: () => void;
        onPullDownRefresh?: () => void;
        onReachBottom?: () => void;
        onShareAppMessage?: () => any;
        [key: string]: any;
      }
  
      /**
       * 页面实例（即页面方法里的 this）
       * 
       * 为什么把 data / setData 单独定义在"实例"上，而不是放进 Options：
       *   Page({...}) 传入的是「配置对象」，框架会自动把 data/setData 挂到 this 上，
       *   配置对象本身既不需要也不应该写 setData。
       *   分开定义之后有两个好处：
       *     1. Page() 的实参不会因为缺少 setData 而报错（这是之前试出来的坑）；
       *     2. 页面方法里 this.setData({...}) 的 key 会被检查——
       *        在微信里 setData 写错 key 不会报错、只是静默不生效，很难排查。
       */
      interface Instance<TData extends Record<string, any>> {
        data: TData;
        setData(data: Partial<TData>, callback?: () => void): void;
        [key: string]: any;
      }
    }
  
    namespace App {
      interface Options<T extends Record<string, any>> {
        // 同上：必填，避免 this.globalData 被判为可能 undefined
        globalData: T;
        onLaunch?: (options: any) => void;
        onShow?: (options: any) => void;
        onHide?: () => void;
        onError?: (error: string) => void;
        [key: string]: any;
      }
    }
  
    namespace Component {
      interface Options<TData extends Record<string, any>, TProperty extends Record<string, any>, TMethod extends Record<string, any>> {
        data?: TData;
        properties?: TProperty;
        methods?: TMethod;
        [key: string]: any;
      }
    }
  }

  declare var wx: {
    request(option: WechatMiniprogram.RequestOption): void;
    showToast(option: WechatMiniprogram.ShowToastOption): void;
    showLoading(option: WechatMiniprogram.ShowLoadingOption): void;
    hideLoading(option?: { success?: (res: WechatMiniprogram.GeneralCallbackResult) => void }): void;
    showModal(option: WechatMiniprogram.ShowModalOption): void;
    navigateTo(option: WechatMiniprogram.NavigateToOption): void;
    switchTab(option: WechatMiniprogram.SwitchTabOption): void;
    navigateBack(option?: { delta?: number }): void;
    /** 获取临时登录凭证 code（交给服务端 code2Session 换 openid） */
    login(option: WechatMiniprogram.LoginOption): void;
    /** 在 tabBar 上显示角标（index 从 0 开始） */
    setTabBarBadge(option: WechatMiniprogram.SetTabBarBadgeOption): void;
    /** 移除 tabBar 角标 */
    removeTabBarBadge(option: WechatMiniprogram.RemoveTabBarBadgeOption): void;
    /** 动态设置导航栏标题（详情页用它显示菜名） */
    setNavigationBarTitle(option: WechatMiniprogram.SetNavigationBarTitleOption): void;
    getSystemInfoSync(): WechatMiniprogram.SystemInfo;
    setStorageSync(key: string, data: any): void;
    getStorageSync(key: string): any;
    removeStorageSync(key: string): void;
  
    // ⚠️ 这里刻意【不再】写 [key: string]: any;
    //    留了它的话，任何写错的 API 名字（比如 wx.showTost）都会静默通过类型检查，
    //    直到运行时才报错。删掉后，未声明的 API 会直接编译报错，提醒你补上声明。
    //    如果确实要用新的 API，在 WechatMiniprogram 命名空间里补一个 Option 接口，
    //    再在这里加一行声明即可（照着上面 login / setTabBarBadge 的写法）。
  };
  
  // 全局函数
  declare function Page<TData extends Record<string, any>>(
    options: WechatMiniprogram.Page.Options<TData, Record<string, any>>
      & ThisType<WechatMiniprogram.Page.Instance<TData>>
  ): void;
  
  declare function App<T extends Record<string, any>>(
    options: WechatMiniprogram.App.Options<T>
  ): void;
  
  declare function Component<
    TData extends Record<string, any>,
    TProperty extends Record<string, any>,
    TMethod extends Record<string, any>
  >(
    options: WechatMiniprogram.Component.Options<TData, TProperty, TMethod>
  ): void;
  
  declare function getApp<T = Record<string, any>>(): T;
  
  // 小程序全局函数（原 typings 缺失，补充声明，避免误报 Cannot find name）
  declare function getCurrentPages(): Array<{ route?: string; [key: string]: any }>;
  declare function setTimeout(callback: (...args: any[]) => void, delay?: number, ...args: any[]): number;
  declare function clearTimeout(timeoutId: number): void;
  declare function setInterval(callback: (...args: any[]) => void, delay?: number, ...args: any[]): number;
  declare function clearInterval(intervalId: number): void;
  
  // 全局接口
  declare interface IAppOption {
    globalData: {
      userInfo?: any;
      userId: number;
      openid: string;
    };
  }
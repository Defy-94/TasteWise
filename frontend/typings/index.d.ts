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
    getSystemInfoSync(): WechatMiniprogram.SystemInfo;
    setStorageSync(key: string, data: any): void;
    getStorageSync(key: string): any;
    removeStorageSync(key: string): void;
    [key: string]: any;
  };
  
  // 全局函数
  declare function Page<TData extends Record<string, any>>(
    options: WechatMiniprogram.Page.Options<TData, Record<string, any>>
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
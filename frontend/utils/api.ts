/**
 * 全局请求封装：后端地址、令牌注入（header: token）、401 统一处理
 * 后端地址按运行环境自动选择，也可用本地存储临时覆盖（见 resolveBaseUrl）
 */

/**
 * 后端地址配置（按运行环境自动选择）
 *
 * ============================================
 * 【为什么不再写死一个地址】
 *   原来只有一行 const BASE_URL = 'http://localhost'，
 *   于是「本地调试 / 体验版 / 正式版」要切后端时只能改源码 ——
 *   容易改错，更容易**忘记改回来**（把正式包指向 localhost 是致命错误）。
 *   微信提供了 wx.getAccountInfoSync().miniProgram.envVersion 来区分运行环境，
 *   所以这里按环境自动选，不用再动代码。
 *
 * 【要改地址就改这里】
 *   - develop：开发者工具与真机调试
 *              （真机调试要改成电脑的局域网 IP，例如 http://192.168.1.5，
 *                手机上的 localhost 指的是手机自己）
 *   - trial  ：体验版
 *   - release：正式版，必须是 https 且已在小程序后台配成「request 合法域名」
 * ============================================
 */
const API_BASE_BY_ENV = {
  develop: 'http://localhost',
  trial: 'http://localhost',
  release: 'https://api.example.com',
};

/** 临时覆盖后端地址用的本地存储键（调试用，见 resolveBaseUrl 的说明） */
const BASE_URL_OVERRIDE_KEY = 'apiBaseUrl';

/**
 * 决定当前该用哪个后端地址
 *
 * 优先级：本地覆盖 > 运行环境配置 > 开发环境默认
 */
function resolveBaseUrl(): string {
  // 1. 调试用的临时覆盖。
  //    在开发者工具控制台执行下面这句就能把请求指向别的机器，不必改代码：
  //        wx.setStorageSync('apiBaseUrl', 'http://192.168.1.5')
  //    清掉它：wx.removeStorageSync('apiBaseUrl')
  //    （模块只在启动时求值一次，所以改完要重新进入小程序才生效）
  try {
    const override = wx.getStorageSync(BASE_URL_OVERRIDE_KEY);
    if (override && typeof override === 'string') {
      return override;
    }
  } catch (e) {
    // 读不到就当没设置
  }

  // 2. 按运行环境选
  try {
    const info = wx.getAccountInfoSync();
    const env = info && info.miniProgram ? info.miniProgram.envVersion : '';

    if (env && (API_BASE_BY_ENV as Record<string, string>)[env]) {
      return (API_BASE_BY_ENV as Record<string, string>)[env];
    }
  } catch (e) {
    // 老版本基础库没有这个 API，落到兜底分支
  }

  // 3. 兜底：按开发环境处理，至少本地能跑起来
  return API_BASE_BY_ENV.develop;
}

/** 后端API基础地址（按运行环境解析，见上方配置表） */
const BASE_URL = resolveBaseUrl();

// 本地缓存键名
const TOKEN_KEY = 'token';
const USER_KEY = 'userInfo';

// 登录页路径
const LOGIN_PAGE = '/pages/login/login';

// 防止多个并发请求同时 401 时重复跳转登录页
let redirectingToLogin = false;

interface ApiResponse<T> {
  code: number;
  msg: string;
  data: T;
}

/** 读取本地保存的登录令牌 */
function getToken(): string {
  return wx.getStorageSync(TOKEN_KEY) || '';
}

/**
 * 保存登录态（令牌 + 用户信息）
 * 登录接口返回的数据中带有 token 字段
 */
function saveAuth(data: any) {
  if (!data) return;
  if (data.token) {
    wx.setStorageSync(TOKEN_KEY, data.token);
  }
  wx.setStorageSync(USER_KEY, data);
}

/** 清除登录态（退出登录、令牌失效时调用） */
function clearAuth() {
  wx.removeStorageSync(TOKEN_KEY);
  wx.removeStorageSync(USER_KEY);
}

/**
 * 登录态失效统一处理
 * 清除本地登录信息并跳转登录页（已在登录页则不重复跳转）
 */
function handleUnauthorized(msg?: string) {
  clearAuth();

  if (redirectingToLogin) return;

  // 已在登录页则不再跳转
  const pages = getCurrentPages();
  if (pages.length > 0) {
    const current = pages[pages.length - 1].route || '';
    if (current.indexOf('pages/login/login') !== -1) return;
  }

  redirectingToLogin = true;
  wx.showToast({ title: msg || '登录已过期，请重新登录', icon: 'none' });

  setTimeout(() => {
    wx.navigateTo({
      url: LOGIN_PAGE,
      complete() {
        redirectingToLogin = false;
      },
    });
  }, 800);
}

/** 请求选项：silent401 不自动跳登录页；silentError 不弹统一提示（页面自己渲染错误态） */
export interface RequestOptions {
  /** 为 true 时，遇到 401 只静默失败、不跳转登录页
   *  （用于购物车角标这类后台辅助请求，避免一进页面就弹登录） */
  silent401?: boolean;

  /** 为 true 时不弹错误提示。
   *  页面自己会渲染持续的「加载失败 + 重试」错误态时用它，避免同一件事提示两遍。 */
  silentError?: boolean;
}

/** 统一请求方法 */
function request<T>(method: 'GET' | 'POST', url: string, data: any = {}, options: RequestOptions = {}): Promise<T> {
  return new Promise((resolve, reject) => {
    // 请求头：带上登录令牌（未登录时不带，由后端返回 401）
    const header: any = { 'Content-Type': 'application/json' };
    const token = getToken();
    if (token) {
      header['token'] = token;
    }

    wx.request({
      url: BASE_URL + url,
      method: method,
      // POST 直接传 JSON 字符串，GET 传对象由小程序拼装 query
      data: method === 'POST' ? JSON.stringify(data) : data,
      header: header,
      success(res: WechatMiniprogram.RequestSuccessCallbackResult) {
        const resp = res.data as ApiResponse<T>;

        if (resp && resp.code === 200) {
          resolve(resp.data);
          return;
        }

        // 登录态失效：统一跳转登录页（silent401 时只静默失败）
        if (resp && resp.code === 401) {
          if (!options.silent401) {
            handleUnauthorized(resp.msg);
          }
          reject(resp);
          return;
        }

        if (!options.silentError) {
          wx.showToast({ title: (resp && resp.msg) || '请求失败', icon: 'none' });
        }
        reject(resp);
      },
      fail(err: WechatMiniprogram.GeneralCallbackResult) {
        if (!options.silentError) {
          wx.showToast({ title: '网络异常，请检查后端服务', icon: 'none' });
        }
        reject(err);
      },
    });
  });
}

/** GET 请求 */
function get<T = any>(url: string, data: any = {}, options: RequestOptions = {}): Promise<T> {
  return request<T>('GET', url, data, options);
}

/** POST 请求 */
function post<T = any>(url: string, data: any = {}, options: RequestOptions = {}): Promise<T> {
  return request<T>('POST', url, data, options);
}

export { get, post, BASE_URL, getToken, saveAuth, clearAuth };

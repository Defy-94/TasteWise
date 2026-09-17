/**
 * 全局API请求封装
 * 统一管理后端接口地址、登录令牌注入、全局请求/响应拦截
 * 
 * ============================================
 * 【后端地址配置入口】修改 BASE_URL 即可切换服务器
 * ============================================
 * 
 * 【鉴权说明】登录成功后后端返回 token，本文件统一保存并在每个请求头中携带
 *            （header: token）。若后端返回 401（未登录/令牌过期），
 *            会自动清除本地登录信息并跳转登录页。
 */

// 后端API基础地址（本地phpstudy环境）
const BASE_URL = 'http://localhost';

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
 * 登录/注册接口返回的数据中带有 token 字段
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

/**
 * 统一请求方法
 * 自动携带登录令牌、统一处理 401 与业务错误
 */
function request<T>(method: 'GET' | 'POST', url: string, data: any = {}): Promise<T> {
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

        // 登录态失效：统一跳转登录页
        if (resp && resp.code === 401) {
          handleUnauthorized(resp.msg);
          reject(resp);
          return;
        }

        wx.showToast({ title: (resp && resp.msg) || '请求失败', icon: 'none' });
        reject(resp);
      },
      fail(err: WechatMiniprogram.GeneralCallbackResult) {
        wx.showToast({ title: '网络异常，请检查后端服务', icon: 'none' });
        reject(err);
      },
    });
  });
}

function get<T = any>(url: string, data: any = {}): Promise<T> {
  return request<T>('GET', url, data);
}

function post<T = any>(url: string, data: any = {}): Promise<T> {
  return request<T>('POST', url, data);
}

export { get, post, BASE_URL, getToken, saveAuth, clearAuth };

/**
 * 全局API请求封装
 * 统一管理后端接口地址，全局拦截请求/响应
 * 
 * ============================================
 * 【后端地址配置入口】修改 BASE_URL 即可切换服务器
 * ============================================
 */

// 后端API基础地址（本地phpstudy环境）
const BASE_URL = 'http://localhost';

interface ApiResponse<T> {
  code: number;
  msg: string;
  data: T;
}

function get<T = any>(url: string, data: any = {}): Promise<T> {
  return new Promise((resolve, reject) => {
    wx.request({
      url: BASE_URL + url,
      method: 'GET',
      data: data,
      header: {
        'Content-Type': 'application/json',
      },
      success(res: WechatMiniprogram.RequestSuccessCallbackResult) {
        const resp = res.data as ApiResponse<T>;
        if (resp.code === 200) {
          resolve(resp.data);
        } else {
          wx.showToast({ title: resp.msg || '请求失败', icon: 'none' });
          reject(resp);
        }
      },
      fail(err: WechatMiniprogram.GeneralCallbackResult) {
        wx.showToast({ title: '网络异常，请检查后端服务', icon: 'none' });
        reject(err);
      },
    });
  });
}

function post<T = any>(url: string, data: any = {}): Promise<T> {
  return new Promise((resolve, reject) => {
    // 将 data 对象转为 JSON 字符串，放入 body
    const jsonData = JSON.stringify(data);
    
    wx.request({
      url: BASE_URL + url,
      method: 'POST',
      data: jsonData,           // ← 关键：直接传 JSON 字符串
      header: {
        'Content-Type': 'application/json',   // ← 声明内容类型
      },
      success(res: WechatMiniprogram.RequestSuccessCallbackResult) {
        const resp = res.data as ApiResponse<T>;
        if (resp.code === 200) {
          resolve(resp.data);
        } else {
          wx.showToast({ title: resp.msg || '请求失败', icon: 'none' });
          reject(resp);
        }
      },
      fail(err: WechatMiniprogram.GeneralCallbackResult) {
        wx.showToast({ title: '网络异常，请检查后端服务', icon: 'none' });
        reject(err);
      },
    });
  });
}

export { get, post, BASE_URL };
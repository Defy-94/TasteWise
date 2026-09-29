/**
 * 购物车 TabBar 角标工具
 * 
 * 功能：把购物车里的商品件数显示在底部 TabBar 的「购物车」图标上
 * 
 * 【为什么单独抽成工具】
 *   角标是跨页面的公共状态：首页加购、AI 助手加购、购物车页增删改、登录、退出登录……
 *   这些地方都需要刷新它。集中在这里，避免每个页面各写一遍。
 * 
 * 【设计取舍】
 *   - 请求使用 silent401：角标只是辅助信息，不该因为令牌过期就把用户弹到登录页
 *   - 失败一律静默：网络异常时角标保持原样即可，不打扰用户
 */
import { get, getToken } from './api';
import { CartItem } from './types';

// 「购物车」在 app.json tabBar.list 中的下标：首页0 / 购物车1 / AI助手2 / 我的3
const CART_TAB_INDEX = 1;

/**
 * 设置角标数量
 * @param count 商品总件数；为 0 时移除角标
 */
function setCartBadge(count: number) {
  const n = Number(count) || 0;

  if (n > 0) {
    wx.setTabBarBadge({
      index: CART_TAB_INDEX,
      text: n > 99 ? '99+' : String(n),
      fail() {
        // 页面未就绪等情况下可能失败，忽略即可（角标非关键信息）
      },
    });
  } else {
    clearCartBadge();
  }
}

/** 移除角标 */
function clearCartBadge() {
  wx.removeTabBarBadge({
    index: CART_TAB_INDEX,
    fail() {},
  });
}

/**
 * 从后端拉取购物车并刷新角标
 * 适用于"手头没有购物车数据"的场景（如首页、AI 助手加购之后）
 */
async function refreshCartBadge() {
  // 未登录直接清空，不必发请求
  if (!getToken()) {
    clearCartBadge();
    return;
  }

  try {
    const list = await get<CartItem[]>('/api/cart/list.php', {}, { silent401: true, silentError: true });
    const count = (list || []).reduce((sum, item) => sum + Number(item.quantity), 0);
    setCartBadge(count);
  } catch (e) {
    // 静默失败：角标不是关键功能，不该弹提示打扰用户
  }
}

export { setCartBadge, clearCartBadge, refreshCartBadge };

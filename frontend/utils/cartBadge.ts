/**
 * 购物车 TabBar 角标工具
 * 角标是跨页面公共状态（首页/AI 助手加购、购物车增删改、登录退出都要刷新），集中在这里。
 * 请求用 silent401 且失败静默：角标只是辅助信息，不该因令牌过期把用户弹到登录页。
 */
import { get, getToken } from './api';
import { CartItem } from './types';

// 「购物车」在 app.json tabBar.list 中的下标：首页0 / 购物车1 / AI助手2 / 我的3
const CART_TAB_INDEX = 1;

/** 设置角标数量（为 0 时移除） */
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

/** 拉取购物车并刷新角标（用于手头没有购物车数据的场景） */
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

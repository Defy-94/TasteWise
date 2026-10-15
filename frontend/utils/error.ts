/**
 * 统一的错误处理
 *
 * ============================================
 * 【要解决的两个问题】
 *
 * 1. **反馈不一致**
 *    请求层（utils/api.ts）已经会弹一句 toast，但各页面又各写各的：
 *    有的页面额外再弹一次（同一件事提示两遍），有的页面什么都不做，
 *    用户以为操作成功了。
 *
 * 2. **「加载失败」和「没有数据」长得一模一样** —— 这个更麻烦
 *    网络断了的时候列表就是空的，而空列表的文案是「该分类下暂时没有菜品」。
 *    于是用户以为店里没菜，而不是「网络有问题、可以重试」。
 *
 * ============================================
 * 【分工】两者不重复：
 *   - 请求层：负责「一次性提示」—— 业务错误与网络错误都弹一句 toast
 *   - 页面  ：负责「持续可见的状态」—— 加载失败要渲染成带重试按钮的错误态
 *   页面自己渲染错误态时，用请求的 silentError 选项让请求层别弹提示。
 * ============================================
 */

/** 后端返回的错误结构（与 core/Response.php 的 error 分支一致） */
export interface ApiLikeError {
  code?: number;
  /** 后端给的可读原因 */
  msg?: string;
  /** 网络层失败时微信给的 errMsg */
  errMsg?: string;
}

/**
 * 从错误对象取一句可展示的话（后端 msg → 网络 errMsg → 兜底文案）
 * 后端不会把 SQL 报错、文件路径这类细节发给客户端，拿到的都能直接展示
 */
export function errorMessage(err: any, fallback: string = '操作失败，请稍后重试'): string {
  if (!err) {
    return fallback;
  }

  if (typeof err === 'string') {
    return err || fallback;
  }

  const e = err as ApiLikeError;
  const msg = e.msg || e.errMsg;

  return msg ? String(msg) : fallback;
}

/** 统一写日志：带场景名，方便在控制台按关键词筛选 */
export function logError(context: string, err: any): void {
  console.error('[' + context + ']', err);
}

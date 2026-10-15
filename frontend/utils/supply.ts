/**
 * 供应情况工具
 *
 * 【为什么要做这层转换】
 *   后端菜品表里存的是具体份数（stock），但"店里提前把 999 份菜做好"这件事本身
 *   不成立 —— 份数只是库存占位，直接摆给顾客看既生硬又容易让人误会。
 *   所以对外只呈现两个档位：供应充足 / 供应紧张。
 *
 * 【为什么阈值放在前端而不是改数据库】
 *   后端 stock 仍然是数字：加入购物车、下单扣库存等逻辑都依赖它做真实校验，
 *   改成枚举会牵动接口、校验和订单流程，风险远大于收益。
 *   这里只负责"显示层"的措辞，库存判断逻辑完全不受影响。
 *
 * 想让"紧张"更早出现/更晚出现，只改这一个常量。
 */

/** 低于这个份数就显示「供应紧张」 */
export const SUPPLY_TIGHT_THRESHOLD = 20;

export type SupplyLevel = 'ok' | 'tight' | 'out';

/** 供应档位：充足 / 紧张 / 已售完 */
export function supplyLevel(stock: number): SupplyLevel {
  const n = Number(stock);

  if (!isFinite(n) || n <= 0) return 'out';
  if (n < SUPPLY_TIGHT_THRESHOLD) return 'tight';
  return 'ok';
}

/** 列表/卡片上用的短标签 */
export function supplyLabel(stock: number): string {
  const level = supplyLevel(stock);

  if (level === 'out') return '已售完';
  if (level === 'tight') return '供应紧张';
  return '供应充足';
}

/** 详情页「供应情况」面板里的说明句 */
export function supplyDesc(stock: number): string {
  const level = supplyLevel(stock);

  if (level === 'out') return '已售完，暂时无法下单';
  if (level === 'tight') return '供应紧张，想吃的话建议尽早下单';
  return '供应充足，可正常下单';
}

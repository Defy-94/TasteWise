/**
 * 供应情况工具
 * 后端 stock 是具体份数，直接展示给顾客既生硬也容易误会，因此对外只呈现
 * 供应充足 / 供应紧张 两档。stock 本身仍是数字，加购与下单校验都依赖它。
 */

/** 低于这个份数显示「供应紧张」 */
const SUPPLY_TIGHT_THRESHOLD = 20;

type SupplyLevel = 'ok' | 'tight' | 'out';

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

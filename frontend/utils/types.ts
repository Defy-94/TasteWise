/** 全局类型定义 */

/** 菜品分类 */
export interface Category {
    id: number;
    name: string;
    sort_order: number;
    icon_url: string;
  }
  
  /** 菜品 */
  export interface Dish {
    id: number;
    category_id: number;
    name: string;
    price: number;
    image_url: string;
    description: string;
    spiciness: number;       // 辣度0-5
    is_recommend: number;    // 是否推荐 0/1
    stock: number;           // 库存（份数，仅用于后端校验，不直接展示给顾客）
    ai_reason?: string;      // AI推荐理由（可选）
    /**
     * 供应情况短标签：供应充足 / 供应紧张 / 已售完
     *
     * 由 utils/supply.ts 从 stock 换算出来，只在视图层使用
     * （顾客不该看到"还剩 999 份"这种内部数字，见 supply.ts 里的说明）
     */
    supplyLabel?: string;
  }

  /**
   * 菜品详情（GET /api/dish/detail.php）
   * 比列表多：category_name（分类名）、sold（累计售出，已取消的订单不计入）
   * 无 is_on_sale 字段：下架菜品的详情接口直接返回 404
   */
  export interface DishDetail extends Dish {
    category_name: string;
    sold: number;
  }
  
  /** 购物车项 */
  export interface CartItem {
    cart_id: number;
    quantity: number;
    dish_id: number;
    name: string;
    price: number;
    image_url: string;
    description: string;
  }
  
  /** 订单明细项 */
  /** 订单里的单个菜品行（只在本文件内被 Order/OrderDetail 引用，不用导出） */
  interface OrderItem {
    dish_name: string;
    dish_price: number;
    quantity: number;
  }
  
  /** 订单 */
  export interface Order {
    id: number;
    order_no: string;
    total_price: number;
    status: number;          // 0待支付 1已支付 2已完成 3已取消
    status_text?: string;    // 状态文本（由后端 order/list.php 返回）
    remark: string;
    created_at: string;
    items: OrderItem[];
  }
  
  /** 用户信息 */
  export interface UserInfo {
    id: number;
    openid: string;
    nickname: string;
    avatar_url: string;
    phone: string;
    address: string;
    created_at: string;
  }
  
  /** 登录/注册成功返回的用户信息（带登录令牌） */
  export interface AuthUser extends UserInfo {
    token?: string;          // 登录令牌，由后端签发，用于后续接口鉴权
  }
  
  /** 「跟上次一样 / 我的常点」确认卡里的单个菜品 */
  export interface RepeatOrderDish extends RecommendDish {
    quantity: number;
    times?: number;        // 「常点」时表示累计点过几份
  }

  /** 「跟上次一样 / 我的常点」确认卡 */
  export interface RepeatOrderCard {
    source: 'last' | 'regular';
    label: string;
    order_no: string;
    ordered_at?: string;
    dishes: RepeatOrderDish[];
    total: number;
    total_text: string;
    count: number;
    skipped?: number;      // 因下架未进入预览的菜品数
  }

  /** AI对话消息 */
  export interface ChatMessage {
    role: 'user' | 'assistant' | 'system';
    content: string;
  }
  
  /** 统一API响应结构 */
  export interface ApiResponse<T = any> {
    code: number;
    msg: string;
    data: T;
  }

  /** 订单状态：0待支付 1已支付 2已完成 3已取消（与数据库 orders.status 一致） */
  export type OrderStatus = 0 | 1 | 2 | 3;
  
  /** 订单状态文本（后端已返回 status_text，这里供兜底使用；键必须是数字） */
  export const OrderStatusMap: Record<number, string> = {
    0: '待支付',
    1: '已支付',
    2: '已完成',
    3: '已取消',
  };

  /** AI回复中的推荐菜品 */
export interface RecommendDish {
    id: number;
    category_id: number;
    name: string;
    price: number;
    image_url: string;
    description: string;
    spiciness: number;
    ai_reason: string;       // AI推荐理由
  }
  
  /** AI对话消息扩展 */
  export interface ChatMessageExtended {
    role: 'user' | 'assistant' | 'system';
    content: string;
    recommendDishes?: RecommendDish[];   // 可选：AI回复附带的推荐菜品
  }
  
  /** AI对话接口返回结构 */
  export interface AIChatResult {
    reply: string;
    recommend_dishes: RecommendDish[];
    has_recommend: boolean;
    repeat_order?: RepeatOrderCard | null;
  }

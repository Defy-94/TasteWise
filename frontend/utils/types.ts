/**
 * 全局TS类型定义文件
 * 统一封装菜品、订单、用户、AI问答等数据类型
 * 适配TS基础模版规范
 */

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
    stock: number;           // 库存
    ai_reason?: string;      // AI推荐理由（可选）
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
  export interface OrderItem {
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
    status_text?: string;    // 状态文本（前端计算）
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
  
  /** AI对话消息 */
  export interface ChatMessage {
    role: 'user' | 'assistant' | 'system';
    content: string;
  }
  
  /** AI推荐结果 */
  export interface AIRecommendResult {
    recommendations: Dish[];
    total: number;
  }
  
  /** 统一API响应结构 */
  export interface ApiResponse<T = any> {
    code: number;
    msg: string;
    data: T;
  }

  /** 注册信息 */
export interface RegisterInfo {
    username: string;
    phone: string;
    code: string;
  }
  
  /** 登录信息 */
  export interface LoginInfo {
    phone: string;
  }
  
  /** 订单状态枚举 */
  export type OrderStatus = 'pending' | 'paid' | 'completed' | 'cancelled';
  
  /** 订单状态映射 */
  export const OrderStatusMap: Record<OrderStatus, string> = {
    pending: '待支付',
    paid: '已支付',
    completed: '已完成',
    cancelled: '已取消',
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
  }
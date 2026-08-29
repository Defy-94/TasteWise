-- ============================================
-- 微信点餐小程序数据库初始化脚本
-- 数据库名: wechat_order
-- ============================================

-- 创建数据库（如果不存在则创建）
CREATE DATABASE IF NOT EXISTS wechat_order DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE wechat_order;

-- ============================================
-- 用户表 (预留扩展字段: extra_field1, extra_field2)
-- ============================================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '用户ID',
    `openid` VARCHAR(100) NOT NULL UNIQUE COMMENT '微信openid',
    `nickname` VARCHAR(50) DEFAULT '微信用户' COMMENT '用户昵称',
    `avatar_url` VARCHAR(500) DEFAULT '' COMMENT '头像URL',
    `phone` VARCHAR(20) DEFAULT '' COMMENT '手机号',
    `address` VARCHAR(200) DEFAULT '' COMMENT '默认地址',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `extra_field1` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段1',
    `extra_field2` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段2',
    INDEX idx_openid (`openid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- ============================================
-- 菜品分类表
-- ============================================
DROP TABLE IF EXISTS `categories`;
CREATE TABLE `categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '分类ID',
    `name` VARCHAR(50) NOT NULL COMMENT '分类名称',
    `sort_order` INT DEFAULT 0 COMMENT '排序权重(越大越靠前)',
    `icon_url` VARCHAR(500) DEFAULT '' COMMENT '分类图标URL',
    `extra_field1` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段1',
    `extra_field2` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段2',
    INDEX idx_sort (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='菜品分类表';

-- ============================================
-- 菜品表
-- ============================================
DROP TABLE IF EXISTS `dishes`;
CREATE TABLE `dishes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '菜品ID',
    `category_id` INT UNSIGNED NOT NULL COMMENT '所属分类ID',
    `name` VARCHAR(100) NOT NULL COMMENT '菜品名称',
    `price` DECIMAL(10,2) NOT NULL COMMENT '价格',
    `image_url` VARCHAR(500) DEFAULT '' COMMENT '菜品图片URL',
    `description` VARCHAR(500) DEFAULT '' COMMENT '菜品描述',
    `spiciness` TINYINT DEFAULT 0 COMMENT '辣度(0-5)',
    `is_recommend` TINYINT DEFAULT 0 COMMENT '是否推荐(0否1是)',
    `stock` INT DEFAULT 999 COMMENT '库存数量',
    `extra_field1` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段1',
    `extra_field2` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段2',
    INDEX idx_category (`category_id`),
    FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='菜品表';

-- ============================================
-- 购物车表
-- ============================================
DROP TABLE IF EXISTS `cart`;
CREATE TABLE `cart` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '购物车记录ID',
    `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
    `dish_id` INT UNSIGNED NOT NULL COMMENT '菜品ID',
    `quantity` INT DEFAULT 1 COMMENT '数量',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '加入时间',
    `extra_field1` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段1',
    `extra_field2` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段2',
    INDEX idx_user (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`dish_id`) REFERENCES `dishes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='购物车表';

-- ============================================
-- 订单表
-- ============================================
DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '订单ID',
    `order_no` VARCHAR(32) NOT NULL UNIQUE COMMENT '订单编号',
    `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
    `total_price` DECIMAL(10,2) NOT NULL COMMENT '订单总金额',
    `status` TINYINT DEFAULT 0 COMMENT '订单状态(0待支付1已支付2已完成3已取消)',
    `remark` VARCHAR(200) DEFAULT '' COMMENT '备注',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
    `extra_field1` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段1',
    `extra_field2` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段2',
    INDEX idx_user (`user_id`),
    INDEX idx_order_no (`order_no`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单表';

-- ============================================
-- 订单明细表(订单关联的菜品明细)
-- ============================================
DROP TABLE IF EXISTS `order_items`;
CREATE TABLE `order_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '明细ID',
    `order_id` INT UNSIGNED NOT NULL COMMENT '订单ID',
    `dish_id` INT UNSIGNED NOT NULL COMMENT '菜品ID',
    `dish_name` VARCHAR(100) NOT NULL COMMENT '菜品名称(冗余)',
    `dish_price` DECIMAL(10,2) NOT NULL COMMENT '菜品单价(冗余)',
    `quantity` INT NOT NULL COMMENT '数量',
    `extra_field1` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段1',
    `extra_field2` VARCHAR(200) DEFAULT '' COMMENT '预留扩展字段2',
    INDEX idx_order (`order_id`),
    FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单明细表';

-- ============================================
-- 插入测试数据
-- ============================================
INSERT INTO `categories` (`name`, `sort_order`) VALUES
('热菜', 10),
('凉菜', 8),
('汤品', 6),
('主食', 4),
('饮品', 2);

INSERT INTO `dishes` (`category_id`, `name`, `price`, `description`, `spiciness`, `is_recommend`) VALUES
(1, '宫保鸡丁', 28.00, '经典川菜，鸡肉嫩滑，花生香脆', 3, 1),
(1, '麻婆豆腐', 22.00, '麻辣鲜香，下饭神器', 4, 1),
(1, '糖醋里脊', 32.00, '酸甜可口，外酥里嫩', 0, 1),
(2, '凉拌黄瓜', 12.00, '清爽开胃小菜', 0, 0),
(2, '皮蛋豆腐', 15.00, '经典凉菜搭配', 0, 0),
(3, '番茄蛋汤', 10.00, '家常美味汤品', 0, 1),
(3, '酸辣汤', 12.00, '酸辣开胃', 2, 0),
(4, '蛋炒饭', 15.00, '粒粒分明，香气四溢', 0, 0),
(4, '阳春面', 10.00, '清淡爽口', 0, 0),
(5, '冰镇柠檬水', 6.00, '清凉解暑', 0, 1);
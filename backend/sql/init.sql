-- ============================================================
-- TasteWise 智味 · AI 点餐微信小程序 —— 数据库初始化脚本
-- 数据库名：wechat_order
--
-- 【使用方式】
--   phpMyAdmin 或命令行中整体执行本文件即可完成建库、建表与演示数据导入：
--     mysql -uroot -p < init.sql
--
-- 【重要提醒】
--   本脚本会 DROP 并重建全部业务表，仅在全新环境初始化时执行；
--   已有数据的库请勿执行，否则数据将被清空！
--
-- 【结构说明】本脚本与项目实际运行库结构保持一致，包含：
--   1) user_id_counter 用户ID计数器表（注册时用于分配 10001 起的用户ID）
--   2) users 表含 username 字段，且 openid / username / phone 均为唯一索引
--   3) orders.status 为 varchar(20)，取值为数字字符串 0/1/2/3
--      （0待支付 1已支付 2已完成 3已取消，代码按数字比较）
--   4) dishes 含 is_on_sale 字段（管理后台的「上架/下架」开关，
--      小程序端只展示上架菜品；下架不影响历史订单，因为 order_items 冗余了菜名与单价）
-- ============================================================

-- ---------- 创建数据库 ----------
CREATE DATABASE IF NOT EXISTS `wechat_order` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `wechat_order`;

-- ---------- 清理旧表（按依赖关系逆序删除） ----------
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `cart`;
DROP TABLE IF EXISTS `dishes`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `user_id_counter`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------- user_id_counter：用户ID计数器表（注册时生成 10001 起的用户ID，配合行锁防并发） ----------
CREATE TABLE `user_id_counter` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `counter_name` varchar(50) NOT NULL COMMENT '计数器名称',
  `current_value` int(10) unsigned NOT NULL DEFAULT '10000' COMMENT '当前值（初始10000）',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `counter_name` (`counter_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户ID计数器';

-- ---------- users：用户表（openid / username / phone 均为唯一索引） ----------
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `openid` varchar(100) NOT NULL COMMENT '微信openid',
  `username` varchar(50) DEFAULT '' COMMENT '用户名',
  `nickname` varchar(50) DEFAULT '微信用户' COMMENT '用户昵称',
  `avatar_url` varchar(500) DEFAULT '' COMMENT '头像URL',
  `phone` varchar(20) DEFAULT '' COMMENT '手机号',
  `address` varchar(200) DEFAULT '' COMMENT '默认地址',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `extra_field1` varchar(200) DEFAULT '' COMMENT '预留扩展字段1',
  `extra_field2` varchar(200) DEFAULT '' COMMENT '预留扩展字段2',
  PRIMARY KEY (`id`),
  UNIQUE KEY `openid` (`openid`),
  UNIQUE KEY `idx_username` (`username`),
  UNIQUE KEY `idx_phone` (`phone`),
  KEY `idx_openid` (`openid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户表';

-- ---------- categories：菜品分类表 ----------
CREATE TABLE `categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '分类ID',
  `name` varchar(50) NOT NULL COMMENT '分类名称',
  `sort_order` int(11) DEFAULT '0' COMMENT '排序权重(越大越靠前)',
  `icon_url` varchar(500) DEFAULT '' COMMENT '分类图标URL',
  `extra_field1` varchar(200) DEFAULT '' COMMENT '预留扩展字段1',
  `extra_field2` varchar(200) DEFAULT '' COMMENT '预留扩展字段2',
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='菜品分类表';

-- ---------- dishes：菜品表（外键关联分类，分类删除级联删除菜品） ----------
CREATE TABLE `dishes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '菜品ID',
  `category_id` int(10) unsigned NOT NULL COMMENT '所属分类ID',
  `name` varchar(100) NOT NULL COMMENT '菜品名称',
  `price` decimal(10,2) NOT NULL COMMENT '价格',
  `image_url` varchar(500) DEFAULT '' COMMENT '菜品图片URL',
  `description` varchar(500) DEFAULT '' COMMENT '菜品描述',
  `spiciness` tinyint(4) DEFAULT '0' COMMENT '辣度(0-5)',
  `is_recommend` tinyint(4) DEFAULT '0' COMMENT '是否推荐(0否1是)',
  `stock` int(11) DEFAULT '999' COMMENT '库存数量',
  `is_on_sale` tinyint(4) NOT NULL DEFAULT '1' COMMENT '是否上架(1上架0下架)',
  `extra_field1` varchar(200) DEFAULT '' COMMENT '预留扩展字段1',
  `extra_field2` varchar(200) DEFAULT '' COMMENT '预留扩展字段2',
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  CONSTRAINT `dishes_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='菜品表';

-- ---------- cart：购物车表 ----------
CREATE TABLE `cart` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '购物车记录ID',
  `user_id` int(10) unsigned NOT NULL COMMENT '用户ID',
  `dish_id` int(10) unsigned NOT NULL COMMENT '菜品ID',
  `quantity` int(11) DEFAULT '1' COMMENT '数量',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '加入时间',
  `extra_field1` varchar(200) DEFAULT '' COMMENT '预留扩展字段1',
  `extra_field2` varchar(200) DEFAULT '' COMMENT '预留扩展字段2',
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `dish_id` (`dish_id`),
  CONSTRAINT `cart_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `cart_ibfk_2` FOREIGN KEY (`dish_id`) REFERENCES `dishes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='购物车表';

-- ---------- orders：订单表（status 为 varchar(20)，存 0/1/2/3） ----------
CREATE TABLE `orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '订单ID',
  `order_no` varchar(32) NOT NULL COMMENT '订单编号',
  `user_id` int(10) unsigned NOT NULL COMMENT '用户ID',
  `total_price` decimal(10,2) NOT NULL COMMENT '订单总金额',
  `status` varchar(20) DEFAULT '0' COMMENT '订单状态：0待支付 1已支付 2已完成 3已取消',
  `remark` varchar(200) DEFAULT '' COMMENT '备注',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新时间',
  `extra_field1` varchar(200) DEFAULT '' COMMENT '预留扩展字段1',
  `extra_field2` varchar(200) DEFAULT '' COMMENT '预留扩展字段2',
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_no` (`order_no`),
  KEY `idx_user` (`user_id`),
  KEY `idx_order_no` (`order_no`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单表';

-- ---------- order_items：订单明细表（菜品名称/单价冗余存储，防止菜品改动影响历史订单） ----------
CREATE TABLE `order_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT COMMENT '明细ID',
  `order_id` int(10) unsigned NOT NULL COMMENT '订单ID',
  `dish_id` int(10) unsigned NOT NULL COMMENT '菜品ID',
  `dish_name` varchar(100) NOT NULL COMMENT '菜品名称(冗余)',
  `dish_price` decimal(10,2) NOT NULL COMMENT '菜品单价(冗余)',
  `quantity` int(11) NOT NULL COMMENT '数量',
  `extra_field1` varchar(200) DEFAULT '' COMMENT '预留扩展字段1',
  `extra_field2` varchar(200) DEFAULT '' COMMENT '预留扩展字段2',
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='订单明细表';

-- ============================================================
-- 演示数据
-- ============================================================

-- ---------- 菜品分类（sort_order 越大越靠前） ----------
INSERT INTO `categories` (`id`, `name`, `sort_order`, `icon_url`) VALUES
(1, '热菜', 10, ''),
(2, '凉菜', 8, ''),
(3, '汤品', 6, ''),
(4, '主食', 4, ''),
(5, '饮品', 2, '');

-- ---------- 菜品（含图片与描述，库存默认 999） ----------
INSERT INTO `dishes` (`id`, `category_id`, `name`, `price`, `image_url`, `description`, `spiciness`, `is_recommend`, `stock`) VALUES
(1, 1, '宫保鸡丁', '28.00', 'https://ts1.tc.mm.bing.net/th/id/OIP-C.nMiv8BOMKfBcX1sUmxdwFQHaEJ?w=193&h=135&c=8&rs=1&qlt=90&o=6&dpr=1.5&pid=ImgAns&rm=2', '经典川菜，鸡肉嫩滑，花生香脆', 3, 1, 999),
(2, 1, '麻婆豆腐', '22.00', 'https://ts1.tc.mm.bing.net/th/id/R-C.2842ebed1c91a3e4747b6ebbdda2026a?rik=xnQWE1sPxNrOjw&riu=http%3a%2f%2fi2.hdslb.com%2fbfs%2farchive%2f0a7daed5e4a52ca5dbf1aa18cbffd5362719fe81.jpg&ehk=6gRq1RhQbYRigMuzPv7lfASg%2b1W4shKPdJa6llOY5x8%3d&risl=&pid=ImgRaw&r=0', '麻辣鲜香，下饭神器', 4, 1, 999),
(3, 1, '糖醋里脊', '32.00', 'https://ts1.tc.mm.bing.net/th/id/R-C.1508dd6e5f58f897f5efada7766dca47?rik=y8S7%2fYx%2bE5UT9w&riu=http%3a%2f%2f5b0988e595225.cdn.sohucs.com%2fimages%2f20190802%2f42c5927671164e47be8f744bf6d81ca0.jpeg&ehk=YTbIIErVCbW%2fhoDBrCBQNc%2fpJtLf1fFzov12o323JhE%3d&risl=&pid=ImgRaw&r=0', '酸甜可口，外酥里嫩', 0, 1, 999),
(4, 2, '凉拌黄瓜', '12.00', 'https://pic1.zhimg.com/v2-b6ead3fee1347f50716fec5e6fdbc849_1440w.jpg?source=172ae18b', '清爽开胃小菜', 0, 0, 999),
(5, 2, '皮蛋豆腐', '15.00', 'https://cp1.douguo.com/upload/caiku/6/a/6/yuan_6a4a7b53b22a1901ac9d6cdbedba55d6.jpeg', '经典凉菜搭配', 0, 0, 999),
(6, 3, '番茄蛋汤', '10.00', 'https://materials.cdn.bcebos.com/images/116661319/0f69e8ad4125143892dc82be8099a0d1.jpeg', '家常美味汤品', 0, 1, 999),
(7, 3, '酸辣汤', '12.00', 'https://imgs.699pic.com/images/600/301/654.jpg!list1x.v2', '酸辣开胃', 2, 0, 999),
(8, 4, '蛋炒饭', '15.00', 'https://ts1.tc.mm.bing.net/th/id/OIP-C.1uv7Ld5-VajUdyKRfDUszwHaHY?r=0&rs=1&pid=ImgDetMain&o=7&rm=3', '粒粒分明，香气四溢', 0, 0, 999),
(9, 4, '阳春面', '10.00', 'https://ts1.tc.mm.bing.net/th/id/R-C.9b6c259b78f31a3b3e11afed48a7afc3?rik=jxG%2fdHnDPWm1lA&riu=http%3a%2f%2fi2.chuimg.com%2f47b37d4a8af211e6a9a10242ac110002_1440w_1080h.jpg%3fimageView2%2f2%2fw%2f660%2finterlace%2f1%2fq%2f90&ehk=XGbiDAjcAJoFTUtECYCN69X6QfbAjnQX%2by9sbnRl1Dk%3d&risl=&pid=ImgRaw&r=0', '清淡爽口', 0, 0, 999),
(10, 5, '冰镇柠檬水', '6.00', 'https://ts3.tc.mm.bing.net/th/id/OIP-C.SPazOoGeBKNX7miEp37dMQHaEK?r=0&rs=1&pid=ImgDetMain&o=7&rm=3s', '清凉解暑', 0, 1, 999),
(11, 1, '鱼香肉丝', '26.00', 'https://materials.cdn.bcebos.com/images/113382875/ab92338842009dbb3861c0366a1c0aee.jpeg', '经典川菜，咸甜酸辣兼备，肉丝嫩滑', 2, 1, 999),
(12, 1, '红烧茄子', '20.00', 'https://materials.cdn.bcebos.com/images/4217004/792d3c6eb12eabb4b86476dd7cd6271a.jpeg', '软糯入味，酱香浓郁，下饭佳品', 1, 0, 999),
(13, 2, '口水鸡', '28.00', 'https://www.shuomingshu.cn/wp-content/uploads/images/2022/11/22/13af98c9064840efa55785d015c9bd78_2pqfuoxx5p1.jpg', '川味凉菜，麻辣鲜香，鸡肉细嫩', 3, 1, 999),
(14, 2, '凉拌木耳', '14.00', 'https://materials.cdn.bcebos.com/images/58591605/939c5ca66a375bae32d316867095bd42.jpeg', '清爽脆嫩，酸辣开胃', 1, 0, 999),
(15, 3, '玉米排骨汤', '22.00', 'https://materials.cdn.bcebos.com/images/31941712/06e074f872c38693d14abe12f4639947.jpeg', '清甜鲜美，排骨软烂，营养丰富', 0, 1, 999),
(16, 3, '紫菜蛋花汤', '8.00', 'https://ts1.tc.mm.bing.net/th/id/R-C.a4eec937c28a450ce61ee58c36cfdf0b?rik=BfnbxTlZPaOsUA&riu=http%3a%2f%2fn.sinaimg.cn%2fsinacn00%2f196%2fw1024h772%2f20180831%2f5443-hinpmnq3063208.jpg&ehk=0F%2bcsIQkMh7BUTbNS5hi39mhZPajzhW1RJzrqBH5AIo%3d&risl=&pid=ImgRaw&r=0', '清淡爽口，经典家常汤品', 0, 0, 999),
(17, 4, '牛肉炒饭', '22.00', 'https://ts1.tc.mm.bing.net/th/id/R-C.f4ec97f0cc63143718140a1a5889c5a1?rik=P4jS21hZsaoztg&riu=http%3a%2f%2f5b0988e595225.cdn.sohucs.com%2fimages%2f20181119%2f49a81d3f8df74045a57b1621c5915dbc.jpeg&ehk=WCjX%2bXEoQSqees6W4QDkxTISLnQHL3NCtzbHbs2iag0%3d&risl=&pid=ImgRaw&r=0', '牛肉粒饱满，米饭粒粒分明', 1, 1, 999),
(18, 4, '葱油拌面', '12.00', 'https://vsd-picture.cdn.bcebos.com/4becae52a44fc2a265491f58571f2fe02dc7ec6a.jpg?x-bce-process=image%2Fquality%2Cq_80', '葱香浓郁，面条劲道', 0, 0, 999),
(19, 5, '酸梅汤', '8.00', 'https://ts1.tc.mm.bing.net/th/id/R-C.e8cd649211857195d30032e0922586e1?rik=90wP2DewcXt5zg&riu=http%3a%2f%2fn.sinaimg.cn%2fsinacn10111%2f194%2fw593h401%2f20200218%2f58c0-iprtayz2048995.jpg&ehk=h4p4NiIa1iiMVJdkRi9xTpA1j8fpK8G5U229YnDii9o%3d&risl=&pid=ImgRaw&r=0', '酸甜解腻，冰镇更佳', 0, 1, 999),
(20, 5, '鲜榨西瓜汁', '15.00', 'https://p1.ssl.qhimg.com/t014a335b42aadb93dc.png', '新鲜西瓜现榨，清甜解暑', 0, 0, 999);

-- ---------- 用户ID计数器初始值（注册后第一个用户ID为 10001） ----------
INSERT INTO `user_id_counter` (`counter_name`, `current_value`) VALUES ('user_id', 10000);

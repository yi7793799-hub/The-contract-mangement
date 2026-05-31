-- phpMyAdmin SQL Dump
-- version 4.8.5
-- https://www.phpmyadmin.net/
--
-- 主机： localhost
-- 生成日期： 2026-04-22 00:05:28
-- 服务器版本： 5.7.26
-- PHP 版本： 7.3.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 数据库： `mf_barbershop`
--

-- --------------------------------------------------------

--
-- 表的结构 `admins`
--

CREATE TABLE `admins` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `email` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL ,
  `role` enum('super','normal','sales') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `status` enum('normal','disabled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `permissions` text COLLATE utf8mb4_unicode_ci
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `admins`
--

INSERT INTO `admins` (`id`, `username`, `password_hash`, `display_name`, `email`, `created_at`, `updated_at`, `role`, `status`, `phone`, `permissions`) VALUES
(1, 'admin', '$2y$10$3iw39gRfQVOnx9by3DnvkOu0toUaw7PRvCpp09jutoWmI3wr0Q0Dy', '超级管理员', '', '2026-04-19 11:21:37', '2026-04-20 21:25:08', 'super', 'normal', NULL, NULL);

-- --------------------------------------------------------

--
-- 表的结构 `app_settings`
--

CREATE TABLE `app_settings` (
  `id` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `site_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `logo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL ,
  `own_contract_only` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `app_settings`
--

INSERT INTO `app_settings` (`id`, `site_name`, `logo_path`, `updated_at`, `own_contract_only`) VALUES
(1, '云云合同管理系统', 'uploads/branding/logo_20260419214233_a07754a6.png', '2026-04-21 21:53:48', 1);

-- --------------------------------------------------------

--
-- 表的结构 `contracts`
--

CREATE TABLE `contracts` (
  `id` int(10) UNSIGNED NOT NULL,
  `contract_no` varchar(64) NOT NULL,
  `contract_name` varchar(180) NOT NULL,
  `customer_name` varchar(180) NOT NULL DEFAULT '',
  `signer_party` varchar(180) NOT NULL DEFAULT '',
  `signer_name` varchar(80) NOT NULL DEFAULT '',
  `phone` varchar(40) NOT NULL DEFAULT '',
  `amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `signed_date` date DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` enum('ongoing','completed','terminated','expiring') NOT NULL DEFAULT 'ongoing',
  `type_id` int(10) UNSIGNED DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT 0 ,
  `payment_type` enum('receipt','payment') NOT NULL DEFAULT 'receipt',
  `is_archived` tinyint(1) NOT NULL DEFAULT '0',
  `archived_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `contract_files`
--

CREATE TABLE `contract_files` (
  `id` int(10) UNSIGNED NOT NULL,
  `contract_id` int(10) UNSIGNED NOT NULL,
  `origin_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `mime_type` varchar(120) NOT NULL DEFAULT '',
  `file_size` bigint(20) UNSIGNED NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `contract_invoices`
--

CREATE TABLE `contract_invoices` (
  `id` int(10) UNSIGNED NOT NULL,
  `contract_id` int(10) UNSIGNED NOT NULL,
  `invoice_type` enum('receipt','payment') NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `note` varchar(255) NOT NULL DEFAULT '',
  `file_path` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `contract_settings`
--

CREATE TABLE `contract_settings` (
  `id` tinyint(3) UNSIGNED NOT NULL DEFAULT '1',
  `remind_days` int(10) UNSIGNED NOT NULL DEFAULT '15',
  `updated_at` datetime NOT NULL DEFAULT 0 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- 转存表中的数据 `contract_settings`
--

INSERT INTO `contract_settings` (`id`, `remind_days`, `updated_at`) VALUES
(1, 5, '2026-04-21 23:45:52');

-- --------------------------------------------------------

--
-- 表的结构 `contract_transactions`
--

CREATE TABLE `contract_transactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `contract_id` int(10) UNSIGNED NOT NULL,
  `tx_type` enum('receipt','payment') NOT NULL,
  `amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `note` varchar(255) NOT NULL DEFAULT '',
  `voucher_path` varchar(255) DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `contract_tx_undo_once`
--

CREATE TABLE `contract_tx_undo_once` (
  `id` int(10) UNSIGNED NOT NULL,
  `contract_id` int(10) UNSIGNED NOT NULL,
  `tx_type` enum('receipt','payment') NOT NULL,
  `undone_tx_id` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- 转存表中的数据 `contract_tx_undo_once`
--

INSERT INTO `contract_tx_undo_once` (`id`, `contract_id`, `tx_type`, `undone_tx_id`, `created_at`) VALUES
(3, 1, 'receipt', 5, '2026-04-21 20:37:03');

-- --------------------------------------------------------

--
-- 表的结构 `contract_types`
--

CREATE TABLE `contract_types` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT 0 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- 表的结构 `members`
--

CREATE TABLE `members` (
  `id` int(10) UNSIGNED NOT NULL,
  `card_number` char(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `gender` enum('male','female','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'male',
  `phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `card_type` enum('savings','times') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'savings',
  `balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `times_remaining` int(10) UNSIGNED NOT NULL DEFAULT '0',
  `expiry_date` date DEFAULT NULL,
  `status` enum('active','inactive') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `remark` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT 0,
  `updated_at` datetime DEFAULT NULL 
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `members`
--

INSERT INTO `members` (`id`, `card_number`, `name`, `gender`, `phone`, `card_type`, `balance`, `times_remaining`, `expiry_date`, `status`, `remark`, `created_at`, `updated_at`) VALUES
(1, '68485449', '张三', 'male', '13542658663', 'times', '0.00', 142, NULL, 'active', NULL, '2026-04-19 11:25:07', '2026-04-19 21:23:13'),
(2, '12823495', '李四', 'male', '13342248225', 'savings', '1200.00', 0, NULL, 'active', NULL, '2026-04-19 13:24:45', NULL),
(3, '91000001', '张晓明', 'male', '13800138001', 'savings', '1288.50', 0, NULL, 'active', '常客，偏好洗剪吹', '2026-04-19 13:27:30', NULL),
(4, '91000002', '李思琪', 'female', '13800138002', 'savings', '356.00', 0, '2026-12-31', 'active', '储值卡', '2026-04-19 13:27:30', NULL),
(5, '91000003', '王浩', 'male', '13800138003', 'times', '0.00', 10, NULL, 'active', '次卡：洗吹套餐', '2026-04-19 13:27:30', NULL),
(6, '91000004', '赵敏', 'female', '13800138004', 'savings', '5000.00', 0, NULL, 'active', 'VIP', '2026-04-19 13:27:30', NULL),
(7, '91000005', '刘洋', 'male', '13800138005', 'savings', '0.00', 0, NULL, 'inactive', '已停用示例', '2026-04-19 13:27:30', NULL),
(8, '91000006', '陈静', 'female', '13800138006', 'savings', '199.90', 0, NULL, 'active', NULL, '2026-04-19 13:27:30', NULL),
(9, '91000007', '周杰', 'male', '13800138007', 'times', '0.00', 3, '2026-06-30', 'active', '剩余次数较少', '2026-04-19 13:27:30', NULL),
(10, '91000008', '吴芳', 'female', '13800138008', 'savings', '88.88', 0, NULL, 'active', NULL, '2026-04-19 13:27:30', NULL),
(11, '91000009', '郑强', 'male', '13800138009', 'savings', '2200.00', 0, NULL, 'active', '推荐办卡', '2026-04-19 13:27:30', NULL),
(12, '91000010', '孙丽', 'female', '13800138010', 'savings', '45.00', 0, NULL, 'active', NULL, '2026-04-19 13:27:30', NULL),
(13, '91000011', '马超', 'male', '13800138011', 'times', '0.00', 19, NULL, 'active', '烫染次卡', '2026-04-19 13:27:30', '2026-04-19 22:23:02'),
(14, '91000012', '黄娟', 'female', '13800138012', 'savings', '666.00', 0, NULL, 'active', NULL, '2026-04-19 13:27:30', NULL),
(15, '91000013', '林峰', 'male', '13800138013', 'savings', '12.50', 0, NULL, 'active', '新客', '2026-04-19 13:27:30', NULL),
(16, '91000014', '何欣', 'female', '13800138014', 'savings', '1065.00', 0, NULL, 'active', NULL, '2026-04-19 13:27:30', '2026-04-19 22:22:23'),
(17, '91000015', '杨帆', 'male', '13800138015', 'savings', '1580.00', 0, '2027-01-01', 'active', '测试数据', '2026-04-19 13:27:30', NULL);

-- --------------------------------------------------------

--
-- 表的结构 `member_wallet_records`
--

CREATE TABLE `member_wallet_records` (
  `id` int(10) UNSIGNED NOT NULL,
  `member_id` int(10) UNSIGNED NOT NULL,
  `kind` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_no` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `gift_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `counterparty_member_id` int(10) UNSIGNED DEFAULT NULL,
  `payment_method` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `staff_name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text COLLATE utf8mb4_unicode_ci,
  `balance_after` decimal(12,2) NOT NULL DEFAULT '0.00',
  `times_delta` int(11) NOT NULL DEFAULT '0',
  `times_after` int(10) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `member_wallet_records`
--

INSERT INTO `member_wallet_records` (`id`, `member_id`, `kind`, `order_no`, `amount`, `gift_amount`, `counterparty_member_id`, `payment_method`, `staff_name`, `remark`, `balance_after`, `times_delta`, `times_after`, `created_at`) VALUES
(1, 1, 'recharge_times', 'CT260419125008960696', '0.00', '0.00', NULL, NULL, NULL, NULL, '0.00', 10, 18, '2026-04-19 12:50:08'),
(2, 16, 'recharge', 'CZ260419195714901916', '1000.00', '100.00', NULL, NULL, NULL, NULL, '1100.00', 0, NULL, '2026-04-19 19:57:14'),
(3, 1, 'recharge_times', 'CT260419205045661698', '0.00', '0.00', NULL, NULL, NULL, NULL, '0.00', 120, 134, '2026-04-19 20:50:45'),
(4, 1, 'recharge_times', 'CT260419212313889277', '800.00', '0.00', NULL, NULL, NULL, NULL, '0.00', 10, 142, '2026-04-19 21:23:13');

-- --------------------------------------------------------

--
-- 表的结构 `orders`
--

CREATE TABLE `orders` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_no` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `member_id` int(10) UNSIGNED DEFAULT NULL,
  `is_walk_in` tinyint(1) NOT NULL DEFAULT '0',
  `order_datetime` datetime NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payable_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `remark` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 转存表中的数据 `orders`
--

INSERT INTO `orders` (`id`, `order_no`, `member_id`, `is_walk_in`, `order_datetime`, `total_amount`, `discount_amount`, `payable_amount`, `payment_method`, `remark`, `created_at`) VALUES
(1, 'SP2604191128446674', NULL, 1, '2026-04-19 11:28:00', '35.00', '0.00', '35.00', 'cash', NULL, '2026-04-19 11:28:44'),
(2, 'SP2604191131549291', 1, 0, '2026-04-19 11:31:00', '35.00', '0.00', '35.00', 'cash', NULL, '2026-04-19 11:31:54'),
(3, 'SP2604191146124208', 1, 0, '2026-04-19 11:43:00', '35.00', '0.00', '35.00', 'cash', NULL, '2026-04-19 11:46:12'),
(4, 'SP2604191150214856', NULL, 1, '2026-04-19 11:49:00', '35.00', '0.00', '35.00', 'cash', NULL, '2026-04-19 11:50:21'),
(5, 'SP2604191153338698', NULL, 1, '2026-04-19 11:53:00', '35.00', '0.00', '35.00', 'cash', NULL, '2026-04-19 11:53:33'),
(6, 'SP2604191153400141', NULL, 1, '2026-04-19 11:53:00', '35.00', '0.00', '35.00', 'cash', NULL, '2026-04-19 11:53:40'),
(7, 'SP2604191153481703', NULL, 1, '2026-04-19 11:53:00', '35.00', '0.00', '35.00', 'cash', NULL, '2026-04-19 11:53:48'),
(8, 'SP2604191154356599', NULL, 1, '2026-04-19 11:54:00', '35.00', '0.00', '35.00', 'cash', NULL, '2026-04-19 11:54:35'),
(9, 'SP2604191201400443', NULL, 1, '2026-04-19 12:01:00', '35.00', '0.00', '35.00', 'cash', NULL, '2026-04-19 12:01:40'),
(10, 'SP2604191218038539', NULL, 1, '2026-04-19 12:17:00', '35.00', '0.00', '35.00', 'scan', NULL, '2026-04-19 12:18:03'),
(11, 'SP2604191224272871', NULL, 1, '2026-04-19 12:24:00', '138.00', '0.00', '138.00', 'cash', NULL, '2026-04-19 12:24:27'),
(12, 'SP2604191232507557', NULL, 1, '2026-04-19 12:32:00', '158.00', '0.00', '158.00', 'cash', NULL, '2026-04-19 12:32:50'),
(13, 'SP2604191329573115', 1, 0, '2026-04-19 13:29:00', '20.00', '0.00', '20.00', 'cash', NULL, '2026-04-19 13:29:57'),
(14, 'SP2604191331063422', 1, 0, '2026-04-19 13:30:00', '55.00', '0.00', '55.00', 'cash', NULL, '2026-04-19 13:31:06'),
(15, 'SP2604191404249481', 1, 0, '2026-04-19 14:03:00', '100.00', '0.00', '100.00', 'cash', NULL, '2026-04-19 14:04:24'),
(16, 'SP2604191513009680', 16, 0, '2026-04-19 15:12:00', '69.00', '0.00', '69.00', 'wechat', NULL, '2026-04-19 15:13:00'),
(17, 'SP2604191946197948', 16, 0, '2026-04-19 19:46:00', '655.00', '0.00', '655.00', 'cash', NULL, '2026-04-19 19:46:19'),
(18, 'SP2604191947083105', 16, 0, '2026-04-19 19:46:00', '545.00', '0.00', '545.00', 'cash', NULL, '2026-04-19 19:47:08'),
(19, 'SP2604191949007639', 16, 0, '2026-04-19 19:48:00', '352.00', '0.00', '352.00', 'cash', NULL, '2026-04-19 19:49:00'),
(20, 'SP2604191951385453', 16, 0, '2026-04-19 19:51:00', '135.00', '0.00', '135.00', 'cash', NULL, '2026-04-19 19:51:38'),
(21, 'SP2604191954172279', 16, 0, '2026-04-19 19:53:00', '365.00', '0.00', '365.00', 'cash', NULL, '2026-04-19 19:54:17'),
(22, 'SP2604191956100235', 16, 0, '2026-04-19 19:56:00', '356.00', '0.00', '356.00', 'cash', NULL, '2026-04-19 19:56:10'),
(23, 'SP2604191956207199', 16, 0, '2026-04-19 19:56:00', '300.00', '0.00', '300.00', 'cash', NULL, '2026-04-19 19:56:20'),
(24, 'SP2604191956330754', 16, 0, '2026-04-19 19:56:00', '300.00', '0.00', '300.00', 'balance', NULL, '2026-04-19 19:56:33'),
(25, 'SP2604192050063027', 16, 0, '2026-04-19 20:49:00', '356.00', '0.00', '356.00', 'cash', NULL, '2026-04-19 20:50:06'),
(26, 'SP2604192052445487', 1, 0, '2026-04-19 20:52:00', '0.00', '0.00', '0.00', 'other', NULL, '2026-04-19 20:52:44'),
(27, 'SP2604192107328861', 1, 0, '2026-04-19 21:07:00', '0.00', '0.00', '0.00', 'other', '扣次', '2026-04-19 21:07:32'),
(28, 'SP2604192133300689', NULL, 1, '2026-04-19 21:32:00', '250.00', '20.00', '230.00', 'cash', NULL, '2026-04-19 21:33:30'),
(29, 'SP2604192221522354', NULL, 1, '2026-04-19 22:21:00', '35.00', '0.00', '35.00', 'cash', NULL, '2026-04-19 22:21:52'),
(30, 'SP2604192222232127', 16, 0, '2026-04-19 22:22:00', '35.00', '0.00', '35.00', 'balance', NULL, '2026-04-19 22:22:23'),
(31, 'SP2604192223024896', 13, 0, '2026-04-19 22:22:00', '0.00', '0.00', '0.00', 'other', '扣次', '2026-04-19 22:23:02');

--
-- 转储表的索引
--

--
-- 表的索引 `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- 表的索引 `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `contracts`
--
ALTER TABLE `contracts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_contract_no` (`contract_no`),
  ADD KEY `idx_contract_name` (`contract_name`),
  ADD KEY `idx_customer_name` (`customer_name`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_type` (`type_id`),
  ADD KEY `idx_expiry_date` (`expiry_date`);

--
-- 表的索引 `contract_files`
--
ALTER TABLE `contract_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contract` (`contract_id`);

--
-- 表的索引 `contract_invoices`
--
ALTER TABLE `contract_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contract_invoice` (`contract_id`,`invoice_type`,`created_at`);

--
-- 表的索引 `contract_settings`
--
ALTER TABLE `contract_settings`
  ADD PRIMARY KEY (`id`);

--
-- 表的索引 `contract_transactions`
--
ALTER TABLE `contract_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_contract_tx` (`contract_id`,`tx_type`,`created_at`);

--
-- 表的索引 `contract_tx_undo_once`
--
ALTER TABLE `contract_tx_undo_once`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_contract_type` (`contract_id`,`tx_type`);

--
-- 表的索引 `contract_types`
--
ALTER TABLE `contract_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_name` (`name`);

--
-- 表的索引 `members`
--
ALTER TABLE `members`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_card` (`card_number`),
  ADD KEY `idx_phone` (`phone`),
  ADD KEY `idx_name` (`name`);

--
-- 表的索引 `member_wallet_records`
--
ALTER TABLE `member_wallet_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_wallet_order_no` (`order_no`),
  ADD KEY `idx_wallet_member` (`member_id`),
  ADD KEY `idx_wallet_created` (`created_at`);

--
-- 表的索引 `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_order_no` (`order_no`),
  ADD KEY `idx_member` (`member_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- 在导出的表使用AUTO_INCREMENT
--

--
-- 使用表AUTO_INCREMENT `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- 使用表AUTO_INCREMENT `contracts`
--
ALTER TABLE `contracts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- 使用表AUTO_INCREMENT `contract_files`
--
ALTER TABLE `contract_files`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- 使用表AUTO_INCREMENT `contract_invoices`
--
ALTER TABLE `contract_invoices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- 使用表AUTO_INCREMENT `contract_transactions`
--
ALTER TABLE `contract_transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- 使用表AUTO_INCREMENT `contract_tx_undo_once`
--
ALTER TABLE `contract_tx_undo_once`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- 使用表AUTO_INCREMENT `contract_types`
--
ALTER TABLE `contract_types`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- 使用表AUTO_INCREMENT `members`
--
ALTER TABLE `members`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- 使用表AUTO_INCREMENT `member_wallet_records`
--
ALTER TABLE `member_wallet_records`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- 使用表AUTO_INCREMENT `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- 限制导出的表
--

--
-- 限制表 `contract_files`
--
ALTER TABLE `contract_files`
  ADD CONSTRAINT `fk_contract_files_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE;

--
-- 限制表 `contract_invoices`
--
ALTER TABLE `contract_invoices`
  ADD CONSTRAINT `fk_contract_invoice_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE;

--
-- 限制表 `contract_transactions`
--
ALTER TABLE `contract_transactions`
  ADD CONSTRAINT `fk_contract_tx_contract` FOREIGN KEY (`contract_id`) REFERENCES `contracts` (`id`) ON DELETE CASCADE;

--
-- 限制表 `member_wallet_records`
--
ALTER TABLE `member_wallet_records`
  ADD CONSTRAINT `fk_wallet_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE CASCADE;

--
-- 限制表 `orders`
--
ALTER TABLE `orders`
  ADD CONSTRAINT `fk_orders_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

-- =====================================================================
-- Lead CRM — database schema + default data
-- Developed by Asha Webtech IT Solution Private Limited
-- Requires MariaDB 10.4+ / MySQL 8+ (utf8mb4, JSON CHECK constraint)
--
-- Import into an EMPTY database (phpMyAdmin -> Import, or:
--   mysql -u USER -p DATABASE < leadcrm.sql )
-- =====================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
/*!40101 SET NAMES utf8mb4 */;

-- --------------------------------------------------------

--
-- Table structure for table `leads`
--

CREATE TABLE `leads` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `public_id` varchar(24) NOT NULL,
  `website_id` int(10) UNSIGNED NOT NULL,
  `channel` enum('form','whatsapp','email') NOT NULL DEFAULT 'form',
  `name` varchar(190) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `status` enum('new','contacted','qualified','converted','lost') NOT NULL DEFAULT 'new',
  `idempotency_key` varchar(64) DEFAULT NULL,
  `fingerprint` char(64) NOT NULL,
  `duplicate_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `last_duplicate_at` datetime DEFAULT NULL,
  `source_url` varchar(2048) DEFAULT NULL,
  `page_title` varchar(255) DEFAULT NULL,
  `referrer` varchar(2048) DEFAULT NULL,
  `origin` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `follow_up_at` datetime DEFAULT NULL,
  `notified_at` datetime DEFAULT NULL,
  `notify_error` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `lead_notes`
--

CREATE TABLE `lead_notes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lead_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `kind` enum('note','status','follow_up') NOT NULL DEFAULT 'note',
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `bucket` char(64) NOT NULL,
  `window_start` int(10) UNSIGNED NOT NULL,
  `hits` int(10) UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `name` varchar(64) NOT NULL,
  `value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','agent') NOT NULL DEFAULT 'agent',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `websites`
--

CREATE TABLE `websites` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `domain` varchar(190) NOT NULL,
  `allowed_origins` text NOT NULL DEFAULT '',
  `allow_no_origin` tinyint(1) NOT NULL DEFAULT 1,
  `api_key` varchar(80) DEFAULT NULL,
  `api_key_prefix` varchar(20) NOT NULL,
  `api_key_hash` char(64) NOT NULL,
  `key_rotated_at` datetime DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `leads`
--
ALTER TABLE `leads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_leads_public_id` (`public_id`),
  ADD UNIQUE KEY `uq_leads_idempotency` (`website_id`,`idempotency_key`),
  ADD KEY `idx_leads_fingerprint` (`website_id`,`fingerprint`,`created_at`),
  ADD KEY `idx_leads_created` (`created_at`),
  ADD KEY `idx_leads_status` (`status`,`created_at`),
  ADD KEY `idx_leads_channel` (`channel`,`created_at`),
  ADD KEY `idx_leads_follow_up` (`follow_up_at`),
  ADD KEY `idx_leads_email` (`email`),
  ADD KEY `idx_leads_phone` (`phone`),
  ADD KEY `idx_leads_name` (`name`);

--
-- Indexes for table `lead_notes`
--
ALTER TABLE `lead_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notes_lead` (`lead_id`,`created_at`),
  ADD KEY `fk_notes_user` (`user_id`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`bucket`),
  ADD KEY `idx_rate_window` (`window_start`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`);

--
-- Indexes for table `websites`
--
ALTER TABLE `websites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_websites_key_hash` (`api_key_hash`),
  ADD KEY `idx_websites_active` (`is_active`),
  ADD KEY `idx_websites_domain` (`domain`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `leads`
--
ALTER TABLE `leads`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `lead_notes`
--
ALTER TABLE `lead_notes`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `websites`
--
ALTER TABLE `websites`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `leads`
--
ALTER TABLE `leads`
  ADD CONSTRAINT `fk_leads_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `lead_notes`
--
ALTER TABLE `lead_notes`
  ADD CONSTRAINT `fk_notes_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- --------------------------------------------------------
--
-- Default data (NO real data)
--
-- Default login:  username  admin   |  password  Admin@123
-- !! CHANGE THE PASSWORD IMMEDIATELY after first login (Account -> Change password). !!
--

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `role`, `is_active`) VALUES
(1, 'Administrator', 'admin', '$2y$10$dTujp.2qEdoIrsYU2ySjzeJKXQFzrEvuwIE3m7M5fk19.zxbtNRXm', 'admin', 1);

-- Email notifications are OFF until you configure SMTP in Settings.
-- (remember_secret is generated automatically by the app on first login.)
INSERT INTO `settings` (`name`, `value`) VALUES
('notify_enabled', '0'),
('notify_recipients', ''),
('smtp_host', ''),
('smtp_port', '465'),
('smtp_encryption', 'ssl'),
('smtp_username', ''),
('smtp_from_email', ''),
('smtp_from_name', 'Lead CRM');

COMMIT;

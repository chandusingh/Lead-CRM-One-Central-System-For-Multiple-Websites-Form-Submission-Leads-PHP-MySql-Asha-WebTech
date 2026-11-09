-- Asha WebTech Lead CRM - upgrade script (safe to run more than once, MariaDB 10.5+ / MySQL 8)
-- Run in phpMyAdmin on the live database. It never deletes or changes existing leads.

-- 1. Speeds up API-key lookups done by the Security Engine on every submission (it searched without an index).
ALTER TABLE `websites` ADD INDEX IF NOT EXISTS `idx_websites_api_key` (`api_key`);

-- 2. Security tables (only created when missing - same definitions the admin pages create automatically).
CREATE TABLE IF NOT EXISTS `security_log` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `website_id` int(10) UNSIGNED DEFAULT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `action` enum('blocked','quarantined','flagged') NOT NULL,
  `enforced` tinyint(1) NOT NULL DEFAULT 1,
  `rule` varchar(120) NOT NULL DEFAULT '',
  `reason` varchar(1000) NOT NULL DEFAULT '',
  `score` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `user_agent` varchar(512) DEFAULT NULL,
  `origin` varchar(255) DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `sample` varchar(400) DEFAULT NULL,
  `quarantine_id` bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_seclog_created` (`created_at`),
  KEY `idx_seclog_site` (`website_id`,`created_at`),
  KEY `idx_seclog_ip` (`ip`,`created_at`),
  KEY `idx_seclog_action` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `security_seen` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `website_id` int(10) UNSIGNED NOT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `content_hash` char(64) NOT NULL,
  `body_hash` char(64) DEFAULT NULL,
  `contact_hash` char(64) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_secseen_body` (`body_hash`,`created_at`),
  KEY `idx_secseen_contact` (`contact_hash`,`created_at`),
  KEY `idx_secseen_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Clean up expired rate-limit rows (older than 7 days). They are never read again.
DELETE FROM `rate_limits` WHERE `window_start` < UNIX_TIMESTAMP() - 7 * 86400;

-- 4. Default security settings (existing values are kept).
INSERT IGNORE INTO `settings` (`name`, `value`, `updated_at`) VALUES
  ('sec_engine', '1', UTC_TIMESTAMP()),
  ('sec_monitor', '0', UTC_TIMESTAMP()),
  ('sec_rate_limit', '1', UTC_TIMESTAMP()),
  ('sec_sqli', '1', UTC_TIMESTAMP()),
  ('sec_xss', '1', UTC_TIMESTAMP()),
  ('sec_duplicate', '1', UTC_TIMESTAMP()),
  ('sec_bot', '1', UTC_TIMESTAMP()),
  ('sec_quarantine', '1', UTC_TIMESTAMP());

-- Emergency only: if the SMS provider is down and you cannot sign in, switch OTP off:
-- UPDATE `settings` SET `value` = '0' WHERE `name` = 'otp_enabled';

-- Asha WebTech Lead CRM - Centralized Security Engine: database migration
-- Safe to run more than once (IF NOT EXISTS / INSERT IGNORE). Does not touch any existing table or row.
-- (The admin pages also create these tables automatically on first visit; running this by hand is optional.)

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

CREATE TABLE IF NOT EXISTS `security_quarantine` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_id` varchar(24) NOT NULL,
  `website_id` int(10) UNSIGNED NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `channel` enum('form','whatsapp','email') NOT NULL DEFAULT 'form',
  `rule` varchar(120) NOT NULL DEFAULT '',
  `reason` varchar(1000) NOT NULL DEFAULT '',
  `score` smallint(5) UNSIGNED NOT NULL DEFAULT 0,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(512) DEFAULT NULL,
  `origin` varchar(255) DEFAULT NULL,
  `source_url` varchar(2048) DEFAULT NULL,
  `page_title` varchar(255) DEFAULT NULL,
  `referrer` varchar(2048) DEFAULT NULL,
  `idempotency_key` varchar(64) DEFAULT NULL,
  `payload` longtext NOT NULL,
  `content_hash` char(64) NOT NULL,
  `hits` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `reviewed_by` int(10) UNSIGNED DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL,
  `lead_id` bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_secq_public` (`public_id`),
  KEY `idx_secq_status` (`status`,`created_at`),
  KEY `idx_secq_site` (`website_id`,`status`),
  KEY `idx_secq_hash` (`website_id`,`content_hash`,`status`),
  CONSTRAINT `fk_secq_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT `fk_secq_user` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
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

CREATE TABLE IF NOT EXISTS `security_site_rules` (
  `website_id` int(10) UNSIGNED NOT NULL,
  `config` longtext NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`website_id`),
  CONSTRAINT `fk_secrules_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default global configuration (engine ON, every module ON). Existing values are never overwritten.
INSERT IGNORE INTO `settings` (`name`, `value`, `updated_at`) VALUES
  ('sec_engine', '1', UTC_TIMESTAMP()),
  ('sec_monitor', '0', UTC_TIMESTAMP()),
  ('sec_rate_limit', '1', UTC_TIMESTAMP()),
  ('sec_sqli', '1', UTC_TIMESTAMP()),
  ('sec_xss', '1', UTC_TIMESTAMP()),
  ('sec_duplicate', '1', UTC_TIMESTAMP()),
  ('sec_bot', '1', UTC_TIMESTAMP()),
  ('sec_quarantine', '1', UTC_TIMESTAMP());

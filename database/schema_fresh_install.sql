-- Asha WebTech Lead CRM - full database structure (no data) for a fresh install.
-- After import, create the first admin user (see bottom of this file).

/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `lead_notes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint(20) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `kind` enum('note','status','follow_up') NOT NULL DEFAULT 'note',
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notes_lead` (`lead_id`,`created_at`),
  KEY `fk_notes_user` (`user_id`),
  CONSTRAINT `fk_notes_lead` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `leads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` varchar(24) NOT NULL,
  `website_id` int(10) unsigned NOT NULL,
  `channel` enum('form','whatsapp','email') NOT NULL DEFAULT 'form',
  `name` varchar(190) DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `status` enum('new','contacted','qualified','converted','lost') NOT NULL DEFAULT 'new',
  `idempotency_key` varchar(64) DEFAULT NULL,
  `fingerprint` char(64) NOT NULL,
  `duplicate_count` int(10) unsigned NOT NULL DEFAULT 0,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_leads_public_id` (`public_id`),
  UNIQUE KEY `uq_leads_idempotency` (`website_id`,`idempotency_key`),
  KEY `idx_leads_fingerprint` (`website_id`,`fingerprint`,`created_at`),
  KEY `idx_leads_created` (`created_at`),
  KEY `idx_leads_status` (`status`,`created_at`),
  KEY `idx_leads_channel` (`channel`,`created_at`),
  KEY `idx_leads_follow_up` (`follow_up_at`),
  KEY `idx_leads_email` (`email`),
  KEY `idx_leads_phone` (`phone`),
  KEY `idx_leads_name` (`name`),
  CONSTRAINT `fk_leads_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_limits` (
  `bucket` char(64) NOT NULL,
  `window_start` int(10) unsigned NOT NULL,
  `hits` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`bucket`),
  KEY `idx_rate_window` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL,
  `website_id` int(10) unsigned DEFAULT NULL,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `action` enum('blocked','quarantined','flagged') NOT NULL,
  `enforced` tinyint(1) NOT NULL DEFAULT 1,
  `rule` varchar(120) NOT NULL DEFAULT '',
  `reason` varchar(1000) NOT NULL DEFAULT '',
  `score` smallint(5) unsigned NOT NULL DEFAULT 0,
  `user_agent` varchar(512) DEFAULT NULL,
  `origin` varchar(255) DEFAULT NULL,
  `source_url` varchar(500) DEFAULT NULL,
  `sample` varchar(400) DEFAULT NULL,
  `quarantine_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_seclog_created` (`created_at`),
  KEY `idx_seclog_site` (`website_id`,`created_at`),
  KEY `idx_seclog_ip` (`ip`,`created_at`),
  KEY `idx_seclog_action` (`action`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_quarantine` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `public_id` varchar(24) NOT NULL,
  `website_id` int(10) unsigned NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `channel` enum('form','whatsapp','email') NOT NULL DEFAULT 'form',
  `rule` varchar(120) NOT NULL DEFAULT '',
  `reason` varchar(1000) NOT NULL DEFAULT '',
  `score` smallint(5) unsigned NOT NULL DEFAULT 0,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `user_agent` varchar(512) DEFAULT NULL,
  `origin` varchar(255) DEFAULT NULL,
  `source_url` varchar(2048) DEFAULT NULL,
  `page_title` varchar(255) DEFAULT NULL,
  `referrer` varchar(2048) DEFAULT NULL,
  `idempotency_key` varchar(64) DEFAULT NULL,
  `payload` longtext NOT NULL,
  `content_hash` char(64) NOT NULL,
  `hits` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `reviewed_by` int(10) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL,
  `lead_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_secq_public` (`public_id`),
  KEY `idx_secq_status` (`status`,`created_at`),
  KEY `idx_secq_site` (`website_id`,`status`),
  KEY `idx_secq_hash` (`website_id`,`content_hash`,`status`),
  KEY `fk_secq_user` (`reviewed_by`),
  CONSTRAINT `fk_secq_user` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_secq_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_seen` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `website_id` int(10) unsigned NOT NULL,
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
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_site_rules` (
  `website_id` int(10) unsigned NOT NULL,
  `config` longtext NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`website_id`),
  CONSTRAINT `fk_secrules_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `name` varchar(64) NOT NULL,
  `value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','agent') NOT NULL DEFAULT 'agent',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `websites` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_websites_key_hash` (`api_key_hash`),
  KEY `idx_websites_active` (`is_active`),
  KEY `idx_websites_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

/*M!999999\- enable the sandbox mode */ 
INSERT IGNORE INTO `settings` VALUES
('otp_endpoint','https://apitxt.com/api/sendOTP','2026-10-23 09:47:28'),
('sec_bot','1','2026-11-06 18:09:00'),
('sec_bot_threshold','60','2026-11-06 18:09:00'),
('sec_dup_body_sources','3','2026-11-06 18:09:00'),
('sec_dup_contact_max','5','2026-11-06 18:09:00'),
('sec_dup_window','86400','2026-11-06 18:09:00'),
('sec_duplicate','1','2026-11-06 18:09:00'),
('sec_engine','1','2026-11-06 18:09:00'),
('sec_ip_header','','2026-11-06 18:09:00'),
('sec_monitor','0','2026-11-06 18:09:00'),
('sec_quarantine','1','2026-11-06 18:09:00'),
('sec_rate_limit','1','2026-11-06 18:09:00'),
('sec_retention_days','60','2026-11-06 18:09:00'),
('sec_rl_max','10','2026-11-06 18:09:00'),
('sec_rl_window','600','2026-11-06 18:09:00'),
('sec_schema','1','2026-11-06 18:08:17'),
('sec_spam_keywords','','2026-11-06 18:09:00'),
('sec_sqli','1','2026-11-06 18:09:00'),
('sec_trusted_ips','','2026-11-06 18:09:00'),
('sec_xss','1','2026-11-06 18:09:00'),
('smtp_encryption','ssl','2026-10-23 11:20:33'),
('smtp_port','465','2026-10-23 11:20:33');

-- First admin (change the email, then set a real password from Admin > Change password).
-- Password below is: ChangeMe@12345
INSERT IGNORE INTO `users` (`name`, `email`, `password_hash`, `role`, `is_active`) VALUES ('Admin', 'admin@example.com', '$2y$10$n4CZr5P3M5viPBxNpPcdEeXsvQ99.E5IZNYh4jCgcDc2JZD02Cepm', 'admin', 1);

-- Index for API-key lookups
ALTER TABLE `websites` ADD INDEX IF NOT EXISTS `idx_websites_api_key` (`api_key`);

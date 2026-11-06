<?php
declare(strict_types=1);

/**
 * Security Engine - admin-side logic: schema installer, quarantine approve / reject, display helpers.
 * Loaded on demand by the admin/security*.php pages (the public API path never needs it).
 */

require_once __DIR__ . '/_security.php';

/** Creates the four security tables when missing (same statements as security_migration.sql). */
function lh_sec_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    // (information_schema works with native prepared statements; "SHOW TABLES LIKE ?" does not)
    $have = (int) db_val(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('security_log', 'security_quarantine', 'security_seen', 'security_site_rules')"
    );
    if ($have >= 4) {
        return;
    }
    $stmts = [];
    $stmts[] = <<<'SQL'
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
SQL;
    $stmts[] = <<<'SQL'
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
SQL;
    $stmts[] = <<<'SQL'
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
SQL;
    $stmts[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `security_site_rules` (
  `website_id` int(10) UNSIGNED NOT NULL,
  `config` longtext NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`website_id`),
  CONSTRAINT `fk_secrules_website` FOREIGN KEY (`website_id`) REFERENCES `websites` (`id`) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    foreach ($stmts as $sql) {
        db()->exec($sql);
    }
}

/** Best-effort name / email / phone from arbitrary form fields (same idea the lead list uses). */
function lh_sec_extract_contact(array $fields): array
{
    $name = '';
    $email = '';
    $phone = '';
    $first = '';
    $last = '';
    foreach ($fields as $k => $v) {
        if (!is_string($v) && !is_int($v)) {
            continue;
        }
        $v = trim((string) $v);
        $key = strtolower((string) $k);
        if ($v === '' || $key === '' || $key[0] === '_') {
            continue;
        }
        if ($email === '' && preg_match('/e-?mail/', $key) && filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $email = strtolower($v);
        } elseif ($phone === '' && preg_match('/(phone|mobile|^tel|whatsapp|contact_?(no|number))/', $key)) {
            $phone = $v;
        } elseif ($name === '' && preg_match('/^(name|full[_\- ]?name|your[_\- ]?name|contact[_\- ]?name|customer[_\- ]?name)$/', $key)) {
            $name = $v;
        } elseif ($first === '' && preg_match('/^first[_\- ]?name$/', $key)) {
            $first = $v;
        } elseif ($last === '' && preg_match('/^last[_\- ]?name$/', $key)) {
            $last = $v;
        }
    }
    if ($name === '' && ($first !== '' || $last !== '')) {
        $name = trim($first . ' ' . $last);
    }
    return [
        $name !== '' ? lh_sec_clip($name, 190) : null,
        $email !== '' ? lh_sec_clip($email, 190) : null,
        $phone !== '' ? lh_sec_clip($phone, 50) : null,
    ];
}

/** Same pending/approved/rejected badge styling used elsewhere (status-* colour classes). */
function lh_sec_status_badge(string $status): string
{
    $map = ['pending' => ['status-contacted', 'Pending'], 'approved' => ['status-converted', 'Approved'], 'rejected' => ['status-lost', 'Rejected']];
    $m = $map[$status] ?? ['badge-muted', ucfirst($status)];
    return '<span class="badge ' . $m[0] . '">' . e($m[1]) . '</span>';
}

function lh_sec_action_badge(string $action, bool $enforced = true): string
{
    $map = ['blocked' => ['status-lost', 'Blocked'], 'quarantined' => ['status-contacted', 'Quarantined'], 'flagged' => ['status-new', 'Flagged']];
    $m = $map[$action] ?? ['badge-muted', ucfirst($action)];
    $suffix = $enforced ? '' : ' <span class="muted">(monitor)</span>';
    return '<span class="badge ' . $m[0] . '">' . e($m[1]) . '</span>' . $suffix;
}

function lh_sec_rule_badges(string $rules): string
{
    $out = '';
    foreach (array_filter(explode(',', $rules)) as $r) {
        $out .= '<span class="badge badge-muted">' . e(LH_SEC_RULE_LABELS[$r] ?? $r) . '</span> ';
    }
    return $out;
}

/** Short human list of what a website overrides, e.g. ["Bot: OFF", "Rate 20 / 5 min"]. */
function lh_sec_override_summary(array $o): array
{
    $out = [];
    if (($o['engine'] ?? 'inherit') !== 'inherit') {
        $out[] = 'Engine ' . strtoupper((string) $o['engine']);
    }
    foreach (LH_SEC_MODULES as $m => $label) {
        $v = $o['modules'][$m] ?? 'inherit';
        if ($v === 'on' || $v === 'off') {
            $out[] = $label . ': ' . strtoupper($v);
        }
    }
    if (!empty($o['rl_max']) || !empty($o['rl_window'])) {
        $out[] = 'Custom rate limit';
    }
    if (!empty($o['bot_threshold'])) {
        $out[] = 'Bot sensitivity';
    }
    if (trim((string) ($o['extra_hosts'] ?? '')) !== '') {
        $out[] = 'Extra trusted domains';
    }
    return $out;
}

/**
 * Turn a quarantined submission into a real lead (status "new"), exactly like a normal submission would have
 * been saved, then mark it approved. The visitor was already shown the reference ID, so it is re-used.
 * @return array [bool ok, string message, string|null leadPublicId]
 */
function lh_sec_approve(int $qid, int $userId, string $note = ''): array
{
    $pdo = db();
    $leadPublicId = null;
    $leadDbId = 0;
    $fields = [];
    $siteName = '';
    $pdo->beginTransaction();
    try {
        $q = db_one('SELECT * FROM security_quarantine WHERE id = ? FOR UPDATE', [$qid]);
        if (!$q) {
            $pdo->rollBack();
            return [false, 'Quarantined submission not found.', null];
        }
        if ($q['status'] !== 'pending') {
            $pdo->rollBack();
            return [false, 'That submission was already ' . $q['status'] . '.', null];
        }
        $site = db_one('SELECT id, name FROM websites WHERE id = ?', [(int) $q['website_id']]);
        if (!$site) {
            $pdo->rollBack();
            return [false, 'The website for this submission no longer exists.', null];
        }
        $siteName = (string) $site['name'];
        $fields = json_decode((string) $q['payload'], true);
        $fields = is_array($fields) ? $fields : [];
        list($name, $email, $phone) = lh_sec_extract_contact($fields);

        $publicId = (string) $q['public_id'];
        if ((int) db_val('SELECT COUNT(*) FROM leads WHERE public_id = ?', [$publicId]) > 0) {
            $publicId = lh_sec_new_public_id();
        }
        $idem = $q['idempotency_key'] !== null && $q['idempotency_key'] !== '' ? (string) $q['idempotency_key'] : null;
        if ($idem !== null && (int) db_val('SELECT COUNT(*) FROM leads WHERE website_id = ? AND idempotency_key = ?', [(int) $q['website_id'], $idem]) > 0) {
            $idem = null;
        }
        $now = utc_now();
        db_exec(
            'INSERT INTO leads (public_id, website_id, channel, name, email, phone, payload, status, idempotency_key, fingerprint, source_url, page_title, referrer, origin, ip_address, user_agent, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'new\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $publicId, (int) $q['website_id'], (string) $q['channel'], $name, $email, $phone, lh_sec_json($fields), $idem,
                hash('sha256', (int) $q['website_id'] . '|' . (string) $q['content_hash']),
                $q['source_url'] !== null ? lh_sec_clip((string) $q['source_url'], 2048) : null,
                $q['page_title'] !== null ? lh_sec_clip((string) $q['page_title'], 255) : null,
                $q['referrer'] !== null ? lh_sec_clip((string) $q['referrer'], 2048) : null,
                $q['origin'] !== null ? lh_sec_clip((string) $q['origin'], 255) : null,
                $q['ip'] !== '' ? lh_sec_clip((string) $q['ip'], 45) : null,
                $q['user_agent'] !== null ? lh_sec_clip((string) $q['user_agent'], 512) : null,
                $now, $now,
            ]
        );
        $leadDbId = (int) $pdo->lastInsertId();
        $leadPublicId = $publicId;
        $body = 'Approved from Security Quarantine #' . $qid . ' (flagged: ' . lh_sec_clip((string) $q['reason'], 300) . '). Originally received ' . $q['created_at'] . ' UTC'
            . ((int) $q['hits'] > 1 ? ', submitted ' . (int) $q['hits'] . ' times' : '') . '.';
        if ($note !== '') {
            $body .= ' Note: ' . lh_sec_clip($note, 300);
        }
        db_exec('INSERT INTO lead_notes (lead_id, user_id, kind, body, created_at) VALUES (?, ?, \'note\', ?, ?)', [$leadDbId, $userId, $body, $now]);
        db_exec(
            "UPDATE security_quarantine SET status = 'approved', reviewed_by = ?, reviewed_at = ?, review_note = ?, lead_id = ? WHERE id = ?",
            [$userId, $now, $note !== '' ? lh_sec_clip($note, 500) : null, $leadDbId, $qid]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (function_exists('log_event')) {
            log_event('error', 'security.approve_failed', ['quarantine_id' => $qid, 'error' => $e->getMessage()]);
        }
        return [false, 'Could not approve this submission (database error). Nothing was changed.', null];
    }
    lh_sec_notify_approved($leadDbId, (string) $leadPublicId, $siteName, $fields);
    return [true, 'Approved - saved as lead ' . $leadPublicId . '.', $leadPublicId];
}

/** Same email notification a normal new lead triggers (uses the CRM's own SMTP settings). Never throws. */
function lh_sec_notify_approved(int $leadDbId, string $publicId, string $siteName, array $fields): void
{
    try {
        if (setting('notify_enabled') !== '1' || !function_exists('smtp_send') || !function_exists('smtp_config') || !function_exists('notify_recipients')) {
            return;
        }
        $to = notify_recipients();
        if (!$to) {
            return;
        }
        $rows = '';
        $text = '';
        foreach ($fields as $k => $v) {
            if (!is_scalar($v) || (string) $k === '' || ((string) $k)[0] === '_') {
                continue;
            }
            $rows .= '<tr><td style="padding:4px 12px 4px 0;color:#667085">' . e((string) $k) . '</td><td style="padding:4px 0">' . nl2br(e((string) $v)) . '</td></tr>';
            $text .= $k . ': ' . $v . "\n";
        }
        $subject = 'New lead (approved from quarantine) - ' . $siteName . ' - ' . $publicId;
        $html = '<div style="font-family:Arial,sans-serif"><p>A reviewed submission from <strong>' . e($siteName) . '</strong> was approved as lead <strong>' . e($publicId) . '</strong>.</p><table>' . $rows . '</table></div>';
        smtp_send(smtp_config(), $to, $subject, $html, "Approved lead $publicId from $siteName\n\n" . $text);
        db_exec('UPDATE leads SET notified_at = ?, notify_error = NULL WHERE id = ?', [utc_now(), $leadDbId]);
    } catch (Throwable $e) {
        try {
            db_exec('UPDATE leads SET notify_error = ? WHERE id = ?', [lh_sec_clip($e->getMessage(), 250), $leadDbId]);
        } catch (Throwable $e2) {
            // ignore
        }
    }
}

/** @return array [bool ok, string message] */
function lh_sec_reject(int $qid, int $userId, string $note = ''): array
{
    $q = db_one('SELECT status FROM security_quarantine WHERE id = ?', [$qid]);
    if (!$q) {
        return [false, 'Quarantined submission not found.'];
    }
    if ($q['status'] !== 'pending') {
        return [false, 'That submission was already ' . $q['status'] . '.'];
    }
    db_exec(
        "UPDATE security_quarantine SET status = 'rejected', reviewed_by = ?, reviewed_at = ?, review_note = ? WHERE id = ? AND status = 'pending'",
        [$userId, utc_now(), $note !== '' ? lh_sec_clip($note, 500) : null, $qid]
    );
    return [true, 'Rejected.'];
}

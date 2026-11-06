# Centralized Security Engine - install & operations

## Install (3 steps, no website changes)
1. Upload these files over the existing CRM (same folder structure):
   `_security.php`, `_security_admin.php`, `_bootstrap.php`, `_ui.php`, `.htaccess`, `api/v1/leads.php`,
   `admin/security.php`, `admin/security_site.php`, `admin/security_quarantine.php`, `admin/security_logs.php`, `admin/websites.php`
2. Tables are created automatically on the first API request or admin visit. (Optional: run `security_migration.sql` in phpMyAdmin instead; needs CREATE TABLE privilege either way.)
3. Admin > Security > Security Settings. Recommended first days: tick **Monitor only**, watch Security Logs, then untick.

Rollback: untick "Security Engine" (instant), or remove the single `lh_sec_guard();` line from `api/v1/leads.php`.

## Behaviour
- Blocked (nothing saved): injection payloads, scanner UAs, honeypot (silent fake success), rate abuse (429 + Retry-After).
- Quarantined (not a lead; visitor sees normal success): bot score >= threshold, spam wording, HTML link/markup, same message from 3+ sources, email/phone flood.
- Allowed: handed to the unchanged core. Exact re-submits are still merged by the core (`duplicate:true`).
- Fails open on any engine error. No CAPTCHA anywhere.
- New files/tables: security_log, security_quarantine, security_seen, security_site_rules; settings rows `sec_*`.

# Lead CRM – update notes

## How to install
1. Back up your current web folder and database.
2. Upload every file from this zip over the existing web folder (same structure). The private folder
   (`/home/u473825723/leadcrm-app`) is NOT touched – keep it as it is.
3. In phpMyAdmin run `database/upgrade.sql` once (safe to run again).
4. Hard-refresh the admin panel once (the new stylesheet is versioned automatically).

`database/schema_fresh_install.sql` is only for a brand-new, empty database (it creates every table
and one starter admin). Do not run it on your live database.

## Bugs fixed
- **Leads inbox lost filters after bulk actions** (`admin/leads.php`): operator-precedence bug in the
  hidden "return" URL (`?? '' ? … : …`) produced `leads.phpwebsite=17…`, so after "Set status" or
  "Delete selected" you were always thrown back to the unfiltered inbox. Fixed.
- **Single-row delete also dropped the filters** (`admin/leads_bulk.php`): now always returns to the
  filtered inbox. The return URL now also accepts `*` and `~` (e.g. a search for `web*`).
- **Fatal error on a website-filtered inbox** (`admin/leads.php`): forms that send numeric field
  names (`0`, `1`, `123`) turned into integer array keys and crashed `lx_label(string)` under
  `strict_types`. Fixed with explicit string casts.
- **Website-filtered inbox showed "—" for name/email/phone** of manually added leads: the dynamic
  columns now fall back to the lead's own Name / Email / Phone.
- **Login on PHP 8.4+** (`admin/login.php`): the timing-safe dummy hash used `random_bytes()`, which
  can contain NUL bytes – bcrypt rejects those on newer PHP and the login page would crash for an
  unknown email. Uses a hex string now.
- **PHP 8.5 deprecation** (`_bootstrap.php`): `curl_close()` is only called on PHP < 8.0.
- **Settings page null values** (`admin/settings.php`): settings that were never saved are now cast
  to strings before comparing/escaping.
- **Lead page follow-up input** (`admin/lead.php`): date is only formatted when a follow-up exists;
  "1 values" → "1 value".
- **Bulk selection highlight** (`assets/admin.js`): used an undefined CSS variable, so selected rows
  were never highlighted.
- **Copy buttons** (`assets/admin.js`): if the browser blocks the clipboard API the old fallback is
  now used instead of failing silently.
- **Websites table** (`admin/websites.php` styling): action buttons broke out of the table row.
- **Mobile filter panels** overflowed the screen on phones (leads, security logs).
- **Quarantine filters**: removed the inline grid style that disabled the responsive layout.

## Security hardening
- `.htaccess`: `_ui.php` is now blocked from direct access (like the other `_*.php` includes), and
  the `frontend/` build-source folder is no longer served.
- `database/upgrade.sql`: index on `websites.api_key` (every API submission looked up the key
  without an index) and cleanup of expired rate-limit rows.

## New design (all screens)
- Modern light theme: Plus Jakarta Sans, professional blue (#2563EB) with your logo's orange as accent,
  slate neutrals, soft shadows, rounded cards, coloured top strip on stat cards, blurred sticky header,
  active-menu indicator, gradient login cover, refined badges, tables, inputs, tabs and modal.
- Fully responsive: sidebar drawer on phones/tablets, collapsible rail on desktop, website tabs scroll
  sideways, tables turn into cards on phones, sticky action column on wide tables, bottom-sheet dialogs.
- Style source: `frontend/src/app.css` → compiled to `assets/app.css` (and identical `assets/admin.css`).
  To rebuild: `cd frontend && npm install && npm run build`. Node is NOT needed on the server.

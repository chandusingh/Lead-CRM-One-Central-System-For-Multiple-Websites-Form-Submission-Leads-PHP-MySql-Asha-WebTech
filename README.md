<div align="center">

# Lead CRM

**One central inbox for every enquiry from every website — forms, WhatsApp and email.**

Developed by **[Asha Webtech IT Solution Private Limited](https://ashawebtechitsolutions.com/)**

</div>

---

## What is this?

Lead CRM is a lightweight, self-hosted **lead management system** built with plain **PHP + MySQL/MariaDB** (no framework, no build step). It gives agencies and businesses a single admin panel to collect, track and follow up leads coming from **any number of websites**.

Each website gets its own **API key**. Drop one tiny script (or a single `fetch` call) into any site's contact form — built with plain HTML, PHP, WordPress, React or Next.js — and every submission lands in one inbox, instantly, with an email notification.

## Features

- **Universal lead API** – one endpoint (`POST /api/v1/leads`) that accepts *any* form fields; CORS-enabled so it works from any domain
- **Browser SDK** – dependency-free `lead-hub.js` with retries, timeouts, idempotency and success/error messages
- **Multi-website** – unlimited websites, each with its own API key (rotate / regenerate any time)
- **Multi-channel** – tag leads as *Form*, *WhatsApp* or *Email*
- **Smart protection** – duplicate detection, idempotency keys, rate limiting, CSRF protection, throttled login
- **Email notifications** – SMTP alerts to one or more recipients on every new lead, with a test-email button
- **Persistent login** – secure "remember me" cookie, session keep-alive
- **Modern responsive UI** – works on desktop, tablet and phone; automatic dark mode

## Modules

| Module | What it does |
|---|---|
| **Dashboard** | Totals, today / last-7-days counts, awaiting contact, follow-ups due, per-website stats, pipeline & channel breakdown, recent submissions, quick search |
| **Leads inbox** | Search & filter by website, channel, status, date range and follow-up; pagination; bulk select, bulk status change and bulk delete |
| **Lead detail** | Full submitted data, one-click call / WhatsApp / email, status pipeline (New → Contacted → Qualified → Converted / Lost), notes, follow-up reminders, activity timeline, technical info (source URL, referrer, IP, user agent) |
| **Add / edit lead** | Create leads manually or edit any field, including custom submitted fields |
| **Websites** | Add / edit / deactivate websites, view and rotate API keys |
| **API & setup** | Ready-made endpoint, cURL test, and copy-paste prompt for developers / AI assistants |
| **Email & SMTP** | SMTP configuration, notification recipients, test mail |
| **Account** | Change password |

## Tech stack

PHP 7.4+ (8.x recommended) · MySQL 8+ / MariaDB 10.4+ · Apache with `mod_rewrite` · vanilla JS & CSS

## Repository layout

```
.
├── public_html/          # ← upload to your web root (public)
│   ├── admin/            #   admin panel pages
│   ├── api/v1/           #   lead API endpoint
│   ├── assets/           #   CSS, JS, icons
│   ├── sdk/lead-hub.js   #   browser SDK
│   ├── _bootstrap.php
│   └── .htaccess
├── leadcrm-app/          # ← PRIVATE app folder (config.php, inc/, cron/, storage/)
│                         #   upload OUTSIDE the web root
├── database/
│   └── leadcrm.sql       # schema + default admin (no real data)
└── README.md
```

## How to install

1. **Create a database** (MySQL/MariaDB, `utf8mb4`) and a database user with full access to it.
2. **Import the schema:** import `database/leadcrm.sql` via phpMyAdmin (*Import*) or
   ```bash
   mysql -u DB_USER -p DB_NAME < database/leadcrm.sql
   ```
3. **Upload the private folder** `leadcrm-app/` to a location **outside** your public web root, e.g. `/home/USER/leadcrm-app`.
4. **Configure it:** edit `leadcrm-app/config.php` with your database host / name / user / password, app name and timezone.
5. **Upload the public files:** upload the contents of `public_html/` to your web root (or a sub-folder such as `public_html/crm/`).
6. **Point the web files at the private folder.** By default `_bootstrap.php` looks for a folder named `leadcrm-app` next to your web root. If yours lives elsewhere, set the path in `public_html/.htaccess`:
   ```apache
   SetEnv LEADCRM_ROOT /home/USER/leadcrm-app
   ```
7. **Make `leadcrm-app/storage/` writable** by PHP (e.g. `chmod 755` / `775`).
8. **Enable HTTPS** (the admin panel is forced to HTTPS by `.htaccess`).
9. **Open** `https://your-domain.com/admin/` and sign in:

   | | |
   |---|---|
   | **Username** | `admin` |
   | **Password** | `Admin@123` |

> ⚠️ **Change the default password immediately** (*Account → Change password*). These credentials are public in this repository — never leave them in production.

10. **Add your first website** (*Websites → Add*), then open *API & setup* to get its endpoint and key.
11. *(Optional)* Go to **Email & SMTP**, enter your SMTP details and recipients, then send a test mail.

## Connecting a website

**Option A – plain fetch**

```js
form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fields = Object.fromEntries(new FormData(form));
  const res = await fetch('https://your-crm.com/api/v1/leads', {
    method: 'POST',
    headers: { 'Content-Type': 'text/plain' },
    body: JSON.stringify({
      key: 'YOUR_API_KEY',
      fields,
      source_url: location.href,
      page_title: document.title
    })
  });
  const data = await res.json();      // { "ok": true, "lead_id": "LH-…" }
});
```

**Option B – cURL test**

```bash
curl -X POST 'https://your-crm.com/api/v1/leads' \
  -H 'Content-Type: application/json' \
  -d '{"key":"YOUR_API_KEY","fields":{"name":"Jane","email":"jane@example.com","message":"Hello"}}'
```

Optional body keys: `channel` (`form` / `whatsapp` / `email`) and `idempotency_key`.
The **Websites → API & setup** page generates all of this with your real endpoint and key already filled in.

## Security notes

- Change the default admin password on first login.
- Keep `leadcrm-app/` (it holds `config.php`) outside the web root and never commit `config.php`.
- Rotate a website's API key any time from its *API & setup* page.
- Use HTTPS only.

## Developed by

**Asha Webtech IT Solution Private Limited**

| | |
|---|---|
| **Website** | https://ashawebtechitsolutions.com/ |
| **Email** | info@ashawebtechitsolutions.com |
| **Phone** | +91 70243 15567 · +91 97551 21478 |
| **Address** | Office No. 69, Ratan Colony, New Jail Road, Huzur, Bhopal – 462038 |

**Company details**

| | |
|---|---|
| PAN | AAYCA4297J |
| TAN | BPLA15557D |
| CIN | U58202MP2023OPC065044 |
| GST | 23AAYCA4297J1Z0 |
| DUNS | 956560412 |
| Udyam Aadhaar | UDYAM-MP-10-0087694 |
| DIN | 09673942 |

---

<div align="center">© Asha Webtech IT Solution Private Limited. All rights reserved.</div>

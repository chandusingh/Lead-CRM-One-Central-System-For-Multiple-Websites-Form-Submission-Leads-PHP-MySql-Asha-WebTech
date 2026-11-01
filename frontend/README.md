# Lead CRM UI source (trimmed TailAdmin Free)

The admin panel UI is built on **TailAdmin Free** (MIT, see `LICENSE-tailadmin`) — Tailwind CSS v4.
Only the parts a CRM needs were kept; the rest of the template was removed.

## Kept (from the template)
- Design tokens: Outfit font, `brand` blue palette, `shadow-theme-*`, `title-*` sizes, breakpoints
- Sidebar (menu-item states), sticky header with search + user menu, sidebar collapse / mobile drawer
- Sign-in split layout (form + brand panel with grid shape)
- Cards, metric cards, tables, badges, buttons, inputs/selects/checkboxes, alerts, tabs, pagination, confirm modal

## Removed
All 100+ demo pages and their partials: other dashboards, e-commerce, calendar, email, chat, support,
tasks, profile, forms/tables/UI-element galleries, charts & maps libraries (ApexCharts, FullCalendar,
jsvectormap, Leaflet, MapLibre, Swiper, Dropzone, flatpickr, Prism), Alpine.js, i18n (4 languages),
dark mode, notification/theme toggles, sidebar promo widget, Webpack toolchain.

## Files
- `src/app.css`  – the single stylesheet source (tokens + component styles for the CRM's class names)
- `../_ui.php`   – sidebar / header markup (TailAdmin classes), injected around `page_header()` output
- `../assets/admin.js` – behaviour (drawer, collapse, user menu, confirm modal, bulk select)
- `../assets/app.css` – **compiled output, already built** — upload this; Node is NOT needed on the server

## Rebuild after changing styles or class names in PHP
```
cd frontend
npm install
npm run build     # writes ../assets/app.css
```
Tailwind scans `../admin/*.php`, `../_ui.php`, `../_bootstrap.php` and `../assets/admin.js` for classes.

## Change the brand colour
Edit the `--color-brand-*` values in `@theme` (top of `src/app.css`) and rebuild.

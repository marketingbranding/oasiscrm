# OASIS PWA 1.0 — Installable Android Foundation (Audit + Plan)

## Scope

Online-first PWA foundation. OASIS becomes installable from Android Chrome / Samsung Internet / Edge Android. No offline CRUD, no offline sync, no push notifications, no native wrapper, no cached authenticated pages, no cached financial/customer data.

## Current Architecture Audit

### Layouts and asset pipeline

- `resources/views/layouts/crm.blade.php`: authenticated CRM shell. Uses `@vite(['resources/css/app.css','resources/js/app.js'])`, inline `favicon.svg`, external Bunny Fonts (figtree). No PWA tags today.
- `resources/views/layouts/guest.blade.php`: login/guest shell, same Vite + favicon + font. No PWA tags.
- `resources/views/errors/operational-maintenance.blade.php`: standalone 224-line HTML 503 page (Full Maintenance Mode). No PWA tags; safe to add.
- `resources/views/layouts/app.blade.php` + `navigation.blade.php`: not PWA targets.
- `vite.config.js`: `laravel-vite-plugin` only; input `app.css` + `app.js`; no PWA plugin. Do not add one.
- `resources/js/app.js`: imports Alpine + module registers (`registerCrmModal`, etc.), `Alpine.start()`. New `registerPwa(Alpine)` will follow the same pattern.
- `public/`: `build/` (Vite hashed output), `js/login-voxel.js`, `favicon.svg`, `favicon.ico`, `.htaccess`, `index.php`, `robots.txt`. No manifest, service worker, or offline page exist.

### Security headers / CSP / session

- No CSP middleware and no security headers middleware exist (`bootstrap/app.php`); `public/.htaccess` has none either. PWA files are same-origin; adding a CSP is out of scope and would require a separate audit (external Bunny Fonts).
- Sessions: database driver; `http_only=true`, `same_site=lax`, `secure` via env. Login GET route `login`; logout is `POST logout` (network-authoritative). CSRF token rendered inline on layouts (`csrf-token` meta + hidden `_token`).

### Sensitive route surface (must never be cached by the service worker)

Auth/guest flows, all CRM HTML, presence/notification/comment JSON, Work Planner, Buku Saku, Database, Pengeluaran, Dana Talangan, Konsumen Progress, exports/imports, Google sync routes, uploads/downloads, maintenance administration, IAM/user administration. The service worker must never `cache.put()` any of these.

## Service Worker Risk Analysis

- A broad cache-first or stale-while-revalidate policy over CRM pages would replay one user's authenticated HTML to another user and replay stale data. Rejected.
- A cache that stores `response` regardless of status would persist 401/403/419/503. Rejected (only `response.ok` may be cached).
- Caching POST/upload/sync responses would break network authority and fake success. Rejected (non-GET is never intercepted).
- Caching login HTML would serve a stale login page offline and hide session/CSRF changes. Rejected (navigation responses are never stored).
- Third-party SW libraries (Workbox etc.) are not installed. Not added.

## Cache Allow / Deny Matrix

| Request type | Strategy | Stored? |
|---|---|---|
| `GET /build/*` (hashed Vite CSS/JS) | cache-first (`oasis-build-v1`) | YES, only `response.ok`, bounded FIFO |
| `GET /offline.html`, `/manifest.webmanifest`, icons, favicons | precached (`oasis-core-v1`) | YES (install time) |
| `GET` navigation (any CRM/auth/maintenance HTML) | network-first | NO (on failure serve `/offline.html`) |
| `GET` same-origin non-navigation (JSON, AJAX, downloads, exports, sync, presence, notifications) | network-first | NO |
| `GET` cross-origin (Bunny Fonts) | not intercepted | NO |
| `POST/PUT/PATCH/DELETE` (login/logout, CSRF-bearing forms, uploads, sync, bulk) | network-only (not intercepted) | NO |
| Maintenance 503 HTML/JSON | network (fetch succeeds) | NO |
| 401/403/419/503 responses | never cached | NO |

`/js/login-voxel.js` is served network-first (stable filename, not hashed) and not cached.

## Manifest Plan

`public/manifest.webmanifest`, served as `application/manifest+json` via `public/.htaccess` (`AddType`). Relative `start_url`/`scope` (`"./"`) so the app installs correctly at the deployment root or under a subpath without hardcoding a host. Values: `name` OASIS CRM, `short_name` OASIS, `display` standalone, `orientation` portrait-primary, `theme_color`/`background_color` `#000000`, `lang` id, `dir` ltr, `id` `"./"`, icons (192/512 any + 192/512 maskable).

## Icon Plan

No canonical PNG icon exists; derive from the verified `public/favicon.svg` mark (black square + OASIS yellow rotated square). Generate with PHP GD:
- `icon-192.png`, `icon-512.png` (purpose any; mark near-full bleed like the favicon).
- `icon-maskable-192.png`, `icon-maskable-512.png` (mark inside the 80% maskable safe zone).
- `apple-touch-icon.png` (180x180, any style).
Opaque black background; no transparency at the outer edge. Document source = `favicon.svg`.

## Offline Fallback Plan

`public/offline.html` — standalone static page, OASIS branding, no compiled JS, no external assets, Indonesian copy ("OASIS sedang offline" / "Koneksi internet tidak tersedia..."), retry button, mobile responsive. Never reveals user or CRM data.

## Install / Update Flow Plan

- `resources/js/pwa.js` registers `Alpine.data('oasisPwa', ...)`:
  - `beforeinstallprompt` captured (preventDefault) → `installable=true`; `install()` calls `prompt()`; `appinstalled` resets.
  - standalone detection via `matchMedia('(display-mode: standalone)')` / `navigator.standalone`.
  - service-worker `updatefound`/`statechange` → `updateAvailable=true` when a new worker is installed and the page is controlled.
  - `applyUpdate()` posts `{type:'SKIP_WAITING'}` to the waiting worker; page reloads only on `controllerchange` when the user initiated the update (guarded, no form-interrupting auto reload).
- `resources/js/app.js`: `import registerPwa from './pwa'; registerPwa(Alpine);` before `Alpine.start()`.
- Service worker registration only in secure contexts (or localhost), on `load`, non-blocking; dev-only console warning on failure.
- Shared `resources/views/components/pwa-control.blade.php` (`x-data="oasisPwa()"`) renders: an update banner ("Versi baru OASIS tersedia." / "Perbarui sekarang") and an install pill ("Pasang OASIS" + helper text + dismiss). Included in `layouts/crm` and `layouts/guest` before `</body>`. Hidden when standalone/installed/dismissed/unsupported; no native `confirm()`/`alert()`.

## Maintenance / Auth / Session Interaction

- Online maintenance 503 is a successful network fetch → returned as-is; never replaced by the offline page, never cached.
- Offline fallback appears only on genuine network failure for navigations.
- Login GET = navigation network-first (never stored); logout POST untouched; CSRF-bearing HTML never stored; session-expired redirects pass through untouched.

## Implementation Plan

1. `docs/PWA_1_INSTALLABLE_ANDROID.md` (this file).
2. Icons via PHP GD from `favicon.svg` mark; delete the generator script.
3. `public/manifest.webmanifest`; `public/.htaccess` `AddType application/manifest+json .webmanifest`.
4. `public/offline.html`.
5. `public/service-worker.js` (core + build caches, bounded, activate cleanup, SKIP_WAITING message, offline navigation fallback).
6. `resources/js/pwa.js` + register in `app.js`; `resources/views/components/pwa-control.blade.php`; include in `layouts/crm`, `layouts/guest`; PWA meta tags in `crm`, `guest`, and the maintenance view heads.
7. Idempotent changelog migration (category `added`).
8. Focused tests (`tests/Feature/PwaTest.php`): manifest file/JSON/icons, layout tags + `oasisPwa` source, service-worker source contracts (static-only caching, non-GET network-only, offline navigation fallback, `response.ok` guard, cache cleanup, update flow), offline.html copy.
9. Validation: `optimize:clear`, `route:list`, focused + full tests, `composer test`, `npm run build`, Pint, `view:cache`, `git diff --check`.

## Excluded (Not Implemented)

Offline CRUD, offline sync, push notifications, Play Store wrapper, Capacitor, TWA, native Android code, background sync, cached authenticated pages, cached financial/customer data, Workbox/third-party SW libraries, CSP changes, Play Store claims.

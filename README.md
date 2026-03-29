# Yojak Foundation (PHP + Filesystem JSON)

## Local development (pretty routes enabled)
From the project root, run:

```bash
php -S localhost:8080 public/router.php
```

Open: `http://localhost:8080`

### Quick URL checks
- Public: `/`, `/homepage`, `/schemes`, `/scheme/pm-surya-ghar`, `/pricing`, `/signup`, `/signup/pm-surya-ghar`, `/login`
- Admin: `/admin/login`, `/admin/dashboard`, `/admin/pending-signups`, `/admin/vendors`, `/admin/schemes`, `/admin/modules`, `/admin/plans`, `/admin/settings`
- Vendor: `/app`, `/app/dashboard`, `/app/profile`, `/app/subscription`
- PM Surya Ghar placeholders: `/app/pm-surya-ghar/leads` (and related module URLs)

## Seeded Credentials
- Superadmin: `admin@yojak.local` / `Admin@123`
- Vendor: `vendor@demo.local` / `Vendor@123`
- Pending signup sample: `pending@demo.local` / `Pending@123` (cannot login until verified)

## Deployment (Apache / shared hosting)

### Preferred (recommended)
Set the web server document root to:

- `<project>/public`

`public/.htaccess` will:
- serve real files and directories directly,
- route all other requests to the app front controller (`public/index.php`).

### Fallback (when document root cannot point to `/public`)
If hosting forces document root to project root:
- keep root `.htaccess` and root `index.php` in place,
- do **not** change route definitions,
- open URLs normally (without `/public`), e.g. `/schemes`, `/pricing`, `/login`.

The fallback only bridges hosting constraints and still routes through the canonical front controller at `public/index.php`.

## Structure
- `public/` front controller + assets + rewrite/router support
- `app/Core` and `app/Services` for reusable logic (storage, auth, access, provisioning)
- `app/Views` split by public/admin/vendor shells
- `data/platform` for platform registries
- `data/tenants` for tenant-isolated data and scheme folders

## Future extension points
- Add new schemes in `data/platform/schemes.json`
- Add/adjust modules in `data/platform/modules.json`
- Add/adjust plans in `data/platform/plans.json`
- Replace placeholders with real module controllers/pages later without changing platform core.

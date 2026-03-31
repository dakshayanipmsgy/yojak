# Yojak Foundation (PHP + Filesystem JSON)

## Local development (pretty routes enabled)
From the project root, run:

```bash
php -S localhost:8080 public/router.php
```

Open: `http://localhost:8080`

### Quick URL checks
- Public: `/`, `/homepage`, `/schemes`, `/scheme/pm-surya-ghar`, `/pricing`, `/signup`, `/signup/pm-surya-ghar`, `/login`
- Admin: `/admin/login`, `/admin/dashboard`, `/admin/pending-signups`, `/admin/vendors`, `/admin/vendors/view`, `/admin/vendors/manage-subscription`, `/admin/schemes`, `/admin/modules`, `/admin/plans`, `/admin/settings`
- Admin diagnostics: `/admin/system-health` (validate + safe repair for missing storage contracts)
- Vendor: `/app`, `/app/dashboard`, `/app/profile`, `/app/subscription`
- PM Surya Ghar modules: `/app/pm-surya-ghar/leads` (and related module URLs)

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

## Storage and snapshot notes
- Records are persisted in tenant scheme `records/*.json` with immutable-ish snapshots saved under `schemes/pm_surya_ghar/snapshots/*`.
- Document artifacts are persisted under `schemes/pm_surya_ghar/documents/*`.
- Use **Admin → System Health** to validate missing folders/files and run safe repairs without destructive resets.

## Future extension points
- Add new schemes in `data/platform/schemes.json`
- Add/adjust modules in `data/platform/modules.json`
- Add/adjust plans in `data/platform/plans.json`
- Continue extending scheme modules without changing platform core URL contracts.

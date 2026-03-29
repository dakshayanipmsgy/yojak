# Yojak Foundation (PHP + Filesystem JSON)

## Run
```bash
php -S localhost:8080 -t public
```
Open: `http://localhost:8080`

## Seeded Credentials
- Superadmin: `admin@yojak.local` / `Admin@123`
- Vendor: `vendor@demo.local` / `Vendor@123`
- Pending signup sample: `pending@demo.local` / `Pending@123` (cannot login until verified)

## Structure
- `public/` front controller + assets
- `app/Core` and `app/Services` for reusable logic (storage, auth, access, provisioning)
- `app/Views` split by public/admin/vendor shells
- `data/platform` for platform registries
- `data/tenants` for tenant-isolated data and scheme folders

## Future extension points
- Add new schemes in `data/platform/schemes.json`
- Add/adjust modules in `data/platform/modules.json`
- Add/adjust plans in `data/platform/plans.json`
- Replace placeholders with real module controllers/pages later without changing platform core.

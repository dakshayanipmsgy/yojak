# Yojak Storage Contract (Filesystem + JSON)

## Authoritative roots
- Platform registries/system/defaults: `data/platform/*`
- Tenant operational data: `data/tenants/tenant_{tenant_id}/*`

## JSON contracts
- Registry files use: `{ "items": [], "meta": {"version":1,"updated_at":""} }`
- Config/default files use: `{ "data": {}, "meta": {...} }`
- Record stores use: `{ "items": [], "meta": {"version":1,"updated_at":"","next_hint":null} }`
- Logs use: `{ "entries": [], "meta": {...} }`

## Key directories
- Platform:
  - `platform/registries/` (`schemes`, `modules`, `plans`, `vendors`, `pending_signups`, `subscriptions`, `superadmin_*`)
  - `platform/system/` (`counters`, `audit_log`, `bootstrap_meta`, `migrations`)
  - `platform/defaults/core` and `platform/defaults/schemes/pm_surya_ghar`
- Tenant:
  - `meta/`, `shared/`, `shared_uploads/`, `shared_exports/`, `shared_documents/`, `shared_snapshots/`
  - `schemes/{scheme_key}/config|records|indexes|documents|snapshots|uploads`

## Authoritative vs cache
- Authoritative: platform `subscriptions.json`, `vendors.json`, tenant scheme records.
- Cache/convenience: `tenant/meta/entitlement_cache.json`, `tenant/shared/subscription_snapshot.json`, `tenant/shared/enabled_*.json`.

## Services to use for all storage access
- `App\Core\JsonStorage`: safe JSON read/write + atomic write + metadata touch.
- `App\Services\RegistryService`: registry/system read/write + accessor methods.
- `App\Services\CounterService`: centralized prefixed IDs (`SCH-0001`, `VND-0001`, `TNT-0001`, ...).
- `App\Services\ProvisioningService`: idempotent tenant provisioning from approved signup.
- `App\Services\BootstrapService`: bootstrap, repair, and scheme structure enforcement.
- `App\Services\TenantStorageService`: tenant/scheme path and accessor helpers.

## Future module guideline
Never use ad hoc `file_get_contents`/`file_put_contents` in page handlers.
Always call service-layer APIs above to keep contracts and migrations consistent.

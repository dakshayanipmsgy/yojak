<h1>Vendor Detail</h1>
<div class="grid grid-2">
<div class="card">
<h3>Identity & Linkage</h3>
<p><strong>Vendor ID:</strong> <?= htmlspecialchars((string) ($vendor['vendor_id'] ?? '')) ?></p>
<p><strong>Tenant ID:</strong> <?= htmlspecialchars((string) ($vendor['tenant_id'] ?? '')) ?></p>
<p><strong>Signup Source:</strong> <?= htmlspecialchars((string) ($vendor['created_from_signup_id'] ?? '-')) ?></p>
<p><strong>Company:</strong> <?= htmlspecialchars((string) ($vendor['company_name'] ?? '')) ?></p>
<p><strong>Owner:</strong> <?= htmlspecialchars((string) ($vendor['owner_name'] ?? '')) ?></p>
<p><strong>Email:</strong> <?= htmlspecialchars((string) ($vendor['email'] ?? '')) ?></p>
<p><strong>Mobile:</strong> <?= htmlspecialchars((string) ($vendor['mobile'] ?? '')) ?></p>
<p><strong>City/State:</strong> <?= htmlspecialchars((string) (($vendor['city'] ?? '') . '/' . ($vendor['state'] ?? ''))) ?></p>
<p><strong>Created:</strong> <?= htmlspecialchars((string) ($vendor['created_at'] ?? '-')) ?></p>
<p><strong>Updated:</strong> <?= htmlspecialchars((string) ($vendor['updated_at'] ?? '-')) ?></p>
</div>
<div class="card">
<h3>Status & Subscription</h3>
<p><span class="<?= badgeClass((string) ($vendor['verification_status'] ?? '')) ?>"><?= htmlspecialchars((string) ($vendor['verification_status'] ?? '-')) ?></span> <span class="<?= badgeClass((string) ($vendor['account_status'] ?? '')) ?>"><?= htmlspecialchars((string) ($vendor['account_status'] ?? '-')) ?></span></p>
<p><strong>Subscription Status:</strong> <span class="<?= badgeClass((string) ($subscription['subscription_status'] ?? 'none')) ?>"><?= htmlspecialchars((string) ($subscription['subscription_status'] ?? 'none')) ?></span></p>
<p><strong>Current Plan:</strong> <?= htmlspecialchars((string) ($subscription['plan_key'] ?? ($vendor['current_plan_key'] ?? '-'))) ?></p>
<p><strong>Billing Cycle:</strong> <?= htmlspecialchars((string) ($subscription['billing_cycle'] ?? '-')) ?></p>
<p><strong>Renewal Date:</strong> <?= htmlspecialchars((string) ($subscription['renewal_date'] ?? '-')) ?></p>
<p><strong>Trial:</strong> <?= htmlspecialchars((string) (($subscription['trial_started_at'] ?? '-') . ' → ' . ($subscription['trial_ends_at'] ?? '-'))) ?></p>
<p><strong>Enabled Schemes:</strong> <?= htmlspecialchars(implode(', ', (array) ($vendor['enabled_schemes'] ?? []))) ?></p>
<p><strong>Default Scheme:</strong> <?= htmlspecialchars((string) ($vendor['default_scheme_key'] ?? '-')) ?></p>
<p><strong>Entitled Modules:</strong> <?= htmlspecialchars(implode(', ', (array) (($subscription['entitled_modules'] ?? $vendor['enabled_modules'] ?? [])))) ?></p>
<p><strong>Add-ons:</strong> <?= htmlspecialchars(implode(', ', (array) ($subscription['addon_module_keys'] ?? []))) ?></p>
</div>
</div>
<div class="card">
<h3>Tenant Storage</h3>
<p><strong>Tenant Path:</strong> <code><?= htmlspecialchars((string) ($tenantPath ?? '-')) ?></code></p>
<p><strong>Tenant Status:</strong> <?= htmlspecialchars((string) ($tenantMeta['tenant_status'] ?? 'unknown')) ?></p>
<p><strong>Provisioned At:</strong> <?= htmlspecialchars((string) ($tenantMeta['provisioned_at'] ?? '-')) ?></p>
<p><strong>Last Repaired At:</strong> <?= htmlspecialchars((string) ($tenantMeta['last_repaired_at'] ?? '-')) ?></p>
</div>
<div class="card">
<h3>Actions</h3>
<form method="post" action="/admin/vendors" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $csrfToken) ?>"><input type="hidden" name="vendor_id" value="<?= htmlspecialchars((string) ($vendor['vendor_id'] ?? '')) ?>"><button class="btn" name="action" value="activate">Activate</button><button class="btn secondary" name="action" value="suspend">Suspend</button><button class="btn secondary" name="action" value="cancel">Cancel</button><button class="btn" name="action" value="refresh_entitlements">Refresh Entitlements</button><button class="btn secondary" name="action" value="repair_storage">Repair Storage</button></form>
<a class="btn" href="/admin/vendors/manage-subscription?id=<?= urlencode((string) ($vendor['vendor_id'] ?? '')) ?>">Manage Subscription</a>
<a class="btn secondary" href="/admin/vendors">Back to Vendors</a>
</div>

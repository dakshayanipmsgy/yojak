<h1>Vendor Lifecycle Management</h1>
<form method="get" class="card inline-form">
    <input type="text" name="q" value="<?= htmlspecialchars((string) ($q ?? '')) ?>" placeholder="Search vendor/company/email/mobile">
    <select name="verification_status"><?php foreach(['all','verified','pending'] as $v): ?><option value="<?= $v ?>" <?= ($verificationFilter ?? 'all')===$v?'selected':'' ?>>Verification: <?= ucfirst($v) ?></option><?php endforeach; ?></select>
    <select name="account_status"><?php foreach(['all','active','suspended','cancelled'] as $v): ?><option value="<?= $v ?>" <?= ($accountFilter ?? 'all')===$v?'selected':'' ?>>Account: <?= ucfirst($v) ?></option><?php endforeach; ?></select>
    <select name="subscription_status"><?php foreach(['all','trial','active','expired','cancelled','none'] as $v): ?><option value="<?= $v ?>" <?= ($subscriptionFilter ?? 'all')===$v?'selected':'' ?>>Subscription: <?= ucfirst($v) ?></option><?php endforeach; ?></select>
    <select name="plan_key"><option value="all">All Plans</option><?php foreach($plans as $p): ?><option value="<?= htmlspecialchars((string) $p['plan_key']) ?>" <?= ($planFilter ?? 'all')===$p['plan_key']?'selected':'' ?>><?= htmlspecialchars((string) $p['plan_name']) ?></option><?php endforeach; ?></select>
    <select name="scheme_key"><option value="all">All Schemes</option><?php foreach($schemes as $s): ?><option value="<?= htmlspecialchars((string) $s['scheme_key']) ?>" <?= ($schemeFilter ?? 'all')===$s['scheme_key']?'selected':'' ?>><?= htmlspecialchars((string) $s['scheme_name']) ?></option><?php endforeach; ?></select>
    <select name="sort"><?php foreach(['newest'=>'Newest','company'=>'Company','status'=>'Status','renewal'=>'Renewal'] as $key=>$label): ?><option value="<?= $key ?>" <?= ($sortBy ?? 'newest')===$key?'selected':'' ?>>Sort: <?= $label ?></option><?php endforeach; ?></select>
    <button class="btn" type="submit">Apply</button>
</form>
<table>
<tr><th>Vendor/Tenant</th><th>Company</th><th>Owner</th><th>Contact</th><th>Status</th><th>Subscription</th><th>Plan/Billing</th><th>Renewal</th><th>Schemes</th><th>Actions</th></tr>
<?php foreach ($filteredVendors as $v): $sub = $subscriptionsByVendor[$v['vendor_id']] ?? null; ?>
<tr>
<td><?= htmlspecialchars((string) ($v['vendor_id'] ?? '')) ?><br><small><?= htmlspecialchars((string) ($v['tenant_id'] ?? '-')) ?></small></td>
<td><?= htmlspecialchars((string) ($v['company_name'] ?? '')) ?></td>
<td><?= htmlspecialchars((string) ($v['owner_name'] ?? '')) ?></td>
<td><?= htmlspecialchars((string) ($v['email'] ?? '')) ?><br><small><?= htmlspecialchars((string) ($v['mobile'] ?? '')) ?></small></td>
<td><span class="<?= badgeClass((string) ($v['verification_status'] ?? '')) ?>"><?= htmlspecialchars((string) ($v['verification_status'] ?? '-')) ?></span> <span class="<?= badgeClass((string) ($v['account_status'] ?? '')) ?>"><?= htmlspecialchars((string) ($v['account_status'] ?? '-')) ?></span></td>
<td><span class="<?= badgeClass((string) ($sub['subscription_status'] ?? 'none')) ?>"><?= htmlspecialchars((string) ($sub['subscription_status'] ?? 'none')) ?></span></td>
<td><?= htmlspecialchars((string) ($sub['plan_key'] ?? ($v['current_plan_key'] ?? '-'))) ?><br><small><?= htmlspecialchars((string) ($sub['billing_cycle'] ?? '-')) ?></small></td>
<td><?= htmlspecialchars((string) ($sub['renewal_date'] ?? '-')) ?></td>
<td><?= htmlspecialchars(implode(', ', (array) ($v['enabled_schemes'] ?? []))) ?></td>
<td>
<a class="btn" href="/admin/vendors/view?id=<?= urlencode((string) ($v['vendor_id'] ?? '')) ?>">View</a>
<form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>"><input type="hidden" name="vendor_id" value="<?= htmlspecialchars((string) ($v['vendor_id'] ?? '')) ?>"><button class="btn" name="action" value="activate">Activate</button><button class="btn secondary" name="action" value="suspend">Suspend</button><button class="btn secondary" name="action" value="cancel">Cancel</button></form>
<form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>"><input type="hidden" name="vendor_id" value="<?= htmlspecialchars((string) ($v['vendor_id'] ?? '')) ?>"><button class="btn" name="action" value="refresh_entitlements">Refresh Entitlements</button><button class="btn secondary" name="action" value="repair_storage">Repair Storage</button></form>
<a class="btn" href="/admin/subscriptions">Manage Subscription</a>
</td>
</tr>
<?php endforeach; ?>
</table>

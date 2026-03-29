<h1>Subscription Management</h1>
<table><tr><th>Vendor</th><th>Status</th><th>Plan</th><th>Cycle</th><th>Renewal</th><th>Manage</th></tr>
<?php $subByVendor=[]; foreach($subscriptions as $s){$subByVendor[$s['vendor_id']]=$s;} foreach($vendors as $v): $s=$subByVendor[$v['vendor_id']]??[]; ?>
<tr><td><?= htmlspecialchars($v['company_name']) ?> (<?= htmlspecialchars($v['vendor_id']) ?>)</td><td><?= htmlspecialchars((string)($s['subscription_status'] ?? 'none')) ?></td><td><?= htmlspecialchars((string)($s['plan_key'] ?? '-')) ?></td><td><?= htmlspecialchars((string)($s['billing_cycle'] ?? '-')) ?></td><td><?= htmlspecialchars((string)($s['renewal_date'] ?? '-')) ?></td><td>
<form method="post" class="card"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken) ?>"><input type="hidden" name="vendor_id" value="<?= htmlspecialchars($v['vendor_id']) ?>">
<select name="subscription_mode"><?php foreach(['plan','modules','hybrid'] as $mode): ?><option value="<?= $mode ?>" <?= ($s['subscription_mode'] ?? 'plan')===$mode?'selected':'' ?>><?= $mode ?></option><?php endforeach; ?></select>
<select name="plan_key"><option value="">(none)</option><?php foreach($plans as $p): ?><option value="<?= htmlspecialchars($p['plan_key']) ?>" <?= ($s['plan_key'] ?? '')===$p['plan_key']?'selected':'' ?>><?= htmlspecialchars($p['plan_name']) ?></option><?php endforeach; ?></select>
<select name="billing_cycle"><?php foreach(['monthly','quarterly','yearly'] as $cy): ?><option value="<?= $cy ?>" <?= ($s['billing_cycle'] ?? 'monthly')===$cy?'selected':'' ?>><?= $cy ?></option><?php endforeach; ?></select>
<select name="subscription_status"><?php foreach(['trial','active','expired','cancelled'] as $st): ?><option value="<?= $st ?>" <?= ($s['subscription_status'] ?? 'trial')===$st?'selected':'' ?>><?= $st ?></option><?php endforeach; ?></select>
<input name="trial_days_assigned" type="number" value="<?= (int)($s['trial_days_assigned'] ?? 14) ?>" placeholder="trial days">
<input name="direct_module_keys" value="<?= htmlspecialchars(implode(',', $s['direct_module_keys'] ?? [])) ?>" placeholder="direct modules csv">
<input name="addon_module_keys" value="<?= htmlspecialchars(implode(',', $s['addon_module_keys'] ?? [])) ?>" placeholder="addon modules csv">
<input name="removed_module_keys" value="<?= htmlspecialchars(implode(',', $s['removed_module_keys'] ?? [])) ?>" placeholder="remove modules csv">
<textarea name="override_pricing_json" placeholder='{"recurring_total_override": 2500}'><?= htmlspecialchars(json_encode($s['override_pricing_json'] ?? new stdClass(), JSON_PRETTY_PRINT)) ?></textarea>
<button class="btn">Apply</button></form></td></tr>
<?php endforeach; ?></table>

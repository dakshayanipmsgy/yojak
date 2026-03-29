<h1>Settings</h1>
<form method="post" class="card">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
<label>Platform Name<input name="platform_name" value="<?= htmlspecialchars((string) ($settings['platform_name'] ?? 'Yojak')) ?>"></label>
<label>Default Trial Plan<select name="default_trial_plan_key"><?php foreach(($plans ?? []) as $p): ?><option value="<?= htmlspecialchars($p['plan_key']) ?>" <?= ($settings['default_trial_plan_key'] ?? '')===$p['plan_key']?'selected':'' ?>><?= htmlspecialchars($p['plan_name']) ?></option><?php endforeach; ?></select></label>
<label>Default Billing Cycle<select name="default_billing_cycle"><?php foreach(['monthly','quarterly','yearly'] as $cy): ?><option value="<?= $cy ?>" <?= ($settings['default_billing_cycle'] ?? 'monthly')===$cy?'selected':'' ?>><?= $cy ?></option><?php endforeach; ?></select></label>
<label><input type="checkbox" name="allow_signup_globally" <?= !empty($settings['allow_signup_globally']) ? 'checked' : '' ?>> Allow signup globally</label>
<label><input type="checkbox" name="demo_mode" <?= !empty($settings['demo_mode']) ? 'checked' : '' ?>> Demo mode</label>
<button class="btn">Save</button>
</form>

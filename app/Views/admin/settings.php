<h1>Platform Settings</h1>
<form method="post" class="card compact-form">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
<label>Platform Name<input name="platform_name" value="<?= htmlspecialchars((string) ($settings['platform_name'] ?? 'Yojak')) ?>"></label>
<label>Public Footer Text<input name="public_footer_text" value="<?= htmlspecialchars((string) ($settings['public_footer_text'] ?? '')) ?>"></label>
<label>Default Trial Plan<select name="default_trial_plan_key"><?php foreach(($plans ?? []) as $p): ?><option value="<?= htmlspecialchars((string)$p['plan_key']) ?>" <?= ($settings['default_trial_plan_key'] ?? '')===$p['plan_key']?'selected':'' ?>><?= htmlspecialchars((string)$p['plan_name']) ?></option><?php endforeach; ?></select></label>
<label>Default Billing Cycle<select name="default_billing_cycle"><?php foreach(['monthly','quarterly','yearly'] as $cy): ?><option value="<?= $cy ?>" <?= ($settings['default_billing_cycle'] ?? 'monthly')===$cy?'selected':'' ?>><?= $cy ?></option><?php endforeach; ?></select></label>
<label><input type="checkbox" name="allow_signup_globally" <?= !empty($settings['allow_signup_globally']) ? 'checked' : '' ?>> Allow signup globally</label>
<label><input type="checkbox" name="maintenance_mode" <?= !empty($settings['maintenance_mode']) ? 'checked' : '' ?>> Maintenance mode</label>
<label><input type="checkbox" name="demo_mode" <?= !empty($settings['demo_mode']) ? 'checked' : '' ?>> Demo mode</label>
<button class="btn">Save</button>
</form>
<div class="card">
    <h3>Default Configuration Sources</h3>
    <?php foreach (($defaultsSummary ?? []) as $defaultFile): ?>
        <p><code><?= htmlspecialchars((string) $defaultFile) ?></code></p>
    <?php endforeach; ?>
</div>

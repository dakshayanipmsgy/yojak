<h1>Settings</h1>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
    <label>Platform Name<input name="platform_name" value="<?= htmlspecialchars((string) ($settings['platform_name'] ?? 'Yojak')) ?>"></label>
    <label><input type="checkbox" name="allow_signup_globally" <?= !empty($settings['allow_signup_globally']) ? 'checked' : '' ?>> Allow signup globally</label>
    <label><input type="checkbox" name="demo_mode" <?= !empty($settings['demo_mode']) ? 'checked' : '' ?>> Demo mode</label>
    <button class="btn">Save</button>
</form>

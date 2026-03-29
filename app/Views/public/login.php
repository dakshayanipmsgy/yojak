<h1><?= !empty($admin) ? 'Superadmin Login' : 'Vendor Login' ?></h1>
<?php if (!empty($error)): ?><p class="msg err"><?= htmlspecialchars((string) $error) ?></p><?php endif; ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
    <?php if (!empty($admin)): ?>
        <label>Email<input type="email" name="email" required></label>
    <?php else: ?>
        <label>Email or Mobile<input name="identifier" required></label>
    <?php endif; ?>
    <label>Password<input type="password" name="password" required></label>
    <button class="btn" type="submit">Login</button>
</form>

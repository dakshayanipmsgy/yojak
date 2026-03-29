<h1><?= !empty($admin)?'Superadmin Login':'Vendor Login' ?></h1><?php if(!empty($error)): ?><p class="msg err"><?= htmlspecialchars((string)$error) ?></p><?php endif; ?>
<form method="post" class="card"><label>Email<input type="email" name="email" required></label><label>Password<input type="password" name="password" required></label><button class="btn" type="submit">Login</button></form>

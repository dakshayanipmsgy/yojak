<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header>
    <div class="container topbar">
        <strong><?= htmlspecialchars((string) ((\App\Services\RegistryService::get('superadmin_settings')[0]['platform_name'] ?? 'Yojak'))) ?> Superadmin</strong>
        <div class="admin-meta">Logged in as <?= htmlspecialchars((string) ((\App\Services\AuthService::admin()['email'] ?? 'admin'))) ?></div>
    </div>
    <div class="container nav">
        <a href="/admin/dashboard">Dashboard</a>
        <a href="/admin/pending-signups">Pending Signups</a>
        <a href="/admin/vendors">Vendors</a>
        <a href="/admin/subscriptions">Subscriptions</a>
        <a href="/admin/schemes">Schemes</a>
        <a href="/admin/modules">Modules</a>
        <a href="/admin/plans">Plans</a>
        <a href="/admin/settings">Settings</a>
        <a href="/admin/system-health">System Health</a>
        <a href="/admin/logout">Logout</a>
    </div>
</header>
<main class="container">
    <?php if (!empty($_GET['ok'])): ?><p class="msg ok"><?= htmlspecialchars((string) $_GET['ok']) ?></p><?php endif; ?>
    <?php if (!empty($_GET['err'])): ?><p class="msg err"><?= htmlspecialchars((string) $_GET['err']) ?></p><?php endif; ?>
    <?php require $contentView; ?>
</main>
</body>
</html>

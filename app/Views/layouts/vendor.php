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
    <div class="container">
        <div class="topbar">
            <div>
                <strong><?= htmlspecialchars($vendor['company_name'] ?? 'Vendor') ?></strong>
                <span class="badge">Plan: <?= htmlspecialchars((string) ($vendor['current_plan_key'] ?? '-')) ?></span>
                <span class="badge">Status: <?= htmlspecialchars((string) ($vendor['subscription_status'] ?? '-')) ?></span>
                <?php if (($pageContext['context_type'] ?? '') === 'scheme'): ?>
                    <span class="badge ok">Scheme: <?= htmlspecialchars((string) ($pageContext['scheme_name'] ?? 'PM Surya Ghar')) ?></span>
                <?php else: ?>
                    <span class="badge">Platform Context</span>
                <?php endif; ?>
            </div>
            <nav class="nav">
                <a href="/app/dashboard">Dashboard</a>
                <a href="/app/profile">Profile</a>
                <a href="/app/subscription">Subscription</a>
                <a href="/logout">Logout</a>
            </nav>
        </div>
    </div>
</header>
<main class="container layout">
    <aside class="card sidebar">
        <div>
            <strong>Platform</strong>
            <div class="nav stacked-nav">
                <a href="/app/dashboard">Dashboard</a>
                <a href="/app/profile">Company Profile</a>
                <a href="/app/subscription">Subscription</a>
            </div>
        </div>

        <?php if (!empty($workspace) && !empty($navigation)): ?>
            <div style="margin-top:16px">
                <strong><?= htmlspecialchars((string) ($workspace['scheme']['scheme_name'] ?? 'Scheme Workspace')) ?></strong>
                <div class="small-muted"><?= htmlspecialchars((string) ($workspace['scheme']['description'] ?? '')) ?></div>
                <?php foreach ($navigation as $group): ?>
                    <div style="margin-top:12px">
                        <div class="small-muted"><strong><?= htmlspecialchars((string) ($group['group_label'] ?? 'Group')) ?></strong></div>
                        <div class="nav stacked-nav">
                            <?php foreach ((array) ($group['items'] ?? []) as $item): ?>
                                <a href="<?= htmlspecialchars((string) ($item['path'] ?? '#')) ?>"><?= htmlspecialchars((string) ($item['label'] ?? 'Module')) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </aside>
    <section>
        <?php if (!empty($pageContext['breadcrumbs'])): ?>
            <div class="card">
                <div class="small-muted">
                    <?php foreach ((array) $pageContext['breadcrumbs'] as $idx => $crumb): ?>
                        <?php if ($idx > 0): ?> &gt; <?php endif; ?>
                        <?php if (!empty($crumb['path'])): ?>
                            <a href="<?= htmlspecialchars((string) $crumb['path']) ?>"><?= htmlspecialchars((string) ($crumb['label'] ?? '')) ?></a>
                        <?php else: ?>
                            <?= htmlspecialchars((string) ($crumb['label'] ?? '')) ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php require $contentView; ?>
    </section>
</main>
</body>
</html>

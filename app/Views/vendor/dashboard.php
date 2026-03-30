<h1>Vendor Platform Dashboard</h1>
<div class="card">
    <p><strong>Company:</strong> <?= htmlspecialchars((string) ($vendor['company_name'] ?? '-')) ?></p>
    <p><strong>Current Plan:</strong> <?= htmlspecialchars((string) ($subscription['plan_key'] ?? $vendor['current_plan_key'] ?? '-')) ?></p>
    <p><strong>Subscription Status:</strong> <?= htmlspecialchars((string) ($subscription['subscription_status'] ?? $vendor['subscription_status'] ?? '-')) ?></p>
    <p><strong>Renewal:</strong> <?= htmlspecialchars((string) ($subscription['renewal_date'] ?? '-')) ?></p>
</div>

<div class="grid grid-4">
    <?php foreach ((array) ($cards ?? []) as $k => $v): ?>
        <div class="card"><h3><?= htmlspecialchars((string) $v) ?></h3><p><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $k))) ?></p></div>
    <?php endforeach; ?>
</div>

<div class="card">
    <h3>Enabled Scheme Workspaces</h3>
    <?php if (empty($schemeSummaries)): ?>
        <p>No enabled schemes found for this subscription.</p>
    <?php endif; ?>
    <?php foreach ((array) ($schemeSummaries ?? []) as $scheme): ?>
        <div class="card">
            <p><strong><?= htmlspecialchars((string) ($scheme['scheme_name'] ?? 'Scheme')) ?></strong></p>
            <p><?= htmlspecialchars((string) ($scheme['description'] ?? '')) ?></p>
            <p>Leads: <?= htmlspecialchars((string) ($scheme['summary']['leads_count'] ?? 0)) ?> · Customers: <?= htmlspecialchars((string) ($scheme['summary']['customers_count'] ?? 0)) ?> · Open complaints: <?= htmlspecialchars((string) ($scheme['summary']['open_complaints_count'] ?? 0)) ?></p>
            <a class="btn" href="<?= htmlspecialchars((string) ($scheme['dashboard_path'] ?? '/app/dashboard')) ?>">Open Scheme Dashboard</a>
        </div>
    <?php endforeach; ?>
</div>

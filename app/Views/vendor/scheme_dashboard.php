<h1><?= htmlspecialchars((string) ($workspace['scheme']['scheme_name'] ?? 'Scheme Dashboard')) ?> Dashboard</h1>
<div class="card">
    <p><?= htmlspecialchars((string) ($workspace['scheme']['description'] ?? 'Scheme workspace dashboard.')) ?></p>
    <p><strong>Workflow:</strong> Journey-driven progression for PM Surya Ghar vendor operations.</p>
    <p><strong>Subscription:</strong> <?= htmlspecialchars((string) ($subscription['subscription_status'] ?? $vendor['subscription_status'] ?? '-')) ?> · Plan <?= htmlspecialchars((string) ($subscription['plan_key'] ?? $vendor['current_plan_key'] ?? '-')) ?></p>
</div>

<div class="card">
    <h3>Workflow Overview</h3>
    <div class="grid grid-2">
        <?php foreach ((array) ($workspace['stages'] ?? []) as $stage): ?>
            <div class="card">
                <p><strong><?= htmlspecialchars((string) ($stage['order'] ?? '-')) ?>. <?= htmlspecialchars((string) ($stage['label'] ?? 'Stage')) ?></strong></p>
                <p><?= htmlspecialchars((string) ($stage['description'] ?? '')) ?></p>
                <p class="small-muted">Category: <?= htmlspecialchars((string) ($stage['category'] ?? 'journey')) ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="grid grid-4">
    <?php foreach ((array) ($workspace['dashboard_summary'] ?? []) as $key => $value): ?>
        <div class="card stat-card"><h3><?= htmlspecialchars((string) $value) ?></h3><p><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $key))) ?></p></div>
    <?php endforeach; ?>
</div>

<div class="card">
    <h3>Quick Actions</h3>
    <div class="nav">
        <?php foreach ((array) ($workspace['quick_actions'] ?? []) as $action): ?>
            <a class="btn" href="<?= htmlspecialchars((string) ($action['path'] ?? '#')) ?>"><?= htmlspecialchars((string) ($action['label'] ?? 'Action')) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h3>Module Access Summary</h3>
    <?php foreach ((array) ($navigation ?? []) as $group): ?>
        <p><strong><?= htmlspecialchars((string) ($group['group_label'] ?? 'Group')) ?>:</strong>
            <?= htmlspecialchars(implode(', ', array_map(fn($item) => (string) ($item['label'] ?? ''), (array) ($group['items'] ?? [])))) ?>
        </p>
    <?php endforeach; ?>
</div>

<div class="card">
    <h3>Configuration Awareness</h3>
    <?php foreach ((array) ($workspace['config_status'] ?? []) as $key => $ready): ?>
        <p><strong><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $key))) ?>:</strong> <?= $ready ? 'Yes' : 'No' ?></p>
    <?php endforeach; ?>
</div>

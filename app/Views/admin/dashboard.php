<h1>Platform Control Center</h1>
<div class="grid grid-4">
    <?php foreach($counts as $k=>$v): ?>
        <div class="card stat-card"><h3><?= htmlspecialchars((string)$v) ?></h3><p><?= htmlspecialchars(ucwords(str_replace('_',' ',$k))) ?></p></div>
    <?php endforeach; ?>
</div>
<div class="grid grid-2">
    <div class="card">
        <h3>Recent Pending Signups</h3>
        <?php foreach ($recentPending as $row): ?>
            <p><strong><?= htmlspecialchars((string) ($row['signup_id'] ?? '-')) ?></strong> · <?= htmlspecialchars((string) ($row['company_name'] ?? '-')) ?> · <span class="<?= badgeClass((string) ($row['verification_status'] ?? 'pending')) ?>"><?= htmlspecialchars((string) ($row['verification_status'] ?? 'pending')) ?></span></p>
        <?php endforeach; ?>
    </div>
    <div class="card">
        <h3>Recent Vendors</h3>
        <?php foreach ($recentVendors as $row): ?>
            <p><strong><?= htmlspecialchars((string) ($row['vendor_id'] ?? '-')) ?></strong> · <?= htmlspecialchars((string) ($row['company_name'] ?? '-')) ?> · <span class="<?= badgeClass((string) ($row['account_status'] ?? '-')) ?>"><?= htmlspecialchars((string) ($row['account_status'] ?? '-')) ?></span></p>
        <?php endforeach; ?>
    </div>
</div>
<div class="grid grid-2">
    <div class="card">
        <h3>Vendors by Plan</h3>
        <?php foreach ($vendorsByPlan as $key => $count): ?><p><?= htmlspecialchars((string) $key) ?>: <?= (int) $count ?></p><?php endforeach; ?>
    </div>
    <div class="card">
        <h3>Vendors by Scheme</h3>
        <?php foreach ($vendorsByScheme as $key => $count): ?><p><?= htmlspecialchars((string) $key) ?>: <?= (int) $count ?></p><?php endforeach; ?>
    </div>
</div>

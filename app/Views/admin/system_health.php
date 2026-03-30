<h1>System Health</h1>
<p class="muted">Storage diagnostics for platform contracts, tenant scheme records, and snapshot/document folder integrity.</p>

<div class="grid two">
    <section class="card">
        <h3>Platform</h3>
        <p>Missing: <strong><?= (int) ($platformSummary['missing_count'] ?? 0) ?></strong></p>
        <p>Invalid JSON: <strong><?= (int) ($platformSummary['invalid_count'] ?? 0) ?></strong></p>
    </section>
    <section class="card">
        <h3>Tenants</h3>
        <p>Missing: <strong><?= (int) ($tenantSummary['missing_count'] ?? 0) ?></strong></p>
        <p>Invalid JSON: <strong><?= (int) ($tenantSummary['invalid_count'] ?? 0) ?></strong></p>
    </section>
</div>

<form method="post" class="card compact-form">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
    <input type="hidden" name="action" value="repair">
    <button class="btn" type="submit">Run Safe Repair</button>
    <p class="muted">Repair only creates missing folders/files and rewrites invalid JSON with known-safe defaults.</p>
</form>

<div class="card">
    <h3>Recent Failing Checks</h3>
    <?php
    $failed = array_values(array_filter(array_merge($platformSummary['checks'] ?? [], $tenantSummary['checks'] ?? []), static fn(array $row): bool => ($row['status'] ?? 'ok') !== 'ok'));
    ?>
    <?php if ($failed === []): ?>
        <p class="msg ok">All required paths and JSON contracts look healthy.</p>
    <?php else: ?>
        <table>
            <thead><tr><th>Type</th><th>Status</th><th>Path</th></tr></thead>
            <tbody>
                <?php foreach (array_slice($failed, 0, 80) as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) ($row['type'] ?? 'n/a')) ?></td>
                        <td><span class="badge <?= ($row['status'] ?? '') === 'missing' ? 'warn' : 'bad' ?>"><?= htmlspecialchars((string) ($row['status'] ?? 'unknown')) ?></span></td>
                        <td><code><?= htmlspecialchars((string) ($row['path'] ?? '')) ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

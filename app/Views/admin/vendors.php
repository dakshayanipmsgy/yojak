<h1>Vendors</h1>
<table>
    <tr><th>Company</th><th>Owner</th><th>Email</th><th>Verification/Account</th><th>Subscription</th><th>Plan</th><th>Actions</th></tr>
    <?php foreach ($vendors as $v): ?>
        <?php $sub = $subscriptionsByVendor[$v['vendor_id']] ?? null; ?>
        <tr>
            <td><?= htmlspecialchars((string) ($v['company_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($v['owner_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($v['email'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) (($v['verification_status'] ?? '') . '/' . ($v['account_status'] ?? ''))) ?></td>
            <td><?= htmlspecialchars((string) ($sub['subscription_status'] ?? 'none')) ?></td>
            <td><?= htmlspecialchars((string) ($sub['plan_key'] ?? ($v['current_plan_key'] ?? '-'))) ?></td>
            <td>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
                    <input type="hidden" name="vendor_id" value="<?= htmlspecialchars((string) ($v['vendor_id'] ?? '')) ?>">
                    <button class="btn" name="action" value="activate">Activate</button>
                </form>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
                    <input type="hidden" name="vendor_id" value="<?= htmlspecialchars((string) ($v['vendor_id'] ?? '')) ?>">
                    <button class="btn secondary" name="action" value="suspend">Suspend</button>
                </form>
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
                    <input type="hidden" name="vendor_id" value="<?= htmlspecialchars((string) ($v['vendor_id'] ?? '')) ?>">
                    <button class="btn secondary" name="action" value="cancel">Cancel</button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

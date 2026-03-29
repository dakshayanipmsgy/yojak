<h1>Pending Signups</h1>
<table>
    <tr>
        <th>Signup ID</th><th>Owner</th><th>Company</th><th>Mobile</th><th>Email</th><th>City/State</th><th>Scheme</th><th>Submitted</th><th>Status</th><th>Actions</th>
    </tr>
    <?php foreach ($pending as $p): ?>
        <?php $status = (string) ($p['verification_status'] ?? 'pending'); ?>
        <tr>
            <td><?= htmlspecialchars((string) ($p['signup_id'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($p['owner_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($p['company_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($p['mobile'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($p['email'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) (($p['city'] ?? '') . '/' . ($p['state'] ?? ''))) ?></td>
            <td><?= htmlspecialchars((string) ($p['requested_scheme_key'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($p['submitted_at'] ?? '-')) ?></td>
            <td><span class="badge"><?= htmlspecialchars($status) ?></span></td>
            <td>
                <?php if ($status === 'pending'): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
                        <input type="hidden" name="signup_id" value="<?= htmlspecialchars((string) ($p['signup_id'] ?? '')) ?>">
                        <button class="btn" name="action" value="verify">Verify</button>
                    </form>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
                        <input type="hidden" name="signup_id" value="<?= htmlspecialchars((string) ($p['signup_id'] ?? '')) ?>">
                        <button class="btn secondary" name="action" value="reject">Reject</button>
                    </form>
                <?php else: ?>
                    <small>Processed by <?= htmlspecialchars((string) ($p['processed_by'] ?? '-')) ?></small>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <td colspan="10"><small>Address: <?= htmlspecialchars((string) ($p['address'] ?? '-')) ?> | Business: <?= htmlspecialchars((string) ($p['business_details'] ?? '-')) ?> | GST: <?= htmlspecialchars((string) ($p['gst_number'] ?? '-')) ?> | Website: <?= htmlspecialchars((string) ($p['website'] ?? '-')) ?> | Note: <?= htmlspecialchars((string) ($p['notes'] ?? ($p['process_note'] ?? '-'))) ?></small></td>
        </tr>
    <?php endforeach; ?>
</table>

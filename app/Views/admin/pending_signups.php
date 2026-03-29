<h1>Pending Signup Management</h1>
<form method="get" class="card inline-form">
    <input type="text" name="q" placeholder="Search company/email/mobile" value="<?= htmlspecialchars((string) ($search ?? '')) ?>">
    <select name="status">
        <?php foreach (['all','pending','verified','rejected'] as $st): ?>
            <option value="<?= $st ?>" <?= ($statusFilter ?? 'all')===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
        <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filter</button>
</form>
<table>
    <tr><th>Signup ID</th><th>Scheme</th><th>Owner</th><th>Company</th><th>Mobile</th><th>Email</th><th>City/State</th><th>Submitted</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($filteredPending as $p): $status=(string)($p['verification_status'] ?? 'pending'); ?>
        <tr>
            <td><?= htmlspecialchars((string) ($p['signup_id'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($p['requested_scheme_key'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($p['owner_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($p['company_name'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($p['mobile'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) ($p['email'] ?? '')) ?></td>
            <td><?= htmlspecialchars((string) (($p['city'] ?? '') . '/' . ($p['state'] ?? ''))) ?></td>
            <td><?= htmlspecialchars((string) ($p['submitted_at'] ?? '-')) ?></td>
            <td><span class="<?= badgeClass($status) ?>"><?= htmlspecialchars($status) ?></span></td>
            <td>
                <a class="btn" href="/admin/pending-signups/view?id=<?= urlencode((string) ($p['signup_id'] ?? '')) ?>">View</a>
                <?php if ($status === 'pending'): ?>
                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>"><input type="hidden" name="signup_id" value="<?= htmlspecialchars((string) ($p['signup_id'] ?? '')) ?>"><button class="btn" name="action" value="verify">Verify</button></form>
                <form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>"><input type="hidden" name="signup_id" value="<?= htmlspecialchars((string) ($p['signup_id'] ?? '')) ?>"><input type="hidden" name="process_note" value="Rejected from list"><button class="btn secondary" name="action" value="reject">Reject</button></form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

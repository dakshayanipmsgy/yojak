<h1>Pending Signup Detail</h1>
<div class="card">
<p><strong>Signup ID:</strong> <?= htmlspecialchars((string) ($signup['signup_id'] ?? '')) ?></p>
<p><strong>Status:</strong> <span class="<?= badgeClass((string) ($signup['verification_status'] ?? 'pending')) ?>"><?= htmlspecialchars((string) ($signup['verification_status'] ?? 'pending')) ?></span></p>
<p><strong>Requested Scheme:</strong> <?= htmlspecialchars((string) ($signup['requested_scheme_key'] ?? '')) ?></p>
<p><strong>Submitted:</strong> <?= htmlspecialchars((string) ($signup['submitted_at'] ?? '-')) ?></p>
<p><strong>Owner:</strong> <?= htmlspecialchars((string) ($signup['owner_name'] ?? '')) ?></p>
<p><strong>Company:</strong> <?= htmlspecialchars((string) ($signup['company_name'] ?? '')) ?></p>
<p><strong>Email:</strong> <?= htmlspecialchars((string) ($signup['email'] ?? '')) ?></p>
<p><strong>Mobile:</strong> <?= htmlspecialchars((string) ($signup['mobile'] ?? '')) ?></p>
<p><strong>City/State:</strong> <?= htmlspecialchars((string) (($signup['city'] ?? '') . '/' . ($signup['state'] ?? ''))) ?></p>
<p><strong>Address:</strong> <?= htmlspecialchars((string) ($signup['address'] ?? '-')) ?></p>
<p><strong>Business Details:</strong> <?= htmlspecialchars((string) ($signup['business_details'] ?? '-')) ?></p>
<p><strong>GST:</strong> <?= htmlspecialchars((string) ($signup['gst_number'] ?? '-')) ?></p>
<p><strong>Website:</strong> <?= htmlspecialchars((string) ($signup['website'] ?? '-')) ?></p>
<p><strong>Notes:</strong> <?= htmlspecialchars((string) ($signup['notes'] ?? '-')) ?></p>
<p><strong>Processed At:</strong> <?= htmlspecialchars((string) ($signup['processed_at'] ?? '-')) ?> by <?= htmlspecialchars((string) ($signup['processed_by'] ?? '-')) ?></p>
<p><strong>Process Note:</strong> <?= htmlspecialchars((string) ($signup['process_note'] ?? '-')) ?></p>
</div>
<?php if (($signup['verification_status'] ?? 'pending') === 'pending'): ?>
<div class="card">
<h3>Verify Signup</h3>
<form method="post" class="compact-form" action="/admin/pending-signups">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
<input type="hidden" name="signup_id" value="<?= htmlspecialchars((string) ($signup['signup_id'] ?? '')) ?>">
<input type="hidden" name="action" value="verify">
<label>Plan<select name="plan_key"><?php foreach(($plans ?? []) as $p): ?><option value="<?= htmlspecialchars((string) $p['plan_key']) ?>" <?= (($settings['default_trial_plan_key'] ?? '')===$p['plan_key'])?'selected':'' ?>><?= htmlspecialchars((string) $p['plan_name']) ?></option><?php endforeach; ?></select></label>
<label>Billing Cycle<select name="billing_cycle"><?php foreach(['monthly','quarterly','yearly'] as $c): ?><option value="<?= $c ?>" <?= (($settings['default_billing_cycle'] ?? 'monthly')===$c)?'selected':'' ?>><?= ucfirst($c) ?></option><?php endforeach; ?></select></label>
<label>Trial Days<input type="number" name="trial_days" value="14"></label>
<label>Enabled Schemes (csv)<input name="enabled_scheme_keys" value="<?= htmlspecialchars((string) ($signup['requested_scheme_key'] ?? 'pm_surya_ghar')) ?>"></label>
<button class="btn">Verify and Provision</button>
</form>
</div>
<div class="card">
<h3>Reject Signup</h3>
<form method="post" class="compact-form" action="/admin/pending-signups">
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
<input type="hidden" name="signup_id" value="<?= htmlspecialchars((string) ($signup['signup_id'] ?? '')) ?>">
<input type="hidden" name="action" value="reject">
<label>Rejection Note<textarea name="process_note" placeholder="Optional rejection note"></textarea></label>
<button class="btn secondary">Reject</button>
</form>
</div>
<?php endif; ?>

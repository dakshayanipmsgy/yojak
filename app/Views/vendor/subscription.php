<h1>Subscription & Billing</h1>
<?php $sub=$subscription ?? []; ?>
<div class="card">
<p><strong>Current plan:</strong> <?= htmlspecialchars((string)($sub['plan_snapshot']['plan_name'] ?? $sub['plan_key'] ?? '-')) ?></p>
<p><strong>Mode:</strong> <?= htmlspecialchars((string)($sub['subscription_mode'] ?? '-')) ?></p>
<p><strong>Billing cycle:</strong> <?= htmlspecialchars((string)($sub['billing_cycle'] ?? '-')) ?></p>
<p><strong>Status:</strong> <?= htmlspecialchars((string)($sub['subscription_status'] ?? '-')) ?></p>
<p><strong>Trial:</strong> <?= htmlspecialchars((string)($sub['trial_started_at'] ?? '-')) ?> to <?= htmlspecialchars((string)($sub['trial_ends_at'] ?? '-')) ?></p>
<p><strong>Active:</strong> <?= htmlspecialchars((string)($sub['active_from'] ?? '-')) ?> to <?= htmlspecialchars((string)($sub['active_until'] ?? '-')) ?></p>
<p><strong>Renewal date:</strong> <?= htmlspecialchars((string)($sub['renewal_date'] ?? '-')) ?></p>
<p><strong>Recurring total:</strong> ₹<?= number_format((float)($sub['price_breakdown']['recurring_total'] ?? 0),2) ?></p>
<p><strong>Core features included:</strong> dashboard, company branding, subscription billing, scheme shell.</p>
<p><strong>Entitled modules:</strong> <?= htmlspecialchars(implode(', ', (array)($sub['entitled_modules'] ?? []))) ?></p>
<p>Need upgrade/downgrade? Contact admin.</p>
</div>

<div class="card"><h1><?= htmlspecialchars((string) ($scheme['public_title'] ?? 'Scheme')) ?></h1><p><?= htmlspecialchars((string) ($scheme['description'] ?? '')) ?></p>
<p><span class="badge">Signup <?= !empty($scheme['signup_enabled']) ? 'Enabled' : 'Disabled' ?></span></p>
<?php if (!empty($scheme['signup_enabled'])): ?><a class="btn" href="/signup/<?= htmlspecialchars((string) ($slug ?? str_replace('_', '-', (string) ($scheme['scheme_key'] ?? '')))) ?>">Sign up</a><?php else: ?><span class="msg err">Signup currently unavailable.</span><?php endif; ?>
<a class="btn secondary" href="/login">Vendor Login</a></div>
<?php if (!empty($schemePlans)): ?><div class="card"><h3>Active Plans</h3><?php foreach ($schemePlans as $plan): ?><p><strong><?= htmlspecialchars((string) $plan['plan_name']) ?></strong> · ₹<?= (float) $plan['monthly_price'] ?>/mo · trial <?= (int) $plan['trial_days'] ?>d</p><?php endforeach; ?></div><?php endif; ?>

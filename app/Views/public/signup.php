<h1>PM Surya Ghar Vendor Signup</h1>
<?php if (!empty($error)): ?><p class="msg err"><?= htmlspecialchars((string) $error) ?></p><?php endif; ?>
<?php if (!empty($success)): ?><p class="msg ok"><?= htmlspecialchars((string) $success) ?></p><?php endif; ?>
<?php
$signupAllowed = !empty($settings['allow_signup_globally']) && !empty($scheme['active_flag']) && !empty($scheme['public_visible']) && !empty($scheme['signup_enabled']);
?>
<?php if (!$signupAllowed): ?>
    <p class="msg err">Signup is currently unavailable for this scheme.</p>
<?php else: ?>
<form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($csrfToken ?? '')) ?>">
    <label>Owner Name*<input name="owner_name" required></label>
    <label>Company Name*<input name="company_name" required></label>
    <label>Mobile*<input name="mobile" required></label>
    <label>Email*<input type="email" name="email" required></label>
    <label>City*<input name="city" required></label>
    <label>State*<input name="state" required></label>
    <label>Password*<input type="password" name="password" minlength="8" required></label>
    <label>Address<input name="address"></label>
    <label>Business Details<textarea name="business_details"></textarea></label>
    <label>GST Number<input name="gst_number"></label>
    <label>Website<input name="website"></label>
    <label>Notes<textarea name="notes"></textarea></label>
    <button class="btn" type="submit">Submit Signup</button>
</form>
<?php endif; ?>

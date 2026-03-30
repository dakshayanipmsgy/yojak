<?php
$profile = (array) ($profile ?? []);
$branding = (array) ($branding ?? []);
?>
<h1>Company Profile</h1>
<div class="card">
    <p><strong>Company:</strong> <?= htmlspecialchars((string) ($profile['company_name'] ?? $vendor['company_name'])) ?></p>
    <p><strong>Owner:</strong> <?= htmlspecialchars((string) ($profile['owner_name'] ?? $vendor['owner_name'])) ?></p>
    <p><strong>Email:</strong> <?= htmlspecialchars((string) ($profile['email'] ?? $vendor['email'])) ?></p>
    <p><strong>Phone:</strong> <?= htmlspecialchars((string) ($profile['phone'] ?? $vendor['mobile'])) ?></p>
    <p><strong>Address:</strong> <?= htmlspecialchars((string) ($profile['address'] ?? '')) ?>, <?= htmlspecialchars((string) ($profile['city'] ?? $vendor['city'])) ?>, <?= htmlspecialchars((string) ($profile['state'] ?? $vendor['state'])) ?></p>
    <p><strong>GST:</strong> <?= htmlspecialchars((string) ($profile['gst_number'] ?? ($branding['gst'] ?? ''))) ?></p>
    <p><strong>Footer text:</strong> <?= htmlspecialchars((string) ($branding['footer_text'] ?? '')) ?></p>
    <p><a class="btn" href="/app/pm-surya-ghar/company-branding">Manage branding for PM Surya Ghar</a></p>
</div>

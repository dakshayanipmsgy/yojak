<?php
$moduleKey = (string) ($routeContext['module_key'] ?? '');
$moduleMeta = (array) (($workspace['module_metadata'][$moduleKey] ?? []));
?>
<h1><?= htmlspecialchars((string) ($routeContext['label'] ?? $title ?? 'Module')) ?></h1>
<div class="card">
    <p><?= htmlspecialchars((string) ($routeContext['description'] ?? $description ?? 'Module shell is ready for implementation.')) ?></p>
    <p><strong>Current Scheme:</strong> <?= htmlspecialchars((string) ($workspace['scheme']['scheme_name'] ?? $schemeKey ?? 'PM Surya Ghar')) ?></p>
    <p><strong>Nav Group:</strong> <?= htmlspecialchars((string) ($routeContext['nav_group_label'] ?? 'Module')) ?></p>
    <p><strong>Entitlement Confirmed:</strong> <?= \App\Services\AccessService::hasModuleAccess($vendor, $moduleKey, (string) ($workspace['scheme_key'] ?? 'pm_surya_ghar')) ? 'Yes' : 'No' ?></p>
</div>

<div class="grid grid-2">
    <div class="card">
        <h3>Workflow Role</h3>
        <p><?= htmlspecialchars((string) ($moduleMeta['short_purpose'] ?? 'This module participates in PM Surya Ghar operations.')) ?></p>
        <p><strong>Stages Influenced:</strong> <?= htmlspecialchars(implode(', ', (array) ($moduleMeta['workflow_stages'] ?? []))) ?></p>
    </div>
    <div class="card">
        <h3>Module Relationships</h3>
        <p><strong>Upstream:</strong> <?= htmlspecialchars(implode(', ', (array) ($moduleMeta['upstream_modules'] ?? []))) ?></p>
        <p><strong>Downstream:</strong> <?= htmlspecialchars(implode(', ', (array) ($moduleMeta['downstream_modules'] ?? []))) ?></p>
    </div>
</div>

<div class="card">
    <h3>Future Implementation</h3>
    <p><?= htmlspecialchars((string) ($moduleMeta['future_actions'] ?? 'Detailed CRUD/business logic will be implemented in upcoming module prompts.')) ?></p>
    <a class="btn secondary" href="/app/pm-surya-ghar/dashboard">Back to PM Surya Ghar Dashboard</a>
</div>

<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\JsonStorage;

class ProvisioningService
{
    public static function provisionTenant(array $vendor, array $plan, array $scheme): void
    {
        $tenantPath = DATA_PATH . '/tenants/tenant_' . $vendor['tenant_id'];
        JsonStorage::ensureDir($tenantPath . '/shared/uploads');
        JsonStorage::ensureDir($tenantPath . '/shared/exports');
        JsonStorage::ensureDir($tenantPath . '/shared/documents');

        JsonStorage::ensureFile($tenantPath . '/tenant_meta.json', [
            'tenant_id' => $vendor['tenant_id'],
            'vendor_id' => $vendor['vendor_id'],
            'created_at' => date('c'),
        ]);

        JsonStorage::ensureFile($tenantPath . '/profile.json', [
            'owner_name' => $vendor['owner_name'],
            'company_name' => $vendor['company_name'],
            'mobile' => $vendor['mobile'],
            'email' => $vendor['email'],
            'city' => $vendor['city'],
            'state' => $vendor['state'],
        ]);

        JsonStorage::ensureFile($tenantPath . '/branding.json', ['logo' => '', 'theme' => 'default']);
        JsonStorage::ensureFile($tenantPath . '/enabled_modules.json', $vendor['enabled_modules']);
        JsonStorage::ensureFile($tenantPath . '/enabled_schemes.json', $vendor['enabled_schemes']);
        JsonStorage::ensureFile($tenantPath . '/billing.json', ['plan_key' => $plan['plan_key'], 'notes' => '']);
        JsonStorage::ensureFile($tenantPath . '/subscription_snapshot.json', ['plan' => $plan, 'captured_at' => date('c')]);

        $schemeKey = $scheme['scheme_key'];
        $schemePath = $tenantPath . '/' . $schemeKey;
        JsonStorage::ensureDir($schemePath . '/uploads');
        JsonStorage::ensureDir($schemePath . '/documents');
        JsonStorage::ensureDir($schemePath . '/snapshots');

        $placeholders = [
            'scheme_meta' => ['scheme_key' => $schemeKey, 'name' => $scheme['scheme_name']],
            'settings' => [],
            'templates' => [],
            'message_templates' => [],
            'explainer_content' => [],
            'rate_chart' => [],
            'workflow' => ['ref' => $scheme['workflow_definition'] ?? 'default-workflow'],
            'leads' => [],
            'customers' => [],
            'quotations' => [],
            'agreements' => [],
            'receipts' => [],
            'invoices' => [],
            'complaints' => [],
            'reports' => [],
        ];

        foreach ($placeholders as $name => $default) {
            JsonStorage::ensureFile($schemePath . '/' . $name . '.json', $default);
        }

        AuditService::log('tenant_provisioned', ['tenant_id' => $vendor['tenant_id'], 'vendor_id' => $vendor['vendor_id']]);
    }
}

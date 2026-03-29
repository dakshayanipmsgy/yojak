<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\JsonStorage;

class ProvisioningService
{
    public static function provisionTenantForApprovedSignup(
        array $signup,
        array $assignedSchemeKeys,
        string $planKey,
        string $billingCycle,
        int $trialDays,
        string $approverAdminId,
        bool $skipSignupUpdate = false,
        ?array $existingVendor = null
    ): array {
        $vendors = RegistryService::get('vendors');
        $vendor = $existingVendor;

        if (!$vendor) {
            $vendor = RegistryService::findBy($vendors, 'created_from_signup_id', $signup['signup_id']);
        }
        if ($vendor) {
            BootstrapService::repairTenantStorage($vendor['tenant_id']);
            return $vendor;
        }

        $plan = RegistryService::getPlanByKey($planKey) ?? RegistryService::getAllPlans()[0];
        $vendorId = CounterService::next('vendor');
        $tenantId = CounterService::next('tenant');
        $schemeKey = $assignedSchemeKeys[0] ?? 'pm_surya_ghar';
        $entitled = array_values(array_unique(array_merge($plan['included_modules'] ?? [], ['dashboard', 'company-branding', 'subscription-billing'])));

        $vendor = [
            'vendor_id' => $vendorId,
            'tenant_id' => $tenantId,
            'owner_name' => $signup['owner_name'],
            'company_name' => $signup['company_name'],
            'mobile' => $signup['mobile'],
            'email' => strtolower($signup['email']),
            'city' => $signup['city'],
            'state' => $signup['state'],
            'password_hash' => $signup['password_hash'],
            'verification_status' => 'verified',
            'account_status' => 'active',
            'created_from_signup_id' => $signup['signup_id'],
            'default_scheme_key' => $schemeKey,
            'current_plan_key' => $plan['plan_key'],
            'enabled_schemes' => $assignedSchemeKeys,
            'enabled_modules' => $entitled,
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        $vendors[] = $vendor;
        RegistryService::put('vendors', $vendors);

        self::provisionTenantFilesystem($vendor, $plan, $billingCycle, $trialDays, $approverAdminId);
        SubscriptionService::assignForVendor($vendor, [
            'plan_key' => $plan['plan_key'],
            'subscription_mode' => 'plan',
            'billing_cycle' => $billingCycle,
            'subscription_status' => 'trial',
            'trial_days_assigned' => $trialDays,
            'source_type' => 'signup',
            'source_ref' => $signup['signup_id'],
        ]);

        if (!$skipSignupUpdate) {
            $pending = RegistryService::get('pending_signups');
            foreach ($pending as &$row) {
                if (($row['signup_id'] ?? null) === $signup['signup_id']) {
                    $row['verification_status'] = 'processed';
                    $row['processed_at'] = date('c');
                    $row['processed_by'] = $approverAdminId;
                    $row['process_note'] = 'Provisioned to tenant ' . $tenantId;
                }
            }
            unset($row);
            RegistryService::put('pending_signups', $pending);
        }

        AuditService::log('tenant_provisioned', 'admin', $approverAdminId, 'tenant', $tenantId, 'Tenant provisioned from signup.', ['signup_id' => $signup['signup_id'], 'vendor_id' => $vendorId]);
        return $vendor;
    }

    private static function provisionTenantFilesystem(array $vendor, array $plan, string $billingCycle, int $trialDays, string $approverAdminId): void
    {
        $tenantPath = TenantStorageService::getTenantPath($vendor['tenant_id']);
        foreach (['meta', 'shared', 'shared_uploads/logos', 'shared_uploads/documents', 'shared_uploads/media', 'shared_uploads/misc', 'shared_exports', 'shared_documents', 'shared_snapshots', 'schemes'] as $dir) {
            JsonStorage::ensureDir($tenantPath . '/' . $dir);
        }

        RegistryService::putConfigData($tenantPath . '/meta/tenant_meta.json', [
            'tenant_id' => $vendor['tenant_id'], 'vendor_id' => $vendor['vendor_id'], 'company_name' => $vendor['company_name'],
            'default_scheme_key' => $vendor['default_scheme_key'], 'tenant_status' => 'active', 'provisioned_at' => date('c'), 'last_repaired_at' => date('c'), 'schema_version' => 1, 'storage_version' => 2,
        ]);
        RegistryService::putConfigData($tenantPath . '/meta/vendor_link.json', ['vendor_id' => $vendor['vendor_id'], 'tenant_id' => $vendor['tenant_id'], 'email' => $vendor['email'], 'mobile' => $vendor['mobile'], 'linked_at' => date('c')]);
        RegistryService::putConfigData($tenantPath . '/meta/entitlement_cache.json', ['current_plan_key' => $plan['plan_key'], 'enabled_scheme_keys' => $vendor['enabled_schemes'], 'enabled_module_keys' => $vendor['enabled_modules'], 'subscription_status' => 'trial', 'updated_at' => date('c')]);

        JsonStorage::ensureFile($tenantPath . '/meta/provisioning_log.json', ['entries' => [], 'meta' => ['version' => 1, 'updated_at' => '']]);
        self::appendProvisioningLog($tenantPath, 'tenant_created', $approverAdminId, ['tenant_id' => $vendor['tenant_id']]);

        RegistryService::putConfigData($tenantPath . '/shared/profile.json', ['owner_name' => $vendor['owner_name'], 'company_name' => $vendor['company_name'], 'phone' => $vendor['mobile'], 'email' => $vendor['email'], 'address' => '', 'city' => $vendor['city'], 'state' => $vendor['state'], 'pincode' => '', 'gst_number' => '', 'website' => '', 'business_details' => '']);
        RegistryService::putConfigData($tenantPath . '/shared/branding.json', RegistryService::getConfigData(DATA_PATH . '/platform/defaults/core/branding_defaults.json'));
        RegistryService::putConfigData($tenantPath . '/shared/billing.json', ['current_plan_key' => $plan['plan_key'], 'billing_cycle' => $billingCycle, 'renewal_date' => date('Y-m-d', strtotime('+' . $trialDays . ' days')), 'trial_days_assigned' => $trialDays, 'payment_history_summary' => [], 'currency' => 'INR', 'last_updated' => date('c')]);
        RegistryService::putConfigData($tenantPath . '/shared/enabled_modules.json', ['keys' => $vendor['enabled_modules']]);
        RegistryService::putConfigData($tenantPath . '/shared/enabled_schemes.json', ['keys' => $vendor['enabled_schemes']]);
        RegistryService::putConfigData($tenantPath . '/shared/subscription_snapshot.json', ['plan' => $plan['plan_key'], 'modules' => $vendor['enabled_modules'], 'cycle' => $billingCycle, 'trial_dates' => ['start' => date('Y-m-d'), 'end' => date('Y-m-d', strtotime('+' . $trialDays . ' days'))], 'subscription_status' => 'trial', 'assigned_at' => date('c')]);
        RegistryService::putConfigData($tenantPath . '/shared/account_preferences.json', ['timezone' => 'Asia/Kolkata', 'language' => 'en']);

        foreach ($vendor['enabled_schemes'] as $schemeKey) {
            BootstrapService::ensureTenantSchemeStructure($vendor['tenant_id'], $schemeKey);
            self::appendProvisioningLog($tenantPath, 'scheme_initialized', $approverAdminId, ['scheme_key' => $schemeKey]);
        }

        self::appendProvisioningLog($tenantPath, 'defaults_copied', $approverAdminId, ['plan_key' => $plan['plan_key']]);
    }

    private static function appendProvisioningLog(string $tenantPath, string $event, string $actor, array $payload): void
    {
        $file = $tenantPath . '/meta/provisioning_log.json';
        $log = JsonStorage::read($file, ['entries' => [], 'meta' => ['version' => 1, 'updated_at' => '']]);
        $log['entries'][] = ['event_type' => $event, 'actor' => $actor, 'payload' => $payload, 'created_at' => date('c')];
        JsonStorage::write($file, JsonStorage::touchMeta($log));
    }
}

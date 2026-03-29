<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\JsonStorage;

class BootstrapService
{
    public static function initialize(): void
    {
        JsonStorage::ensureDir(DATA_PATH . '/platform');
        JsonStorage::ensureDir(DATA_PATH . '/tenants');

        $modules = self::seedModules();
        $plans = self::seedPlans();
        $schemes = self::seedSchemes();

        JsonStorage::ensureFile(DATA_PATH . '/platform/modules.json', $modules);
        JsonStorage::ensureFile(DATA_PATH . '/platform/plans.json', $plans);
        JsonStorage::ensureFile(DATA_PATH . '/platform/schemes.json', $schemes);
        JsonStorage::ensureFile(DATA_PATH . '/platform/vendors.json', []);
        JsonStorage::ensureFile(DATA_PATH . '/platform/pending_signups.json', []);
        JsonStorage::ensureFile(DATA_PATH . '/platform/subscriptions.json', []);
        JsonStorage::ensureFile(DATA_PATH . '/platform/counters.json', ['vendor' => 1, 'tenant' => 1, 'signup' => 1]);
        JsonStorage::ensureFile(DATA_PATH . '/platform/audit_log.json', []);
        JsonStorage::ensureFile(DATA_PATH . '/platform/superadmin_settings.json', [
            'platform_name' => 'Yojak',
            'allow_signup_globally' => true,
            'demo_mode' => true,
            'email' => 'admin@yojak.local',
            'password_hash' => password_hash('Admin@123', PASSWORD_DEFAULT),
        ]);

        self::seedDemoVendor();
        self::seedPendingSignup();
    }

    private static function seedSchemes(): array
    {
        return [[
            'scheme_id' => 'SCH001',
            'scheme_key' => 'pm-surya-ghar',
            'scheme_name' => 'PM Surya Ghar',
            'public_title' => 'PM Surya Ghar Vendor Platform',
            'description' => 'End-to-end vendor workspace for PM Surya Ghar workflows.',
            'active_flag' => true,
            'signup_enabled' => true,
            'public_visible' => true,
            'public_landing_content' => [
                'overview' => 'Manage leads, quotations, agreements, billing and documents with a single workspace.',
                'benefits' => ['Faster onboarding', 'Entitlement-based modules', 'Scheme-ready architecture'],
                'workflow_summary' => 'Signup -> Approval -> Provisioning -> Operate',
            ],
            'workflow_definition' => 'pm_surya_ghar_v1',
            'module_registry' => 'pm_surya_ghar_v1',
            'default_settings' => [],
            'default_templates' => [],
            'default_calculations' => [],
            'content_definition' => [],
            'document_definition' => [],
        ]];
    }

    private static function seedModules(): array
    {
        $rows = [
            ['dashboard', 'Dashboard', 0, true],
            ['leads', 'Leads', 499, false],
            ['customers', 'Customers', 299, false],
            ['quotations', 'Quotations', 899, false],
            ['solar-finance', 'Solar and Finance', 1499, false],
            ['agreements', 'Agreements', 399, false],
            ['payment-receipts', 'Payment Receipts', 299, false],
            ['invoices', 'Invoices', 499, false],
            ['complaints', 'Complaints', 499, false],
            ['templates-media', 'Templates & Media', 399, false],
            ['messaging-templates', 'Messaging Templates', 199, false],
            ['explainer-content', 'Explainer Content', 199, false],
            ['rate-chart', 'Rate Chart', 299, false],
            ['reports-exports', 'Reports & Exports', 299, false],
            ['company-branding', 'Company Profile & Branding', 0, true],
            ['subscription-billing', 'Subscription & Billing', 0, true],
            ['scheme-settings', 'Scheme Settings', 199, false],
        ];
        $data = [];
        $i = 1;
        foreach ($rows as [$key, $name, $price, $core]) {
            $data[] = [
                'module_id' => 'MOD' . str_pad((string) $i++, 3, '0', STR_PAD_LEFT),
                'module_key' => $key,
                'module_name' => $name,
                'module_scope' => in_array($key, ['dashboard', 'company-branding', 'subscription-billing'], true) ? 'core' : 'scheme-specific',
                'scheme_keys' => ['pm-surya-ghar'],
                'description' => $name . ' module placeholder.',
                'monthly_price' => $price,
                'quarterly_price' => $price * 3,
                'yearly_price' => $price * 12,
                'optional_setup_fee' => 0,
                'enabled_flag' => true,
                'dependency_list' => [],
                'visibility_rules' => [],
                'config_json' => [],
                'is_core_included' => $core,
            ];
        }
        return $data;
    }

    private static function seedPlans(): array
    {
        return [
            [
                'plan_id' => 'PLAN001',
                'plan_key' => 'basic',
                'plan_name' => 'Basic Package',
                'scheme_key' => 'pm-surya-ghar',
                'included_modules' => ['leads', 'customers', 'quotations', 'messaging-templates', 'templates-media'],
                'monthly_price' => 1000,
                'quarterly_price' => 2700,
                'yearly_price' => 10000,
                'trial_days' => 14,
                'limits_json' => [],
                'recommended_flag' => false,
                'active_flag' => true,
                'description' => 'Starter bundle for new vendors.',
            ],
            [
                'plan_id' => 'PLAN002',
                'plan_key' => 'growth',
                'plan_name' => 'Growth Package',
                'scheme_key' => 'pm-surya-ghar',
                'included_modules' => ['leads', 'customers', 'quotations', 'messaging-templates', 'templates-media', 'solar-finance', 'agreements', 'payment-receipts', 'explainer-content', 'rate-chart', 'reports-exports', 'scheme-settings'],
                'monthly_price' => 3000,
                'quarterly_price' => 8400,
                'yearly_price' => 33000,
                'trial_days' => 14,
                'limits_json' => [],
                'recommended_flag' => true,
                'active_flag' => true,
                'description' => 'Most popular plan for growing vendors.',
            ],
            [
                'plan_id' => 'PLAN003',
                'plan_key' => 'pro',
                'plan_name' => 'Pro Package',
                'scheme_key' => 'pm-surya-ghar',
                'included_modules' => ['leads', 'customers', 'quotations', 'messaging-templates', 'templates-media', 'solar-finance', 'agreements', 'payment-receipts', 'explainer-content', 'rate-chart', 'reports-exports', 'scheme-settings', 'invoices', 'complaints'],
                'monthly_price' => 5000,
                'quarterly_price' => 14000,
                'yearly_price' => 55000,
                'trial_days' => 14,
                'limits_json' => [],
                'recommended_flag' => false,
                'active_flag' => true,
                'description' => 'Complete package with advanced modules.',
            ],
        ];
    }

    private static function seedDemoVendor(): void
    {
        $vendors = RegistryService::get('vendors', []);
        foreach ($vendors as $vendor) {
            if (($vendor['email'] ?? '') === 'vendor@demo.local') {
                return;
            }
        }

        $plan = RegistryService::findBy(RegistryService::get('plans', []), 'plan_key', 'growth');
        $vendor = [
            'vendor_id' => 'VEN0001',
            'tenant_id' => 'TEN0001',
            'owner_name' => 'Demo Owner',
            'company_name' => 'Demo Solar LLP',
            'mobile' => '9000000001',
            'email' => 'vendor@demo.local',
            'city' => 'Ahmedabad',
            'state' => 'Gujarat',
            'password_hash' => password_hash('Vendor@123', PASSWORD_DEFAULT),
            'verification_status' => 'verified',
            'account_status' => 'active',
            'subscription_status' => 'trial',
            'plan_key' => 'growth',
            'trial_end_date' => date('Y-m-d', strtotime('+14 days')),
            'enabled_schemes' => ['pm-surya-ghar'],
            'enabled_modules' => array_values(array_unique(array_merge($plan['included_modules'] ?? [], ['dashboard', 'company-branding', 'subscription-billing']))),
            'created_at' => date('c'),
        ];
        $vendors[] = $vendor;
        RegistryService::put('vendors', $vendors);
        ProvisioningService::provisionTenant($vendor, $plan, RegistryService::get('schemes', [])[0]);
    }

    private static function seedPendingSignup(): void
    {
        $pending = RegistryService::get('pending_signups', []);
        foreach ($pending as $p) {
            if (($p['email'] ?? '') === 'pending@demo.local') {
                return;
            }
        }
        $pending[] = [
            'signup_id' => 'SGN0001',
            'scheme_key' => 'pm-surya-ghar',
            'owner_name' => 'Pending Owner',
            'company_name' => 'Pending Energy',
            'mobile' => '9000000002',
            'email' => 'pending@demo.local',
            'city' => 'Surat',
            'state' => 'Gujarat',
            'password_hash' => password_hash('Pending@123', PASSWORD_DEFAULT),
            'verification_status' => 'pending',
            'account_status' => 'inactive',
            'subscription_status' => 'none',
            'requested_plan_key' => 'growth',
            'status' => 'pending',
            'submitted_at' => date('c'),
        ];
        RegistryService::put('pending_signups', $pending);
    }
}

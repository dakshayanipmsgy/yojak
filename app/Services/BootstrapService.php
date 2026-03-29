<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\JsonStorage;

class BootstrapService
{
    public static function initialize(): void
    {
        self::bootstrapPlatformStorage();
        self::migrateLegacyStructure();
        self::seedDemoData();
        foreach (RegistryService::get('vendors') as $vendor) {
            self::repairTenantStorage((string) $vendor['tenant_id']);
        }
    }

    public static function bootstrapPlatformStorage(): void
    {
        $dirs = [
            DATA_PATH,
            DATA_PATH . '/platform/registries',
            DATA_PATH . '/platform/system',
            DATA_PATH . '/platform/defaults/core',
            DATA_PATH . '/platform/defaults/schemes/pm_surya_ghar',
            DATA_PATH . '/platform/cache',
            DATA_PATH . '/tenants',
        ];
        foreach ($dirs as $dir) {
            JsonStorage::ensureDir($dir);
        }

        foreach (self::defaultRegistryContracts() as $name => $payload) {
            JsonStorage::ensureFile(RegistryService::registryFile($name), $payload);
        }

        foreach (self::defaultSystemContracts() as $name => $payload) {
            JsonStorage::ensureFile(RegistryService::systemFile($name), $payload);
        }

        foreach (self::defaultCoreFiles() as $path => $payload) {
            JsonStorage::ensureFile($path, $payload);
        }

        foreach (self::defaultPmSuryaGharFiles() as $path => $payload) {
            JsonStorage::ensureFile($path, $payload);
        }

        self::touchBootstrapMeta();
    }

    public static function defaultCounters(): array
    {
        return [
            'counters' => [
                'scheme' => 1, 'module' => 17, 'plan' => 3, 'vendor' => 1, 'tenant' => 1, 'signup' => 1,
                'subscription' => 1, 'admin' => 1, 'lead' => 0, 'customer' => 0, 'quotation' => 0, 'agreement' => 0,
                'receipt' => 0, 'invoice' => 0, 'complaint' => 0, 'report' => 0, 'audit_entry' => 0,
            ],
            'meta' => ['version' => 1, 'updated_at' => ''],
        ];
    }

    public static function repairTenantStorage(string $tenantId): void
    {
        $vendors = RegistryService::get('vendors');
        $vendor = RegistryService::findBy($vendors, 'tenant_id', $tenantId);
        if (!$vendor) {
            return;
        }
        $tenantPath = TenantStorageService::getTenantPath($tenantId);
        foreach (['meta', 'shared', 'shared_uploads/logos', 'shared_uploads/documents', 'shared_uploads/media', 'shared_uploads/misc', 'shared_exports', 'shared_documents', 'shared_snapshots', 'schemes'] as $dir) {
            JsonStorage::ensureDir($tenantPath . '/' . $dir);
        }
        JsonStorage::ensureFile($tenantPath . '/meta/provisioning_log.json', ['entries' => [], 'meta' => ['version' => 1, 'updated_at' => '']]);
        JsonStorage::ensureFile($tenantPath . '/meta/tenant_meta.json', ['data' => ['tenant_id' => $tenantId, 'vendor_id' => $vendor['vendor_id'], 'company_name' => $vendor['company_name'], 'default_scheme_key' => $vendor['default_scheme_key'] ?? 'pm_surya_ghar', 'tenant_status' => 'active', 'provisioned_at' => date('c'), 'last_repaired_at' => date('c'), 'schema_version' => 1, 'storage_version' => 2], 'meta' => ['version' => 1, 'updated_at' => '']]);
        JsonStorage::ensureFile($tenantPath . '/meta/vendor_link.json', ['data' => ['vendor_id' => $vendor['vendor_id'], 'tenant_id' => $tenantId, 'email' => $vendor['email'], 'mobile' => $vendor['mobile'], 'linked_at' => date('c')], 'meta' => ['version' => 1, 'updated_at' => '']]);
        JsonStorage::ensureFile($tenantPath . '/meta/entitlement_cache.json', ['data' => ['current_plan_key' => $vendor['current_plan_key'] ?? 'growth', 'enabled_scheme_keys' => $vendor['enabled_schemes'] ?? ['pm_surya_ghar'], 'enabled_module_keys' => $vendor['enabled_modules'] ?? [], 'subscription_status' => 'active', 'updated_at' => date('c')], 'meta' => ['version' => 1, 'updated_at' => '']]);
        JsonStorage::ensureFile($tenantPath . '/shared/profile.json', ['data' => ['owner_name' => $vendor['owner_name'], 'company_name' => $vendor['company_name'], 'phone' => $vendor['mobile'], 'email' => $vendor['email'], 'address' => '', 'city' => $vendor['city'], 'state' => $vendor['state'], 'pincode' => '', 'gst_number' => '', 'website' => '', 'business_details' => ''], 'meta' => ['version' => 1, 'updated_at' => '']]);
        JsonStorage::ensureFile($tenantPath . '/shared/branding.json', JsonStorage::read(DATA_PATH . '/platform/defaults/core/branding_defaults.json', ['data' => [], 'meta' => ['version' => 1, 'updated_at' => '']]));
        JsonStorage::ensureFile($tenantPath . '/shared/billing.json', JsonStorage::read(DATA_PATH . '/platform/defaults/core/billing_defaults.json', ['data' => [], 'meta' => ['version' => 1, 'updated_at' => '']]));
        JsonStorage::ensureFile($tenantPath . '/shared/enabled_modules.json', ['data' => ['keys' => $vendor['enabled_modules'] ?? []], 'meta' => ['version' => 1, 'updated_at' => '']]);
        JsonStorage::ensureFile($tenantPath . '/shared/enabled_schemes.json', ['data' => ['keys' => $vendor['enabled_schemes'] ?? ['pm_surya_ghar']], 'meta' => ['version' => 1, 'updated_at' => '']]);
        JsonStorage::ensureFile($tenantPath . '/shared/subscription_snapshot.json', ['data' => ['plan' => $vendor['current_plan_key'] ?? 'growth', 'modules' => $vendor['enabled_modules'] ?? [], 'cycle' => 'monthly', 'trial_dates' => ['start' => date('Y-m-d'), 'end' => date('Y-m-d')], 'subscription_status' => 'active', 'assigned_at' => date('c')], 'meta' => ['version' => 1, 'updated_at' => '']]);
        JsonStorage::ensureFile($tenantPath . '/shared/account_preferences.json', ['data' => ['timezone' => 'Asia/Kolkata', 'language' => 'en'], 'meta' => ['version' => 1, 'updated_at' => '']]);
        $schemes = $vendor['enabled_schemes'] ?? [$vendor['default_scheme_key'] ?? 'pm_surya_ghar'];
        foreach ($schemes as $schemeKey) {
            self::ensureTenantSchemeStructure($tenantId, str_replace('-', '_', $schemeKey));
        }
    }

    public static function ensureTenantSchemeStructure(string $tenantId, string $schemeKey): void
    {
        $base = TenantStorageService::getTenantSchemePath($tenantId, $schemeKey);
        foreach (['config', 'records', 'indexes', 'documents/quotations', 'documents/agreements', 'documents/receipts', 'documents/invoices', 'documents/reports', 'snapshots/quotations', 'snapshots/agreements', 'snapshots/receipts', 'snapshots/invoices', 'snapshots/reports', 'uploads/templates', 'uploads/media', 'uploads/imports', 'uploads/exports'] as $dir) {
            JsonStorage::ensureDir($base . '/' . $dir);
        }

        $configDefaults = self::schemeConfigDefaults();
        foreach ($configDefaults as $name => $data) {
            JsonStorage::ensureFile($base . '/config/' . $name . '.json', ['data' => $data, 'meta' => ['version' => 1, 'updated_at' => '']]);
        }

        foreach (['leads','customers','quotations','agreements','receipts','invoices','complaints','reports'] as $recordType) {
            JsonStorage::ensureFile($base . '/records/' . $recordType . '.json', ['items' => [], 'meta' => ['version' => 1, 'updated_at' => '', 'next_hint' => null]]);
        }

        foreach (['lead','customer','quotation','agreement','receipt','invoice','complaint'] as $idx) {
            JsonStorage::ensureFile($base . '/indexes/' . $idx . '_index.json', ['by_id' => [], 'by_status' => [], 'by_date' => [], 'meta' => ['version' => 1, 'updated_at' => '']]);
        }

        JsonStorage::ensureFile($base . '/scheme_meta.json', ['data' => ['scheme_key' => $schemeKey, 'scheme_name' => 'PM Surya Ghar', 'enabled_flag' => true, 'activated_at' => date('c'), 'last_updated_at' => date('c'), 'storage_schema_version' => 1], 'meta' => ['version' => 1, 'updated_at' => '']]);
        JsonStorage::ensureFile($base . '/settings.json', ['data' => ['subsidy_assumptions' => [], 'finance_assumptions' => [], 'workflow_toggles' => ['enabled' => true], 'content_toggles' => [], 'defaults' => []], 'meta' => ['version' => 1, 'updated_at' => '']]);
        JsonStorage::ensureFile($base . '/workflow_runtime.json', ['data' => ['stage_order' => ['lead','engagement','detailed_information_shared','solar_finance','quotation','quotation_accepted','agreement','payment_receipt','invoice','complaint_support'], 'allowed_transitions' => ['lead' => ['engagement']]], 'meta' => ['version' => 1, 'updated_at' => '']]);
    }

    private static function migrateLegacyStructure(): void
    {
        $legacyPlatform = DATA_PATH . '/platform';
        foreach (['schemes','modules','plans','vendors','pending_signups','subscriptions','superadmin_settings'] as $name) {
            $legacy = $legacyPlatform . '/' . $name . '.json';
            $target = RegistryService::registryFile($name);
            if (file_exists($legacy) && !file_exists($target)) {
                $rows = JsonStorage::read($legacy, []);
                JsonStorage::write($target, ['items' => array_is_list($rows) ? $rows : [], 'meta' => ['version' => 1, 'updated_at' => date('c')]]);
            }
        }
    }

    private static function seedDemoData(): void
    {
        if (!RegistryService::getVendorByEmail('vendor@demo.local')) {
            $plan = RegistryService::getPlanByKey('growth') ?? RegistryService::getAllPlans()[0];
            $vendor = [
                'vendor_id' => 'VND-0001', 'tenant_id' => 'TNT-0001', 'owner_name' => 'Demo Owner', 'company_name' => 'Demo Solar LLP', 'mobile' => '9000000001',
                'email' => 'vendor@demo.local', 'city' => 'Ahmedabad', 'state' => 'Gujarat', 'password_hash' => password_hash('Vendor@123', PASSWORD_DEFAULT),
                'verification_status' => 'verified', 'account_status' => 'active', 'created_from_signup_id' => 'seed', 'default_scheme_key' => 'pm_surya_ghar',
                'current_plan_key' => $plan['plan_key'], 'enabled_schemes' => ['pm_surya_ghar'],
                'enabled_modules' => array_values(array_unique(array_merge($plan['included_modules'] ?? [], ['dashboard','company-branding','subscription-billing']))),
                'created_at' => date('c'), 'updated_at' => date('c'),
            ];
            $vendors = RegistryService::get('vendors'); $vendors[] = $vendor; RegistryService::put('vendors', $vendors);
            ProvisioningService::provisionTenantForApprovedSignup([
                'signup_id' => 'SEED', 'owner_name' => $vendor['owner_name'], 'company_name' => $vendor['company_name'], 'mobile' => $vendor['mobile'], 'email' => $vendor['email'],
                'city' => $vendor['city'], 'state' => $vendor['state'], 'address' => '', 'business_details' => '', 'gst_number' => '', 'website' => '', 'notes' => '', 'password_hash' => $vendor['password_hash'],
            ], ['pm_surya_ghar'], $plan['plan_key'], 'monthly', 14, 'ADM-0001', true, $vendor);
        }


        $subscriptions = RegistryService::get('subscriptions');
        if (!RegistryService::findBy($subscriptions, 'vendor_id', 'VND-0001')) {
            $subscriptions[] = [
                'subscription_id' => 'SUB-0001',
                'vendor_id' => 'VND-0001',
                'tenant_id' => 'TNT-0001',
                'scheme_key' => 'pm_surya_ghar',
                'plan_key' => 'growth',
                'billing_cycle' => 'monthly',
                'subscription_status' => 'trial',
                'started_at' => date('c'),
                'trial_started_at' => date('c'),
                'trial_ends_at' => date('c', strtotime('+14 days')),
                'active_from' => null,
                'active_until' => null,
                'cancelled_at' => null,
                'override_pricing_json' => [],
                'entitled_modules' => array_values(array_unique(array_merge($plan['included_modules'] ?? [], ['dashboard','company-branding','subscription-billing']))),
                'source_type' => 'seed',
                'source_ref' => 'SEED',
                'created_at' => date('c'),
                'updated_at' => date('c'),
            ];
            RegistryService::put('subscriptions', $subscriptions);
        }

        $pending = RegistryService::get('pending_signups');
        if (!RegistryService::findBy($pending, 'email', 'pending@demo.local')) {
            $pending[] = [
                'signup_id' => 'SGN-0001', 'requested_scheme_key' => 'pm_surya_ghar', 'owner_name' => 'Pending Owner', 'company_name' => 'Pending Energy', 'mobile' => '9000000002',
                'email' => 'pending@demo.local', 'city' => 'Surat', 'state' => 'Gujarat', 'address' => '', 'business_details' => '', 'gst_number' => '', 'website' => '', 'notes' => '',
                'password_hash' => password_hash('Pending@123', PASSWORD_DEFAULT), 'verification_status' => 'pending', 'submitted_at' => date('c'), 'processed_at' => null, 'processed_by' => null, 'process_note' => null,
            ];
            RegistryService::put('pending_signups', $pending);
        }
    }

    private static function defaultRegistryContracts(): array
    {
        return [
            'schemes' => ['items' => [[
                'scheme_id' => 'SCH-0001', 'scheme_key' => 'pm_surya_ghar', 'scheme_name' => 'PM Surya Ghar', 'public_title' => 'PM Surya Ghar Vendor Platform',
                'description' => 'Operational workspace for PM Surya Ghar vendors.', 'active_flag' => true, 'public_visible' => true, 'signup_enabled' => true, 'public_sort_order' => 1,
                'workflow_definition_ref' => 'defaults/schemes/pm_surya_ghar/workflow_definition.json', 'module_registry_keys' => ['dashboard','leads','customers','quotations','solar-finance','agreements','payment-receipts','invoices','complaints','templates-media','messaging-templates','explainer-content','rate-chart','reports-exports','company-branding','subscription-billing','scheme-settings'],
                'default_settings_ref' => 'defaults/schemes/pm_surya_ghar/scheme_defaults.json', 'default_templates_ref' => 'defaults/schemes/pm_surya_ghar/templates_defaults.json', 'default_calculations_ref' => 'defaults/schemes/pm_surya_ghar/calculation_defaults.json',
                'content_definition_ref' => 'defaults/schemes/pm_surya_ghar/content_definition.json', 'document_definition_ref' => 'defaults/schemes/pm_surya_ghar/document_definition.json', 'created_at' => date('c'), 'updated_at' => date('c'),
            ]], 'meta' => ['version' => 1, 'updated_at' => '']],
            'modules' => ['items' => self::moduleSeeds(), 'meta' => ['version' => 1, 'updated_at' => '']],
            'plans' => ['items' => self::planSeeds(), 'meta' => ['version' => 1, 'updated_at' => '']],
            'vendors' => ['items' => [], 'meta' => ['version' => 1, 'updated_at' => '']],
            'pending_signups' => ['items' => [], 'meta' => ['version' => 1, 'updated_at' => '']],
            'subscriptions' => ['items' => [], 'meta' => ['version' => 1, 'updated_at' => '']],
            'superadmin_accounts' => ['items' => [[
                'admin_id' => 'ADM-0001', 'email' => 'admin@yojak.local', 'display_name' => 'Super Admin', 'password_hash' => password_hash('Admin@123', PASSWORD_DEFAULT), 'active_flag' => true, 'created_at' => date('c'), 'updated_at' => date('c')
            ]], 'meta' => ['version' => 1, 'updated_at' => '']],
            'superadmin_settings' => ['items' => [[
                'platform_name' => 'Yojak', 'allow_signup_globally' => true, 'maintenance_mode' => false, 'demo_mode' => true, 'public_footer_text' => 'Yojak Platform', 'default_trial_plan_key' => 'growth', 'default_billing_cycle' => 'monthly', 'updated_at' => date('c')
            ]], 'meta' => ['version' => 1, 'updated_at' => '']],
        ];
    }

    private static function defaultSystemContracts(): array
    {
        return [
            'counters' => self::defaultCounters(),
            'audit_log' => ['entries' => [], 'meta' => ['version' => 1, 'updated_at' => '']],
            'bootstrap_meta' => ['data' => ['current_storage_schema_version' => 2, 'bootstrap_completed_at' => date('c'), 'last_repair_at' => date('c')], 'meta' => ['version' => 1, 'updated_at' => '']],
            'migrations' => ['data' => ['storage_version' => 2, 'applied_migration_keys' => ['v2_storage_contract'], 'timestamps' => [date('c')]], 'meta' => ['version' => 1, 'updated_at' => '']],
        ];
    }

    private static function touchBootstrapMeta(): void
    {
        $meta = RegistryService::getSystem('bootstrap_meta');
        $meta['data']['current_storage_schema_version'] = 2;
        $meta['data']['bootstrap_completed_at'] = $meta['data']['bootstrap_completed_at'] ?? date('c');
        $meta['data']['last_repair_at'] = date('c');
        RegistryService::putSystem('bootstrap_meta', $meta);
    }

    private static function defaultCoreFiles(): array
    {
        $base = DATA_PATH . '/platform/defaults/core/';
        return [
            $base . 'branding_defaults.json' => ['data' => ['company_name' => '', 'logo_path' => '', 'email' => '', 'phone' => '', 'address' => '', 'gst' => '', 'bank_details' => ['account_name' => '', 'account_number' => '', 'ifsc' => ''], 'footer_text' => 'Thank you for choosing us.', 'primary_color' => '#0b5fff', 'document_branding_enabled' => true], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . 'profile_defaults.json' => ['data' => ['owner_name' => '', 'company_name' => '', 'phone' => '', 'email' => '', 'address' => '', 'city' => '', 'state' => '', 'pincode' => '', 'gst_number' => '', 'website' => '', 'business_details' => ''], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . 'billing_defaults.json' => ['data' => ['current_plan_key' => 'growth', 'billing_cycle' => 'monthly', 'renewal_date' => '', 'trial_days_assigned' => 14, 'payment_history_summary' => [], 'currency' => 'INR', 'last_updated' => date('c')], 'meta' => ['version' => 1, 'updated_at' => '']],
        ];
    }

    private static function defaultPmSuryaGharFiles(): array
    {
        $base = DATA_PATH . '/platform/defaults/schemes/pm_surya_ghar/';
        $config = self::schemeConfigDefaults();
        return [
            $base . 'scheme_defaults.json' => ['data' => ['enabled' => true, 'default_currency' => 'INR', 'regional_assumptions' => ['state' => 'Gujarat']], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . 'workflow_definition.json' => ['data' => ['stages' => [
                ['key' => 'lead', 'label' => 'Lead', 'order' => 1, 'description' => 'Initial lead intake.'],
                ['key' => 'engagement', 'label' => 'Engagement', 'order' => 2, 'description' => 'Initial contact and qualification.'],
                ['key' => 'detailed_information_shared', 'label' => 'Detailed Information Shared', 'order' => 3, 'description' => 'Brochure and details provided.'],
                ['key' => 'solar_finance', 'label' => 'Solar Finance', 'order' => 4, 'description' => 'Finance assumptions aligned.'],
                ['key' => 'quotation', 'label' => 'Quotation', 'order' => 5, 'description' => 'Proposal prepared.'],
                ['key' => 'quotation_accepted', 'label' => 'Quotation Accepted', 'order' => 6, 'description' => 'Customer accepted proposal.'],
                ['key' => 'agreement', 'label' => 'Agreement', 'order' => 7, 'description' => 'Agreement finalized.'],
                ['key' => 'payment_receipt', 'label' => 'Payment Receipt', 'order' => 8, 'description' => 'Payment acknowledged.'],
                ['key' => 'invoice', 'label' => 'Invoice', 'order' => 9, 'description' => 'Invoice issued.'],
                ['key' => 'complaint_support', 'label' => 'Complaint Support', 'order' => 10, 'description' => 'After-sales support.'],
            ], 'allowed_transitions' => ['lead' => ['engagement']]], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . 'templates_defaults.json' => ['data' => $config['templates'], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . 'message_templates_defaults.json' => ['data' => $config['message_templates'], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . 'explainer_content_defaults.json' => ['data' => $config['explainer_content'], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . 'rate_chart_defaults.json' => ['data' => $config['rate_chart'], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . 'document_definition.json' => ['data' => $config['document_rules'], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . 'content_definition.json' => ['data' => $config['content_blocks'], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . 'calculation_defaults.json' => ['data' => $config['calculations'], 'meta' => ['version' => 1, 'updated_at' => '']],
        ];
    }

    private static function schemeConfigDefaults(): array
    {
        return [
            'templates' => ['template_sets' => ['standard_v1' => ['cover_notes' => 'Dear {{customer_name}}, thank you for your interest.', 'annexure_blocks' => ['System scope', 'Installation timeline']]], 'cover_notes' => [], 'annexure_blocks' => [], 'subsidy_info' => 'Subsidy may vary by policy updates.', 'milestones' => ['Survey', 'Approval', 'Install'], 'next_steps' => ['Confirm quotation', 'Share documents'], 'payment_terms' => '50% advance, 50% on completion', 'warranty' => 'As per OEM policy', 'transport' => 'Inclusive within city limits', 'terms_conditions' => ['Prices valid for 15 days']],
            'message_templates' => ['whatsapp_intro' => 'Hello {{customer_name}}, this is {{vendor_name}}.', 'email_intro_subject' => 'Welcome from {{vendor_name}}', 'email_intro_body' => 'Please review your details at {{quotation_link}}.', 'whatsapp_details' => 'Your PM Surya Ghar details are ready.', 'email_details_subject' => 'Your quotation details', 'email_details_body' => 'Dear {{customer_name}}, your quotation is ready at {{quotation_link}}.', 'placeholders' => ['{{customer_name}}', '{{vendor_name}}', '{{quotation_link}}']],
            'explainer_content' => ['page_title' => 'PM Surya Ghar Guide', 'hero_intro' => 'Understand subsidy-ready rooftop solar quickly.', 'scheme_explanation' => 'Government-backed rooftop adoption support.', 'eligibility' => ['Residential premises', 'Valid electricity bill'], 'on_grid_block' => 'On-grid systems reduce daytime bills.', 'hybrid_block' => 'Hybrid includes battery backup.', 'faqs' => [['q' => 'How long does installation take?', 'a' => 'Usually 2-4 weeks.']], 'benefits' => ['Bill savings', 'Clean energy'], 'expectations' => ['Site survey required'], 'cta' => 'Contact us to start.', 'media_urls' => []],
            'rate_chart' => ['on_grid_rates' => [['kw' => '1-3', 'price' => 62000]], 'hybrid_rates' => [['kw' => '3', 'price' => 98000]], 'self_funded_price' => 60000, 'loan_upto_2_lakh_price' => 64000, 'loan_above_2_lakh_price' => 67000, 'effective_from' => date('Y-m-d'), 'notes' => 'Demo values only.'],
            'calculations' => ['subsidy_assumptions' => ['max_subsidy' => 78000], 'finance_assumptions' => ['interest_rate' => 11.5], 'rate_assumptions' => ['inflation' => 3.5], 'payback_assumptions' => ['years' => 5], 'default_graphs_config' => ['show_savings_curve' => true]],
            'content_blocks' => ['sections' => [['key' => 'intro', 'label' => 'Introduction', 'enabled' => true]]],
            'document_rules' => ['naming_patterns' => ['quotation' => 'quotation_{quotation_id}_v1.html'], 'default_paper_size' => 'A4', 'printable_sections' => ['cover', 'pricing', 'terms'], 'snapshot_requirements' => ['branding', 'rate_chart', 'calculation_inputs'], 'required_branding_rules' => ['logo_optional' => true]],
        ];
    }

    private static function moduleSeeds(): array
    {
        $rows = [
            ['dashboard', 'Dashboard', 'core', 0, true], ['leads', 'Leads', 'scheme-specific', 499, false], ['customers', 'Customers', 'scheme-specific', 299, false], ['quotations', 'Quotations', 'scheme-specific', 899, false], ['solar-finance', 'Solar and Finance', 'scheme-specific', 1499, false], ['agreements', 'Agreements', 'scheme-specific', 399, false], ['payment-receipts', 'Payment Receipts', 'scheme-specific', 299, false], ['invoices', 'Invoices', 'scheme-specific', 499, false], ['complaints', 'Complaints', 'scheme-specific', 499, false], ['templates-media', 'Templates & Media', 'multi-scheme', 399, false], ['messaging-templates', 'Messaging Templates', 'multi-scheme', 199, false], ['explainer-content', 'Explainer Content', 'scheme-specific', 199, false], ['rate-chart', 'Rate Chart', 'scheme-specific', 299, false], ['reports-exports', 'Reports & Exports', 'multi-scheme', 299, false], ['company-branding', 'Company Profile & Branding', 'core', 0, true], ['subscription-billing', 'Subscription & Billing', 'core', 0, true], ['scheme-settings', 'Scheme Settings', 'scheme-specific', 199, false],
        ];
        $out = [];
        foreach ($rows as $i => $r) {
            [$key, $name, $scope, $price, $core] = $r;
            $out[] = ['module_id' => 'MOD-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'module_key' => $key, 'module_name' => $name, 'module_scope' => $scope, 'scheme_keys' => ['pm_surya_ghar'], 'description' => $name . ' module placeholder.', 'monthly_price' => $price, 'quarterly_price' => $price * 3, 'yearly_price' => $price * 12, 'optional_setup_fee' => 0, 'enabled_flag' => true, 'dependency_list' => [], 'visibility_rules' => [], 'config_json' => [], 'is_core_included' => $core, 'nav_label' => $name, 'nav_order' => $i + 1, 'created_at' => date('c'), 'updated_at' => date('c')];
        }
        return $out;
    }

    private static function planSeeds(): array
    {
        return [
            ['plan_id' => 'PLN-0001', 'plan_key' => 'basic', 'plan_name' => 'Basic Package', 'scheme_key' => 'pm_surya_ghar', 'included_modules' => ['leads','customers','quotations','messaging-templates','templates-media'], 'excluded_modules' => ['solar-finance','invoices','complaints'], 'monthly_price' => 1000, 'quarterly_price' => 2700, 'yearly_price' => 10000, 'trial_days' => 14, 'limits_json' => ['max_leads' => 100], 'recommended_flag' => false, 'active_flag' => true, 'description' => 'Starter bundle for new vendors.', 'created_at' => date('c'), 'updated_at' => date('c')],
            ['plan_id' => 'PLN-0002', 'plan_key' => 'growth', 'plan_name' => 'Growth Package', 'scheme_key' => 'pm_surya_ghar', 'included_modules' => ['leads','customers','quotations','messaging-templates','templates-media','solar-finance','agreements','payment-receipts','explainer-content','rate-chart','reports-exports','scheme-settings'], 'excluded_modules' => ['invoices','complaints'], 'monthly_price' => 3000, 'quarterly_price' => 8400, 'yearly_price' => 33000, 'trial_days' => 14, 'limits_json' => ['max_leads' => 500], 'recommended_flag' => true, 'active_flag' => true, 'description' => 'Most popular plan for growing vendors.', 'created_at' => date('c'), 'updated_at' => date('c')],
            ['plan_id' => 'PLN-0003', 'plan_key' => 'pro', 'plan_name' => 'Pro Package', 'scheme_key' => 'pm_surya_ghar', 'included_modules' => ['leads','customers','quotations','messaging-templates','templates-media','solar-finance','agreements','payment-receipts','explainer-content','rate-chart','reports-exports','scheme-settings','invoices','complaints'], 'excluded_modules' => [], 'monthly_price' => 5000, 'quarterly_price' => 14000, 'yearly_price' => 55000, 'trial_days' => 14, 'limits_json' => ['max_leads' => 2500], 'recommended_flag' => false, 'active_flag' => true, 'description' => 'Complete package with advanced modules.', 'created_at' => date('c'), 'updated_at' => date('c')],
        ];
    }
}

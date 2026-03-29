<?php

declare(strict_types=1);

namespace App\Services;

class SubscriptionService
{
    public static function normalizeAll(): void
    {
        ModuleService::normalizeRegistry();
        PlanService::normalizeRegistry();

        $subs = RegistryService::get('subscriptions');
        $normalized = [];
        foreach ($subs as $sub) {
            $normalized[] = self::hydrateSubscription($sub);
        }
        RegistryService::put('subscriptions', $normalized);

        $vendors = RegistryService::get('vendors');
        foreach ($vendors as &$vendor) {
            self::refreshVendorEntitlements($vendor);
        }
        unset($vendor);
        RegistryService::put('vendors', $vendors);
    }

    public static function hydrateSubscription(array $sub): array
    {
        $now = date('c');
        $sub['subscription_id'] = (string) ($sub['subscription_id'] ?? CounterService::next('subscription'));
        $sub['vendor_id'] = (string) ($sub['vendor_id'] ?? '');
        $sub['tenant_id'] = (string) ($sub['tenant_id'] ?? '');
        $sub['scheme_key'] = (string) ($sub['scheme_key'] ?? 'pm_surya_ghar');
        $mode = (string) ($sub['subscription_mode'] ?? 'plan');
        $sub['subscription_mode'] = in_array($mode, ['plan', 'modules', 'hybrid'], true) ? $mode : 'plan';
        $sub['plan_key'] = isset($sub['plan_key']) ? (string) $sub['plan_key'] : null;
        $sub['addon_module_keys'] = array_values(array_unique(array_filter((array) ($sub['addon_module_keys'] ?? []))));
        $sub['direct_module_keys'] = array_values(array_unique(array_filter((array) ($sub['direct_module_keys'] ?? []))));
        $sub['removed_module_keys'] = array_values(array_unique(array_filter((array) ($sub['removed_module_keys'] ?? []))));
        $sub['billing_cycle'] = BillingCycleService::normalize((string) ($sub['billing_cycle'] ?? 'monthly'));
        $sub['subscription_status'] = (string) ($sub['subscription_status'] ?? 'none');
        $sub['trial_days_assigned'] = (int) ($sub['trial_days_assigned'] ?? ($sub['trial_days'] ?? 0));
        $sub['started_at'] = (string) ($sub['started_at'] ?? $now);
        $sub['trial_started_at'] = $sub['trial_started_at'] ?? null;
        $sub['trial_ends_at'] = $sub['trial_ends_at'] ?? null;
        $sub['active_from'] = $sub['active_from'] ?? null;
        $sub['active_until'] = $sub['active_until'] ?? null;
        $sub['renewal_date'] = $sub['renewal_date'] ?? ($sub['trial_ends_at'] ?? $sub['active_until'] ?? null);
        $sub['cancelled_at'] = $sub['cancelled_at'] ?? null;
        $sub['override_pricing_json'] = (array) ($sub['override_pricing_json'] ?? []);
        $sub['override_modules_json'] = (array) ($sub['override_modules_json'] ?? []);
        $sub['plan_snapshot'] = (array) ($sub['plan_snapshot'] ?? []);
        $sub['module_snapshots'] = (array) ($sub['module_snapshots'] ?? []);
        $sub['price_breakdown'] = (array) ($sub['price_breakdown'] ?? []);
        $sub['source_type'] = (string) ($sub['source_type'] ?? 'system');
        $sub['source_ref'] = $sub['source_ref'] ?? null;
        $sub['created_at'] = (string) ($sub['created_at'] ?? $now);
        $sub['updated_at'] = $now;

        self::refreshDateBoundStatus($sub);
        $sub['entitled_modules'] = EntitlementService::resolve($sub);
        $plan = ($sub['plan_key'] ?? null) ? (RegistryService::getPlanByKey((string) $sub['plan_key']) ?? []) : [];
        if ($sub['plan_snapshot'] === []) {
            $sub['plan_snapshot'] = SubscriptionSnapshotService::planSnapshot($plan);
        }
        if ($sub['module_snapshots'] === []) {
            $sub['module_snapshots'] = SubscriptionSnapshotService::moduleSnapshots($sub['entitled_modules']);
        }
        $sub['price_breakdown'] = PricingService::buildPriceBreakdown($sub, $plan, $sub['entitled_modules'], ModuleService::allIndexed());
        return $sub;
    }

    public static function refreshDateBoundStatus(array &$sub): void
    {
        $now = strtotime(date('c'));
        if (($sub['subscription_status'] ?? '') === 'trial' && !empty($sub['trial_ends_at']) && strtotime((string) $sub['trial_ends_at']) < $now) {
            $sub['subscription_status'] = 'expired';
        }
        if (($sub['subscription_status'] ?? '') === 'active' && !empty($sub['active_until']) && strtotime((string) $sub['active_until']) < $now) {
            $sub['subscription_status'] = 'expired';
        }
    }

    public static function assignForVendor(array $vendor, array $input): array
    {
        $subs = RegistryService::get('subscriptions');
        $existing = RegistryService::getSubscriptionForVendor((string) $vendor['vendor_id']);
        $sub = $existing ?? [];

        $planKey = trim((string) ($input['plan_key'] ?? ($sub['plan_key'] ?? $vendor['current_plan_key'] ?? '')));
        $mode = (string) ($input['subscription_mode'] ?? ($sub['subscription_mode'] ?? 'plan'));
        $cycle = BillingCycleService::normalize((string) ($input['billing_cycle'] ?? ($sub['billing_cycle'] ?? 'monthly')));
        $trialDays = (int) ($input['trial_days_assigned'] ?? ($sub['trial_days_assigned'] ?? 0));
        $status = (string) ($input['subscription_status'] ?? ($sub['subscription_status'] ?? 'trial'));
        $now = date('c');

        $sub = array_merge($sub, [
            'subscription_id' => $sub['subscription_id'] ?? CounterService::next('subscription'),
            'vendor_id' => $vendor['vendor_id'],
            'tenant_id' => $vendor['tenant_id'],
            'scheme_key' => $vendor['default_scheme_key'] ?? 'pm_surya_ghar',
            'subscription_mode' => in_array($mode, ['plan', 'modules', 'hybrid'], true) ? $mode : 'plan',
            'plan_key' => $planKey !== '' ? $planKey : null,
            'addon_module_keys' => self::toKeyList($input['addon_module_keys'] ?? ($sub['addon_module_keys'] ?? [])),
            'direct_module_keys' => self::toKeyList($input['direct_module_keys'] ?? ($sub['direct_module_keys'] ?? [])),
            'removed_module_keys' => self::toKeyList($input['removed_module_keys'] ?? ($sub['removed_module_keys'] ?? [])),
            'billing_cycle' => $cycle,
            'subscription_status' => $status,
            'trial_days_assigned' => $trialDays,
            'started_at' => $sub['started_at'] ?? $now,
            'trial_started_at' => $sub['trial_started_at'] ?? null,
            'trial_ends_at' => $sub['trial_ends_at'] ?? null,
            'active_from' => $sub['active_from'] ?? null,
            'active_until' => $sub['active_until'] ?? null,
            'renewal_date' => $sub['renewal_date'] ?? null,
            'cancelled_at' => $sub['cancelled_at'] ?? null,
            'override_pricing_json' => self::normalizeJsonArray($input['override_pricing_json'] ?? ($sub['override_pricing_json'] ?? [])),
            'override_modules_json' => self::normalizeJsonArray($input['override_modules_json'] ?? ($sub['override_modules_json'] ?? [])),
            'source_type' => (string) ($input['source_type'] ?? ($sub['source_type'] ?? 'admin')),
            'source_ref' => $input['source_ref'] ?? ($sub['source_ref'] ?? null),
            'created_at' => $sub['created_at'] ?? $now,
            'updated_at' => $now,
        ]);

        if ($sub['subscription_status'] === 'trial') {
            $sub['trial_started_at'] = $sub['trial_started_at'] ?? $now;
            $sub['trial_ends_at'] = date('c', strtotime('+' . max(0, $trialDays) . ' days', strtotime((string) $sub['trial_started_at'])));
            $sub['renewal_date'] = $sub['trial_ends_at'];
            $sub['active_from'] = null;
            $sub['active_until'] = null;
            $sub['cancelled_at'] = null;
        } elseif ($sub['subscription_status'] === 'active') {
            $sub['active_from'] = $input['active_from'] ?? ($sub['active_from'] ?? $now);
            $sub['active_until'] = $input['active_until'] ?? BillingCycleService::addToDate($sub['billing_cycle'], (string) $sub['active_from']);
            $sub['renewal_date'] = $sub['active_until'];
            $sub['cancelled_at'] = null;
        } elseif ($sub['subscription_status'] === 'cancelled') {
            $sub['cancelled_at'] = $input['cancelled_at'] ?? $now;
        }

        $sub = self::hydrateSubscription($sub);

        if ($existing) {
            foreach ($subs as &$row) {
                if (($row['subscription_id'] ?? '') === $existing['subscription_id']) {
                    $row = $sub;
                    break;
                }
            }
            unset($row);
        } else {
            $subs[] = $sub;
        }
        RegistryService::put('subscriptions', $subs);

        self::refreshVendorBySubscription($vendor['vendor_id'], $sub);
        return $sub;
    }

    public static function refreshVendorBySubscription(string $vendorId, ?array $sub = null): void
    {
        $vendors = RegistryService::get('vendors');
        foreach ($vendors as &$vendor) {
            if (($vendor['vendor_id'] ?? '') !== $vendorId) {
                continue;
            }
            if ($sub === null) {
                self::refreshVendorEntitlements($vendor);
            } else {
                $vendor['current_plan_key'] = $sub['plan_key'] ?? null;
                $vendor['enabled_modules'] = $sub['entitled_modules'] ?? [];
                $vendor['subscription_status'] = $sub['subscription_status'] ?? 'none';
                $vendor['updated_at'] = date('c');
                self::writeTenantConvenienceFiles($vendor, $sub);
            }
            break;
        }
        unset($vendor);
        RegistryService::put('vendors', $vendors);
    }

    public static function refreshVendorEntitlements(array &$vendor): void
    {
        $sub = RegistryService::getSubscriptionForVendor((string) $vendor['vendor_id']);
        if (!$sub) {
            return;
        }
        $sub = self::hydrateSubscription($sub);

        $all = RegistryService::get('subscriptions');
        foreach ($all as &$row) {
            if (($row['subscription_id'] ?? '') === $sub['subscription_id']) {
                $row = $sub;
                break;
            }
        }
        unset($row);
        RegistryService::put('subscriptions', $all);

        $vendor['current_plan_key'] = $sub['plan_key'] ?? null;
        $vendor['enabled_modules'] = $sub['entitled_modules'] ?? [];
        $vendor['subscription_status'] = $sub['subscription_status'] ?? 'none';
        $vendor['updated_at'] = date('c');
        self::writeTenantConvenienceFiles($vendor, $sub);
    }

    public static function writeTenantConvenienceFiles(array $vendor, array $sub): void
    {
        $tenantPath = TenantStorageService::getTenantPath((string) $vendor['tenant_id']);
        RegistryService::putConfigData($tenantPath . '/shared/enabled_modules.json', ['keys' => $sub['entitled_modules'] ?? []]);
        RegistryService::putConfigData($tenantPath . '/shared/enabled_schemes.json', ['keys' => $vendor['enabled_schemes'] ?? []]);
        RegistryService::putConfigData($tenantPath . '/shared/subscription_snapshot.json', [
            'current_plan' => $sub['plan_snapshot']['plan_name'] ?? $sub['plan_key'] ?? null,
            'current_plan_key' => $sub['plan_key'] ?? null,
            'mode' => $sub['subscription_mode'] ?? 'plan',
            'cycle' => $sub['billing_cycle'] ?? 'monthly',
            'status' => $sub['subscription_status'] ?? 'none',
            'trial_dates' => ['start' => $sub['trial_started_at'] ?? null, 'end' => $sub['trial_ends_at'] ?? null],
            'active_dates' => ['from' => $sub['active_from'] ?? null, 'until' => $sub['active_until'] ?? null],
            'renewal_date' => $sub['renewal_date'] ?? null,
            'entitled_modules' => $sub['entitled_modules'] ?? [],
            'price_breakdown' => $sub['price_breakdown'] ?? [],
            'updated_at' => date('c'),
        ]);
        RegistryService::putConfigData($tenantPath . '/shared/billing.json', [
            'current_plan_key' => $sub['plan_key'] ?? null,
            'billing_cycle' => $sub['billing_cycle'] ?? 'monthly',
            'renewal_date' => $sub['renewal_date'] ?? null,
            'trial_days_assigned' => $sub['trial_days_assigned'] ?? 0,
            'payment_history_summary' => [],
            'currency' => 'INR',
            'last_updated' => date('c'),
        ]);
        RegistryService::putConfigData($tenantPath . '/meta/entitlement_cache.json', [
            'enabled_scheme_keys' => $vendor['enabled_schemes'] ?? [],
            'enabled_module_keys' => $sub['entitled_modules'] ?? [],
            'subscription_status' => $sub['subscription_status'] ?? 'none',
            'current_plan_key' => $sub['plan_key'] ?? null,
            'current_mode' => $sub['subscription_mode'] ?? 'plan',
            'renewal_date' => $sub['renewal_date'] ?? null,
            'updated_at' => date('c'),
        ]);
    }

    private static function toKeyList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/\s*,\s*/', trim($value)) ?: [];
        }
        return array_values(array_unique(array_filter((array) $value)));
    }

    private static function normalizeJsonArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }
}

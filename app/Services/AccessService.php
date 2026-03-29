<?php

declare(strict_types=1);

namespace App\Services;

class AccessService
{
    public static function evaluateVendorAccess(array $vendor): array
    {
        $subscription = RegistryService::getSubscriptionForVendor((string) ($vendor['vendor_id'] ?? ''));
        if ($subscription) {
            $subscription = SubscriptionService::hydrateSubscription($subscription);
            SubscriptionService::refreshVendorBySubscription((string) $vendor['vendor_id'], $subscription);
            $vendor = RegistryService::getVendorById((string) $vendor['vendor_id']) ?? $vendor;
        }

        $subscriptionStatus = (string) ($subscription['subscription_status'] ?? ($vendor['subscription_status'] ?? 'none'));
        $enabledSchemes = array_values(array_filter((array) ($vendor['enabled_schemes'] ?? [])));
        $enabledModules = array_values(array_filter((array) ($subscription['entitled_modules'] ?? ($vendor['enabled_modules'] ?? []))));

        $result = [
            'is_allowed' => false,
            'verification_status' => (string) ($vendor['verification_status'] ?? 'pending'),
            'account_status' => (string) ($vendor['account_status'] ?? 'inactive'),
            'subscription_status' => $subscriptionStatus,
            'enabled_scheme_keys' => $enabledSchemes,
            'enabled_module_keys' => $enabledModules,
            'default_scheme_key' => (string) ($vendor['default_scheme_key'] ?? ''),
            'current_plan_key' => (string) ($subscription['plan_key'] ?? ($vendor['current_plan_key'] ?? '')),
            'blocked_reason_code' => null,
            'blocked_message' => null,
        ];

        if ($result['verification_status'] !== 'verified') {
            return self::blocked($result, $result['verification_status'] === 'rejected' ? 'rejected' : 'pending');
        }

        if (in_array($result['account_status'], ['inactive', 'suspended', 'cancelled'], true)) {
            return self::blocked($result, $result['account_status']);
        }

        if (!in_array($subscriptionStatus, ['trial', 'active'], true)) {
            $reason = match ($subscriptionStatus) {
                'expired' => 'expired',
                'cancelled' => 'subscription_cancelled',
                default => 'no_subscription',
            };
            return self::blocked($result, $reason);
        }

        if ($enabledSchemes === []) {
            return self::blocked($result, 'no_scheme_access');
        }

        $result['is_allowed'] = true;
        return $result;
    }

    public static function canAccessVendorWorkspace(array $vendor): bool
    {
        return self::evaluateVendorAccess($vendor)['is_allowed'] === true;
    }

    public static function hasSchemeAccess(array $vendor, string $schemeKey): bool
    {
        if (!self::canAccessVendorWorkspace($vendor)) {
            return false;
        }

        $scheme = RegistryService::getSchemeByKey($schemeKey);
        if (!$scheme || empty($scheme['active_flag'])) {
            return false;
        }

        return in_array($schemeKey, (array) ($vendor['enabled_schemes'] ?? []), true);
    }

    public static function hasModuleAccess(array $vendor, string $moduleKey, ?string $schemeKey = null): bool
    {
        if (!self::canAccessVendorWorkspace($vendor)) {
            return false;
        }

        $module = RegistryService::getModuleByKey($moduleKey);
        if (!$module || empty($module['enabled_flag'])) {
            return false;
        }

        if ($schemeKey !== null && !self::hasSchemeAccess($vendor, $schemeKey)) {
            return false;
        }

        $freshVendor = RegistryService::getVendorById((string) ($vendor['vendor_id'] ?? '')) ?? $vendor;
        return in_array($moduleKey, (array) ($freshVendor['enabled_modules'] ?? []), true);
    }

    public static function blockedMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'pending' => 'Your account signup has been received and is awaiting superadmin verification.',
            'rejected' => 'Your signup was not approved. Please contact support for clarification.',
            'inactive' => 'Your account is inactive. Please contact support or the platform administrator.',
            'suspended' => 'Your account is currently suspended. Please contact support or the platform administrator.',
            'cancelled' => 'Your account is no longer active.',
            'expired' => 'Your subscription/trial has expired. Please contact support to reactivate access.',
            'subscription_cancelled' => 'Your subscription is cancelled and workspace access is blocked.',
            'no_subscription' => 'Your account does not have an active subscription yet.',
            'no_scheme_access' => 'Your account does not currently have access to any enabled scheme.',
            default => 'You do not have access to this section.',
        };
    }

    private static function blocked(array $result, string $reason): array
    {
        $result['blocked_reason_code'] = $reason;
        $result['blocked_message'] = self::blockedMessage($reason);
        return $result;
    }
}

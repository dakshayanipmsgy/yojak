<?php

declare(strict_types=1);

namespace App\Services;

class AccessService
{
    public static function isVendorVerified(array $vendor): bool
    {
        return ($vendor['verification_status'] ?? '') === 'verified';
    }

    public static function isVendorActive(array $vendor): bool
    {
        return ($vendor['account_status'] ?? '') === 'active';
    }

    public static function isSubscriptionAllowed(array $vendor): bool
    {
        $sub = RegistryService::getActiveSubscriptionForVendor((string) ($vendor['vendor_id'] ?? ''));
        return $sub !== null;
    }

    public static function hasSchemeAccess(array $vendor, string $schemeKey): bool
    {
        return in_array($schemeKey, $vendor['enabled_schemes'] ?? [], true);
    }

    public static function hasModuleAccess(array $vendor, string $moduleKey): bool
    {
        return in_array($moduleKey, $vendor['enabled_modules'] ?? [], true) || in_array($moduleKey, ['dashboard', 'company-branding', 'subscription-billing'], true);
    }

    public static function canAccessVendorWorkspace(array $vendor): bool
    {
        return self::isVendorVerified($vendor)
            && self::isVendorActive($vendor)
            && self::isSubscriptionAllowed($vendor)
            && !empty($vendor['enabled_schemes']);
    }
}

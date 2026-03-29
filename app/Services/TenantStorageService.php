<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\JsonStorage;

class TenantStorageService
{
    public static function getTenantPath(string $tenantId): string
    {
        return DATA_PATH . '/tenants/tenant_' . $tenantId;
    }

    public static function getTenantSchemePath(string $tenantId, string $schemeKey): string
    {
        return self::getTenantPath($tenantId) . '/schemes/' . $schemeKey;
    }

    public static function getTenantMeta(string $tenantId): array
    {
        return RegistryService::getConfigData(self::getTenantPath($tenantId) . '/meta/tenant_meta.json');
    }

    public static function getTenantProfile(string $tenantId): array { return RegistryService::getConfigData(self::getTenantPath($tenantId) . '/shared/profile.json'); }
    public static function getTenantBranding(string $tenantId): array { return RegistryService::getConfigData(self::getTenantPath($tenantId) . '/shared/branding.json'); }
    public static function getTenantEnabledSchemes(string $tenantId): array { return RegistryService::getConfigData(self::getTenantPath($tenantId) . '/shared/enabled_schemes.json')['keys'] ?? []; }
    public static function getTenantEnabledModules(string $tenantId): array { return RegistryService::getConfigData(self::getTenantPath($tenantId) . '/shared/enabled_modules.json')['keys'] ?? []; }
    public static function getTenantSubscriptionSnapshot(string $tenantId): array { return RegistryService::getConfigData(self::getTenantPath($tenantId) . '/shared/subscription_snapshot.json'); }

    public static function getTenantSchemeMeta(string $tenantId, string $schemeKey): array
    {
        return RegistryService::getConfigData(self::getTenantSchemePath($tenantId, $schemeKey) . '/scheme_meta.json');
    }

    public static function getTenantSchemeSettings(string $tenantId, string $schemeKey): array
    {
        return RegistryService::getConfigData(self::getTenantSchemePath($tenantId, $schemeKey) . '/settings.json');
    }

    public static function getTenantSchemeConfig(string $tenantId, string $schemeKey, string $configKey): array
    {
        return RegistryService::getConfigData(self::getTenantSchemePath($tenantId, $schemeKey) . '/config/' . $configKey . '.json');
    }

    public static function getTenantSchemeRecords(string $tenantId, string $schemeKey, string $recordType): array
    {
        $path = self::getTenantSchemePath($tenantId, $schemeKey) . '/records/' . $recordType . '.json';
        $payload = JsonStorage::read($path, ['items' => [], 'meta' => ['version' => 1, 'updated_at' => '', 'next_hint' => null]]);
        return $payload['items'] ?? [];
    }
}

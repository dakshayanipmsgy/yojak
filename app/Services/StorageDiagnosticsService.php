<?php

declare(strict_types=1);

namespace App\Services;

class StorageDiagnosticsService
{
    /**
     * @return array{checks:array<int,array<string,mixed>>,missing_count:int,invalid_count:int}
     */
    public static function platformSummary(bool $repair = false): array
    {
        $checks = [];
        $checks = array_merge($checks, self::validatePaths([
            DATA_PATH,
            DATA_PATH . '/platform/registries',
            DATA_PATH . '/platform/system',
            DATA_PATH . '/platform/defaults/core',
            DATA_PATH . '/platform/defaults/schemes/pm_surya_ghar',
            DATA_PATH . '/tenants',
        ], true, $repair));

        foreach (['schemes', 'modules', 'plans', 'vendors', 'pending_signups', 'subscriptions', 'superadmin_accounts', 'superadmin_settings'] as $registry) {
            $checks[] = self::validateJsonFile(RegistryService::registryFile($registry), ['items' => [], 'meta' => ['version' => 1, 'updated_at' => '']], $repair);
        }
        foreach (['counters', 'audit_log', 'bootstrap_meta', 'migrations'] as $systemKey) {
            $defaults = $systemKey === 'counters'
                ? BootstrapService::defaultCounters()
                : ($systemKey === 'audit_log'
                    ? ['entries' => [], 'meta' => ['version' => 1, 'updated_at' => '']]
                    : ['data' => [], 'meta' => ['version' => 1, 'updated_at' => '']]);
            $checks[] = self::validateJsonFile(RegistryService::systemFile($systemKey), $defaults, $repair);
        }

        return self::summarize($checks);
    }

    /**
     * @return array{checks:array<int,array<string,mixed>>,missing_count:int,invalid_count:int}
     */
    public static function tenantSummary(bool $repair = false): array
    {
        $checks = [];
        foreach (RegistryService::get('vendors') as $vendor) {
            $tenantId = (string) ($vendor['tenant_id'] ?? '');
            if ($tenantId === '') {
                continue;
            }
            if ($repair) {
                BootstrapService::repairTenantStorage($tenantId);
            }
            $checks = array_merge($checks, self::tenantChecks($tenantId));
        }
        return self::summarize($checks);
    }

    private static function tenantChecks(string $tenantId): array
    {
        $base = TenantStorageService::getTenantPath($tenantId);
        $checks = self::validatePaths([
            $base,
            $base . '/meta',
            $base . '/shared',
            $base . '/schemes/pm_surya_ghar/config',
            $base . '/schemes/pm_surya_ghar/records',
            $base . '/schemes/pm_surya_ghar/indexes',
            $base . '/schemes/pm_surya_ghar/snapshots/quotations',
            $base . '/schemes/pm_surya_ghar/snapshots/agreements',
            $base . '/schemes/pm_surya_ghar/snapshots/receipts',
            $base . '/schemes/pm_surya_ghar/snapshots/invoices',
            $base . '/schemes/pm_surya_ghar/documents/quotations',
            $base . '/schemes/pm_surya_ghar/documents/agreements',
            $base . '/schemes/pm_surya_ghar/documents/receipts',
            $base . '/schemes/pm_surya_ghar/documents/invoices',
        ], true, false);

        foreach ([
            $base . '/meta/tenant_meta.json' => ['data' => [], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . '/meta/vendor_link.json' => ['data' => [], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . '/shared/enabled_schemes.json' => ['data' => ['keys' => []], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . '/shared/enabled_modules.json' => ['data' => ['keys' => []], 'meta' => ['version' => 1, 'updated_at' => '']],
            $base . '/schemes/pm_surya_ghar/settings.json' => ['data' => [], 'meta' => ['version' => 1, 'updated_at' => '']],
        ] as $path => $default) {
            $checks[] = self::validateJsonFile($path, $default, false);
        }

        foreach (['leads', 'customers', 'solar_finance', 'quotations', 'agreements', 'receipts', 'invoices', 'complaints', 'reports'] as $recordType) {
            $checks[] = self::validateJsonFile($base . '/schemes/pm_surya_ghar/records/' . $recordType . '.json', ['items' => [], 'meta' => ['version' => 1, 'updated_at' => '', 'next_hint' => null]], false);
        }
        foreach (['lead', 'customer', 'solar_finance', 'quotation', 'agreement', 'receipt', 'invoice', 'complaint', 'report'] as $indexType) {
            $checks[] = self::validateJsonFile($base . '/schemes/pm_surya_ghar/indexes/' . $indexType . '_index.json', ['by_id' => [], 'by_status' => [], 'by_date' => [], 'meta' => ['version' => 1, 'updated_at' => '']], false);
        }

        return $checks;
    }

    private static function validatePaths(array $paths, bool $isDirectory, bool $repair): array
    {
        $checks = [];
        foreach ($paths as $path) {
            $exists = $isDirectory ? is_dir($path) : file_exists($path);
            if (!$exists && $repair) {
                if ($isDirectory) {
                    \App\Core\JsonStorage::ensureDir($path);
                }
                $exists = $isDirectory ? is_dir($path) : file_exists($path);
            }
            $checks[] = ['type' => $isDirectory ? 'dir' : 'path', 'path' => $path, 'status' => $exists ? 'ok' : 'missing'];
        }
        return $checks;
    }

    private static function validateJsonFile(string $path, array $default, bool $repair): array
    {
        if (!file_exists($path)) {
            if ($repair) {
                \App\Core\JsonStorage::ensureFile($path, $default);
            }
            return ['type' => 'json', 'path' => $path, 'status' => file_exists($path) ? 'ok' : 'missing'];
        }

        $raw = (string) @file_get_contents($path);
        json_decode($raw, true);
        $valid = json_last_error() === JSON_ERROR_NONE;
        if (!$valid && $repair) {
            \App\Core\JsonStorage::write($path, $default);
            $valid = true;
        }
        return ['type' => 'json', 'path' => $path, 'status' => $valid ? 'ok' : 'invalid_json'];
    }

    private static function summarize(array $checks): array
    {
        return [
            'checks' => $checks,
            'missing_count' => count(array_filter($checks, static fn(array $row): bool => ($row['status'] ?? '') === 'missing')),
            'invalid_count' => count(array_filter($checks, static fn(array $row): bool => ($row['status'] ?? '') === 'invalid_json')),
        ];
    }
}

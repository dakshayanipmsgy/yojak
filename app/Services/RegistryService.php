<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\JsonStorage;

class RegistryService
{
    private const REGISTRY_PATTERN = ['items' => [], 'meta' => ['version' => 1, 'updated_at' => '']];
    private const CONFIG_PATTERN = ['data' => [], 'meta' => ['version' => 1, 'updated_at' => '']];
    private const LOG_PATTERN = ['entries' => [], 'meta' => ['version' => 1, 'updated_at' => '']];

    public static function registryFile(string $name): string
    {
        return DATA_PATH . '/platform/registries/' . $name . '.json';
    }

    public static function systemFile(string $name): string
    {
        return DATA_PATH . '/platform/system/' . $name . '.json';
    }

    public static function get(string $name): array
    {
        $payload = JsonStorage::read(self::registryFile($name), self::REGISTRY_PATTERN);
        return self::normalizeRegistryPayload($payload)['items'];
    }

    public static function put(string $name, array $items): void
    {
        $payload = self::normalizeRegistryPayload(JsonStorage::read(self::registryFile($name), self::REGISTRY_PATTERN));
        $payload['items'] = $items;
        JsonStorage::write(self::registryFile($name), JsonStorage::touchMeta($payload));
    }

    public static function getConfigData(string $path): array
    {
        $payload = JsonStorage::read($path, self::CONFIG_PATTERN);
        if (!isset($payload['data']) || !is_array($payload['data'])) {
            $payload = ['data' => is_array($payload) ? $payload : [], 'meta' => ['version' => 1, 'updated_at' => '']];
        }
        return $payload['data'];
    }

    public static function putConfigData(string $path, array $data): void
    {
        $payload = ['data' => $data, 'meta' => ['version' => 1, 'updated_at' => '']];
        JsonStorage::write($path, JsonStorage::touchMeta($payload));
    }

    public static function getSystem(string $name): array
    {
        $defaults = match ($name) {
            'counters' => BootstrapService::defaultCounters(),
            'audit_log' => self::LOG_PATTERN,
            'bootstrap_meta' => self::CONFIG_PATTERN,
            'migrations' => ['data' => ['storage_version' => 2, 'applied_migrations' => []], 'meta' => ['version' => 1, 'updated_at' => '']],
            default => self::CONFIG_PATTERN,
        };

        $payload = JsonStorage::read(self::systemFile($name), $defaults);

        if ($name === 'audit_log') {
            if (!isset($payload['entries']) || !is_array($payload['entries'])) {
                $payload['entries'] = [];
            }
            return $payload;
        }

        if ($name === 'counters') {
            if (!isset($payload['counters']) || !is_array($payload['counters'])) {
                $payload = BootstrapService::defaultCounters();
            }
            return $payload;
        }

        if (!isset($payload['data']) || !is_array($payload['data'])) {
            $payload['data'] = [];
        }
        return $payload;
    }

    public static function putSystem(string $name, array $payload): void
    {
        JsonStorage::write(self::systemFile($name), JsonStorage::touchMeta($payload));
    }

    public static function appendSystemLog(string $name, array $entry): void
    {
        $payload = self::getSystem($name);
        $payload['entries'][] = $entry;
        self::putSystem($name, $payload);
    }

    public static function findBy(array $records, string $key, mixed $value): ?array
    {
        foreach ($records as $record) {
            if (($record[$key] ?? null) === $value) {
                return $record;
            }
        }
        return null;
    }

    public static function getAllSchemes(): array { return self::get('schemes'); }
    public static function getSchemeByKey(string $schemeKey): ?array { return self::findBy(self::getAllSchemes(), 'scheme_key', $schemeKey); }
    public static function getAllModules(): array { return self::get('modules'); }
    public static function getModuleByKey(string $moduleKey): ?array { return self::findBy(self::getAllModules(), 'module_key', $moduleKey); }
    public static function getModulesForScheme(string $schemeKey): array
    {
        return array_values(array_filter(self::getAllModules(), fn(array $m): bool => in_array($schemeKey, $m['scheme_keys'] ?? [], true) || ($m['module_scope'] ?? '') === 'core'));
    }
    public static function getAllPlans(): array { return self::get('plans'); }
    public static function getPlanByKey(string $planKey): ?array { return self::findBy(self::getAllPlans(), 'plan_key', $planKey); }
    public static function getVendorById(string $vendorId): ?array { return self::findBy(self::get('vendors'), 'vendor_id', $vendorId); }
    public static function getVendorByEmail(string $email): ?array { return self::findBy(self::get('vendors'), 'email', strtolower($email)); }
    public static function getPendingSignupById(string $signupId): ?array { return self::findBy(self::get('pending_signups'), 'signup_id', $signupId); }

    public static function getVendorByMobile(string $mobile): ?array
    {
        $normalized = preg_replace('/\D+/', '', $mobile) ?? '';
        foreach (self::get('vendors') as $vendor) {
            $rowMobile = preg_replace('/\D+/', '', (string) ($vendor['mobile'] ?? '')) ?? '';
            if ($rowMobile !== '' && $rowMobile === $normalized) {
                return $vendor;
            }
        }
        return null;
    }

    public static function getPendingSignupByEmailOrMobile(string $identifier): ?array
    {
        $email = strtolower(trim($identifier));
        $mobile = preg_replace('/\D+/', '', $identifier) ?? '';
        foreach (self::get('pending_signups') as $row) {
            $rowEmail = strtolower((string) ($row['email'] ?? ''));
            $rowMobile = preg_replace('/\D+/', '', (string) ($row['mobile'] ?? '')) ?? '';
            if (($email !== '' && $rowEmail === $email) || ($mobile !== '' && $rowMobile !== '' && $rowMobile === $mobile)) {
                return $row;
            }
        }
        return null;
    }

    public static function getSubscriptionForVendor(string $vendorId): ?array
    {
        $subs = array_values(array_filter(self::get('subscriptions'), fn(array $s): bool => ($s['vendor_id'] ?? '') === $vendorId));
        if ($subs === []) {
            return null;
        }

        usort($subs, fn(array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        return $subs[0];
    }
    public static function getActiveSubscriptionForVendor(string $vendorId): ?array
    {
        $subs = array_filter(self::get('subscriptions'), fn(array $s): bool => ($s['vendor_id'] ?? '') === $vendorId && in_array($s['subscription_status'] ?? '', ['trial', 'active'], true));
        return array_values($subs)[0] ?? null;
    }

    private static function normalizeRegistryPayload(array $payload): array
    {
        if (array_is_list($payload)) {
            return ['items' => $payload, 'meta' => ['version' => 1, 'updated_at' => '']];
        }
        if (!isset($payload['items']) || !is_array($payload['items'])) {
            $payload['items'] = [];
        }
        if (!isset($payload['meta']) || !is_array($payload['meta'])) {
            $payload['meta'] = ['version' => 1, 'updated_at' => ''];
        }
        return $payload;
    }
}

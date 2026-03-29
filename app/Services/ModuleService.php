<?php

declare(strict_types=1);

namespace App\Services;

class ModuleService
{
    public static function coreIncludedKeys(): array
    {
        $keys = [];
        foreach (RegistryService::getAllModules() as $module) {
            if (!empty($module['is_core_included']) && !empty($module['enabled_flag'])) {
                $keys[] = (string) ($module['module_key'] ?? '');
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    public static function allIndexed(): array
    {
        $out = [];
        foreach (self::normalizedAll() as $row) {
            $out[$row['module_key']] = $row;
        }
        return $out;
    }

    public static function normalizedAll(): array
    {
        $rows = RegistryService::getAllModules();
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizeModule($row);
        }
        return $out;
    }

    public static function normalizeRegistry(): void
    {
        RegistryService::put('modules', self::normalizedAll());
    }

    public static function normalizeModule(array $row): array
    {
        $row['module_id'] = (string) ($row['module_id'] ?? CounterService::next('module'));
        $row['module_key'] = (string) ($row['module_key'] ?? '');
        $row['module_name'] = (string) ($row['module_name'] ?? ucfirst(str_replace('-', ' ', $row['module_key'])));
        $row['module_scope'] = (string) ($row['module_scope'] ?? 'scheme-specific');
        $row['scheme_keys'] = array_values(array_unique(array_filter((array) ($row['scheme_keys'] ?? ['pm_surya_ghar']))));
        $row['description'] = (string) ($row['description'] ?? '');
        $row['monthly_price'] = (float) ($row['monthly_price'] ?? 0);
        $row['quarterly_price'] = isset($row['quarterly_price']) && $row['quarterly_price'] !== '' ? (float) $row['quarterly_price'] : ($row['monthly_price'] * 3);
        $row['yearly_price'] = isset($row['yearly_price']) && $row['yearly_price'] !== '' ? (float) $row['yearly_price'] : ($row['monthly_price'] * 12);
        $row['optional_setup_fee'] = (float) ($row['optional_setup_fee'] ?? 0);
        $row['enabled_flag'] = !isset($row['enabled_flag']) ? true : (bool) $row['enabled_flag'];
        $row['dependency_list'] = array_values(array_unique(array_filter((array) ($row['dependency_list'] ?? []))));
        $row['visibility_rules'] = (array) ($row['visibility_rules'] ?? []);
        $row['config_json'] = (array) ($row['config_json'] ?? []);
        $row['is_core_included'] = !empty($row['is_core_included']);
        $row['nav_label'] = (string) ($row['nav_label'] ?? $row['module_name']);
        $row['nav_order'] = (int) ($row['nav_order'] ?? 99);
        $row['created_at'] = (string) ($row['created_at'] ?? date('c'));
        $row['updated_at'] = date('c');
        return $row;
    }

    public static function moduleAllowedForScheme(array $module, string $schemeKey): bool
    {
        if (($module['module_scope'] ?? '') === 'core') {
            return true;
        }

        return in_array($schemeKey, (array) ($module['scheme_keys'] ?? []), true);
    }
}

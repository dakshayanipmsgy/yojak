<?php

declare(strict_types=1);

namespace App\Services;

class EntitlementService
{
    public static function resolve(array $subscription): array
    {
        $schemeKey = (string) ($subscription['scheme_key'] ?? 'pm_surya_ghar');
        $plan = [];
        $planKey = (string) ($subscription['plan_key'] ?? '');
        if ($planKey !== '') {
            $plan = RegistryService::getPlanByKey($planKey) ?? [];
        }

        $moduleIndex = ModuleService::allIndexed();
        $keys = [];
        $keys = array_merge($keys, ModuleService::coreIncludedKeys());
        $keys = array_merge($keys, (array) ($plan['included_modules'] ?? []));
        $mode = (string) ($subscription['subscription_mode'] ?? 'plan');
        if ($mode === 'modules') {
            $keys = array_merge($keys, (array) ($subscription['direct_module_keys'] ?? []));
        }
        if ($mode === 'hybrid') {
            $keys = array_merge($keys, (array) ($subscription['addon_module_keys'] ?? []));
        }

        $removed = array_flip((array) ($subscription['removed_module_keys'] ?? []));
        $resolved = [];
        $visiting = [];
        foreach (array_values(array_unique(array_filter($keys))) as $key) {
            self::resolveDependencyTree($key, $moduleIndex, $schemeKey, $removed, $resolved, $visiting);
        }

        ksort($resolved);
        return array_keys($resolved);
    }

    private static function resolveDependencyTree(string $key, array $moduleIndex, string $schemeKey, array $removed, array &$resolved, array &$visiting): void
    {
        if (isset($removed[$key]) || isset($resolved[$key])) {
            return;
        }
        if (isset($visiting[$key]) || !isset($moduleIndex[$key])) {
            return;
        }

        $module = $moduleIndex[$key];
        if (empty($module['enabled_flag']) || !ModuleService::moduleAllowedForScheme($module, $schemeKey)) {
            return;
        }

        $visiting[$key] = true;
        foreach ((array) ($module['dependency_list'] ?? []) as $dep) {
            self::resolveDependencyTree((string) $dep, $moduleIndex, $schemeKey, $removed, $resolved, $visiting);
        }
        unset($visiting[$key]);
        $resolved[$key] = true;
    }
}

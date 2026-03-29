<?php

declare(strict_types=1);

namespace App\Services;

class SubscriptionSnapshotService
{
    public static function planSnapshot(array $plan): array
    {
        if ($plan === []) {
            return [];
        }

        return [
            'plan_key' => $plan['plan_key'] ?? null,
            'plan_name' => $plan['plan_name'] ?? null,
            'included_modules' => $plan['included_modules'] ?? [],
            'monthly_price' => $plan['monthly_price'] ?? 0,
            'quarterly_price' => $plan['quarterly_price'] ?? 0,
            'yearly_price' => $plan['yearly_price'] ?? 0,
            'trial_days' => $plan['trial_days'] ?? 0,
            'captured_at' => date('c'),
        ];
    }

    public static function moduleSnapshots(array $moduleKeys): array
    {
        $index = ModuleService::allIndexed();
        $out = [];
        foreach (array_values(array_unique($moduleKeys)) as $key) {
            if (!isset($index[$key])) {
                continue;
            }
            $module = $index[$key];
            $out[] = [
                'module_key' => $module['module_key'],
                'module_name' => $module['module_name'],
                'monthly_price' => $module['monthly_price'],
                'quarterly_price' => $module['quarterly_price'],
                'yearly_price' => $module['yearly_price'],
                'setup_fee' => $module['optional_setup_fee'] ?? 0,
                'captured_at' => date('c'),
            ];
        }
        return $out;
    }
}

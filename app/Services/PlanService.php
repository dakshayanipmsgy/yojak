<?php

declare(strict_types=1);

namespace App\Services;

class PlanService
{
    public static function normalizedAll(): array
    {
        $rows = RegistryService::getAllPlans();
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::normalizePlan($row);
        }
        return $out;
    }

    public static function normalizeRegistry(): void
    {
        RegistryService::put('plans', self::normalizedAll());
    }

    public static function normalizePlan(array $row): array
    {
        $row['plan_id'] = (string) ($row['plan_id'] ?? CounterService::next('plan'));
        $row['plan_key'] = (string) ($row['plan_key'] ?? '');
        $row['plan_name'] = (string) ($row['plan_name'] ?? ucfirst($row['plan_key']));
        $row['scheme_key'] = (string) ($row['scheme_key'] ?? 'pm_surya_ghar');
        $row['included_modules'] = array_values(array_unique(array_filter((array) ($row['included_modules'] ?? []))));
        $row['excluded_modules'] = array_values(array_unique(array_filter((array) ($row['excluded_modules'] ?? []))));
        $row['monthly_price'] = (float) ($row['monthly_price'] ?? 0);
        $row['quarterly_price'] = isset($row['quarterly_price']) && $row['quarterly_price'] !== '' ? (float) $row['quarterly_price'] : ($row['monthly_price'] * 3);
        $row['yearly_price'] = isset($row['yearly_price']) && $row['yearly_price'] !== '' ? (float) $row['yearly_price'] : ($row['monthly_price'] * 12);
        $row['trial_days'] = (int) ($row['trial_days'] ?? 0);
        $row['limits_json'] = (array) ($row['limits_json'] ?? []);
        $row['recommended_flag'] = !empty($row['recommended_flag']);
        $row['active_flag'] = !isset($row['active_flag']) ? true : (bool) $row['active_flag'];
        $row['description'] = (string) ($row['description'] ?? '');
        $row['created_at'] = (string) ($row['created_at'] ?? date('c'));
        $row['updated_at'] = date('c');
        return $row;
    }
}

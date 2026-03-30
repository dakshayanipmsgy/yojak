<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\JsonStorage;

class PmSuryaGharOpsService
{
    private const SCHEME_KEY = 'pm_surya_ghar';

    public static function recordsPath(string $tenantId, string $type): string
    {
        return TenantStorageService::getTenantSchemePath($tenantId, self::SCHEME_KEY) . '/records/' . $type . '.json';
    }

    public static function indexPath(string $tenantId, string $type): string
    {
        return TenantStorageService::getTenantSchemePath($tenantId, self::SCHEME_KEY) . '/indexes/' . $type . '_index.json';
    }

    public static function readRecords(string $tenantId, string $type): array
    {
        $path = self::recordsPath($tenantId, $type);
        return JsonStorage::read($path, ['items' => [], 'meta' => ['version' => 1, 'updated_at' => '', 'next_hint' => 1]]);
    }

    public static function writeRecords(string $tenantId, string $type, array $payload): void
    {
        JsonStorage::write(self::recordsPath($tenantId, $type), JsonStorage::touchMeta($payload));
    }

    public static function nextSchemeId(string $tenantId, string $type, string $prefix): string
    {
        $payload = self::readRecords($tenantId, $type);
        $next = max(1, (int) ($payload['meta']['next_hint'] ?? 1));
        $payload['meta']['next_hint'] = $next + 1;
        self::writeRecords($tenantId, $type, $payload);
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public static function normalizeMobile(string $mobile): string
    {
        return preg_replace('/\D+/', '', $mobile) ?? '';
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function duplicateLead(array $leads, string $mobile, string $email): ?array
    {
        foreach ($leads as $lead) {
            if ($mobile !== '' && self::normalizeMobile((string) ($lead['mobile'] ?? '')) === $mobile) {
                return $lead;
            }
            if ($email !== '' && self::normalizeEmail((string) ($lead['email'] ?? '')) === $email) {
                return $lead;
            }
        }
        return null;
    }

    public static function duplicateCustomer(array $customers, string $mobile, string $email): ?array
    {
        foreach ($customers as $customer) {
            if ($mobile !== '' && self::normalizeMobile((string) ($customer['mobile'] ?? '')) === $mobile) {
                return $customer;
            }
            if ($email !== '' && self::normalizeEmail((string) ($customer['email'] ?? '')) === $email) {
                return $customer;
            }
        }
        return null;
    }

    public static function calculations(array $input): array
    {
        $bill = max(0.0, (float) ($input['monthly_bill'] ?? 0));
        $units = max(0.0, (float) ($input['monthly_units'] ?? 0));
        $rate = max(1.0, (float) ($input['electricity_rate_assumption'] ?? 8));
        if ($units <= 0 && $bill > 0) {
            $units = $bill / $rate;
        }
        if ($bill <= 0 && $units > 0) {
            $bill = $units * $rate;
        }

        $size = max(1.0, (float) ($input['selected_system_size'] ?? ($units / 120.0)));
        $baseCost = $size * 60000;
        $subsidy = min($baseCost * 0.3, 78000);
        $net = max(0, $baseCost - $subsidy);

        $loan2 = min($net, 200000);
        $loanBig = $net;
        $emi2 = ($loan2 / 60) * 1.12;
        $emiBig = ($loanBig / 84) * 1.14;

        $monthlySavings = $bill * 0.82;
        $outflow = [
            'self_funded' => ['before' => $bill, 'after' => max(0, $bill - $monthlySavings)],
            'loan_upto_2_lacs' => ['before' => $bill, 'after' => max(0, $emi2 + ($bill * 0.15))],
            'loan_above_2_lacs' => ['before' => $bill, 'after' => max(0, $emiBig + ($bill * 0.18))],
        ];

        $years = [];
        $without = 0.0;
        $with = 0.0;
        for ($y = 1; $y <= 25; $y++) {
            $without += $bill * 12 * pow(1.03, $y - 1);
            $with += ($bill - $monthlySavings) * 12;
            $years[] = ['year' => $y, 'without_solar' => round($without, 2), 'with_solar' => round(max(0, $with), 2)];
        }

        $paybackYears = $monthlySavings > 0 ? round($net / ($monthlySavings * 12), 1) : null;

        return [
            'recommended_system_size' => round($size, 2),
            'pricing' => ['base_cost' => round($baseCost, 2), 'subsidy' => round($subsidy, 2), 'net_project_cost' => round($net, 2)],
            'solar_at_a_glance' => ['monthly_bill' => round($bill, 2), 'monthly_units' => round($units, 2), 'estimated_monthly_savings' => round($monthlySavings, 2)],
            'monthly_outflow_comparison' => $outflow,
            'cumulative_expense_25y' => $years,
            'payback_data' => ['estimated_payback_years' => $paybackYears],
            'financial_clarity' => ['annual_savings' => round($monthlySavings * 12, 2), '25y_savings' => round($years[24]['without_solar'] - $years[24]['with_solar'], 2)],
            'funding_options_summary' => [
                ['scenario' => 'self_funded', 'upfront' => round($net, 2), 'monthly_outflow' => $outflow['self_funded']['after']],
                ['scenario' => 'loan_upto_2_lacs', 'upfront' => round(max(0, $net - $loan2), 2), 'monthly_outflow' => round($outflow['loan_upto_2_lacs']['after'], 2)],
                ['scenario' => 'loan_above_2_lacs', 'upfront' => 0, 'monthly_outflow' => round($outflow['loan_above_2_lacs']['after'], 2)],
            ],
            'graphs_data' => ['monthly_outflow' => $outflow, 'cumulative_25y' => $years],
        ];
    }

    public static function snapshot(string $tenantId, string $folder, string $id, array $data): string
    {
        $dir = TenantStorageService::getTenantSchemePath($tenantId, self::SCHEME_KEY) . '/snapshots/' . $folder;
        JsonStorage::ensureDir($dir);
        $file = strtolower($folder . '_' . $id . '_snapshot_' . date('Ymd_His') . '.json');
        JsonStorage::write($dir . '/' . $file, ['data' => $data, 'meta' => ['created_at' => date('c')]]);
        return $file;
    }

    public static function writeIndex(string $tenantId, string $type, array $items, string $idField, string $statusField = 'status'): void
    {
        $index = ['by_id' => [], 'by_status' => [], 'by_date' => []];
        foreach ($items as $item) {
            $id = (string) ($item[$idField] ?? '');
            if ($id === '') {
                continue;
            }
            $index['by_id'][$id] = ['id' => $id, 'updated_at' => (string) ($item['updated_at'] ?? '')];
            $status = (string) ($item[$statusField] ?? 'unknown');
            $index['by_status'][$status][] = $id;
            $date = substr((string) ($item['created_at'] ?? ''), 0, 10);
            $index['by_date'][$date][] = $id;
        }
        JsonStorage::write(self::indexPath($tenantId, $type), ['data' => $index, 'meta' => ['updated_at' => date('c')]]);
    }

    public static function configPath(string $tenantId, string $configKey): string
    {
        return TenantStorageService::getTenantSchemePath($tenantId, self::SCHEME_KEY) . '/config/' . $configKey . '.json';
    }

    public static function loadSchemeDefaultConfig(string $configKey): array
    {
        $map = [
            'templates' => 'templates_defaults.json',
            'message_templates' => 'message_templates_defaults.json',
            'explainer_content' => 'explainer_content_defaults.json',
            'rate_chart' => 'rate_chart_defaults.json',
            'calculations' => 'calculation_defaults.json',
            'settings' => 'scheme_defaults.json',
        ];
        $file = $map[$configKey] ?? null;
        if ($file === null) {
            return [];
        }

        return RegistryService::getConfigData(DATA_PATH . '/platform/defaults/schemes/pm_surya_ghar/' . $file);
    }

    public static function mergeConfig(array $platformDefault, array $schemeDefault, array $tenantOverride): array
    {
        return self::deepMerge(self::deepMerge($platformDefault, $schemeDefault), $tenantOverride);
    }

    public static function effectiveConfig(string $tenantId, string $configKey): array
    {
        $platformDefault = self::loadSchemeDefaultConfig($configKey);
        $schemeDefault = $configKey === 'settings'
            ? TenantStorageService::getTenantSchemeSettings($tenantId, self::SCHEME_KEY)
            : TenantStorageService::getTenantSchemeConfig($tenantId, self::SCHEME_KEY, $configKey);
        $tenantOverride = $configKey === 'settings'
            ? TenantStorageService::getTenantSchemeSettings($tenantId, self::SCHEME_KEY)
            : TenantStorageService::getTenantSchemeConfig($tenantId, self::SCHEME_KEY, $configKey);

        return self::mergeConfig($platformDefault, $schemeDefault, $tenantOverride);
    }

    public static function saveConfig(string $tenantId, string $configKey, array $data): void
    {
        $path = $configKey === 'settings'
            ? TenantStorageService::getTenantSchemePath($tenantId, self::SCHEME_KEY) . '/settings.json'
            : self::configPath($tenantId, $configKey);
        RegistryService::putConfigData($path, $data);
    }

    public static function renderPlaceholders(string $content, array $values): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static function (array $m) use ($values): string {
            $key = (string) ($m[1] ?? '');
            $value = $values[$key] ?? '';
            return is_scalar($value) ? (string) $value : '';
        }, $content);
    }

    public static function effectiveBranding(string $tenantId): array
    {
        $platformBranding = RegistryService::getConfigData(DATA_PATH . '/platform/defaults/core/branding_defaults.json');
        $tenantBranding = TenantStorageService::getTenantBranding($tenantId);
        $profile = TenantStorageService::getTenantProfile($tenantId);

        return self::deepMerge(
            self::deepMerge($platformBranding, $tenantBranding),
            [
                'company_name' => (string) ($profile['company_name'] ?? ($tenantBranding['company_name'] ?? '')),
                'phone' => (string) ($profile['phone'] ?? ($tenantBranding['phone'] ?? '')),
                'email' => (string) ($profile['email'] ?? ($tenantBranding['email'] ?? '')),
                'address' => trim((string) (($profile['address'] ?? '') . ' ' . ($profile['city'] ?? '') . ' ' . ($profile['state'] ?? '') . ' ' . ($profile['pincode'] ?? ''))),
                'gst' => (string) ($profile['gst_number'] ?? ($tenantBranding['gst'] ?? '')),
            ]
        );
    }

    private static function deepMerge(array $base, array $overlay): array
    {
        foreach ($overlay as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = self::deepMerge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }
}

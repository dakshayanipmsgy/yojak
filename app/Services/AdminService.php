<?php

declare(strict_types=1);

namespace App\Services;

class AdminService
{
    public static function dashboardCounts(): array
    {
        $pending = RegistryService::get('pending_signups');
        $vendors = RegistryService::get('vendors');
        $subs = RegistryService::get('subscriptions');
        $schemes = RegistryService::get('schemes');
        $plans = RegistryService::get('plans');
        $modules = RegistryService::get('modules');

        return [
            'pending_signups' => count(array_filter($pending, fn(array $r): bool => ($r['verification_status'] ?? 'pending') === 'pending')),
            'total_vendors' => count($vendors),
            'verified_vendors' => count(array_filter($vendors, fn(array $r): bool => ($r['verification_status'] ?? '') === 'verified')),
            'active_vendors' => count(array_filter($vendors, fn(array $r): bool => ($r['account_status'] ?? '') === 'active')),
            'suspended_vendors' => count(array_filter($vendors, fn(array $r): bool => ($r['account_status'] ?? '') === 'suspended')),
            'cancelled_vendors' => count(array_filter($vendors, fn(array $r): bool => ($r['account_status'] ?? '') === 'cancelled')),
            'trial_subscriptions' => count(array_filter($subs, fn(array $r): bool => ($r['subscription_status'] ?? '') === 'trial')),
            'active_subscriptions' => count(array_filter($subs, fn(array $r): bool => ($r['subscription_status'] ?? '') === 'active')),
            'expired_subscriptions' => count(array_filter($subs, fn(array $r): bool => ($r['subscription_status'] ?? '') === 'expired')),
            'public_schemes' => count(array_filter($schemes, fn(array $r): bool => !empty($r['public_visible']) && !empty($r['active_flag']))),
            'signup_enabled_schemes' => count(array_filter($schemes, fn(array $r): bool => !empty($r['signup_enabled']) && !empty($r['active_flag']))),
            'total_modules' => count($modules),
            'enabled_modules' => count(array_filter($modules, fn(array $r): bool => !empty($r['enabled_flag']))),
            'active_plans' => count(array_filter($plans, fn(array $r): bool => !empty($r['active_flag']))),
            'total_plans' => count($plans),
        ];
    }

    public static function vendorsByPlan(): array
    {
        $vendors = RegistryService::get('vendors');
        $out = [];
        foreach ($vendors as $vendor) {
            $key = (string) ($vendor['current_plan_key'] ?? 'none');
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        arsort($out);
        return $out;
    }

    public static function vendorsByScheme(): array
    {
        $vendors = RegistryService::get('vendors');
        $out = [];
        foreach ($vendors as $vendor) {
            $keys = (array) ($vendor['enabled_schemes'] ?? []);
            foreach ($keys as $key) {
                $out[$key] = ($out[$key] ?? 0) + 1;
            }
        }
        arsort($out);
        return $out;
    }

    public static function defaultsSummary(): array
    {
        $base = DATA_PATH . '/platform/defaults';
        $files = [];
        foreach (['core', 'schemes'] as $segment) {
            $dir = $base . '/' . $segment;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $rel = str_replace(DATA_PATH . '/platform/', '', $file->getPathname());
                $files[] = $rel;
            }
        }
        sort($files);
        return $files;
    }
}

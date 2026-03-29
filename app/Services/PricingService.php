<?php

declare(strict_types=1);

namespace App\Services;

class PricingService
{
    public static function resolvePlanPrice(array $plan, string $cycle): float
    {
        $cycle = BillingCycleService::normalize($cycle);
        $monthly = (float) ($plan['monthly_price'] ?? 0);
        $explicit = (float) ($plan[$cycle . '_price'] ?? 0);
        if ($explicit > 0 || $monthly <= 0) {
            return $explicit;
        }
        return $monthly * BillingCycleService::multiple($cycle);
    }

    public static function resolveModulePrice(array $module, string $cycle): float
    {
        $cycle = BillingCycleService::normalize($cycle);
        $monthly = (float) ($module['monthly_price'] ?? 0);
        $explicit = (float) ($module[$cycle . '_price'] ?? 0);
        if ($explicit > 0 || $monthly <= 0) {
            return $explicit;
        }
        return $monthly * BillingCycleService::multiple($cycle);
    }

    public static function buildPriceBreakdown(array $subscription, array $plan, array $entitledModules, array $moduleIndex): array
    {
        $cycle = BillingCycleService::normalize((string) ($subscription['billing_cycle'] ?? 'monthly'));
        $mode = (string) ($subscription['subscription_mode'] ?? 'plan');
        $override = (array) ($subscription['override_pricing_json'] ?? []);

        $planPrice = 0.0;
        if ($plan !== []) {
            $planPrice = self::resolvePlanPrice($plan, $cycle);
        }

        $modulePrices = [];
        $setupFees = 0.0;
        $chargedModuleKeys = [];
        if ($mode === 'modules') {
            $chargedModuleKeys = (array) ($subscription['direct_module_keys'] ?? []);
        } elseif ($mode === 'hybrid') {
            $chargedModuleKeys = (array) ($subscription['addon_module_keys'] ?? []);
        }

        foreach ($chargedModuleKeys as $moduleKey) {
            if (!isset($moduleIndex[$moduleKey])) {
                continue;
            }
            $price = self::resolveModulePrice($moduleIndex[$moduleKey], $cycle);
            if (isset($override['module_price_overrides'][$moduleKey])) {
                $price = (float) $override['module_price_overrides'][$moduleKey];
            }
            $modulePrices[$moduleKey] = $price;
            $setupFees += (float) ($moduleIndex[$moduleKey]['optional_setup_fee'] ?? 0);
        }

        if (isset($override['plan_price_override']) && $plan !== []) {
            $planPrice = (float) $override['plan_price_override'];
        }

        $total = ($mode === 'modules') ? array_sum($modulePrices) : $planPrice + array_sum($modulePrices);
        if (isset($override['recurring_total_override'])) {
            $total = (float) $override['recurring_total_override'];
        }

        return [
            'mode' => $mode,
            'plan_key' => $plan['plan_key'] ?? null,
            'plan_price' => $planPrice,
            'addon_module_prices' => $mode === 'hybrid' ? $modulePrices : [],
            'direct_module_prices' => $mode === 'modules' ? $modulePrices : [],
            'setup_fees' => $setupFees,
            'recurring_total' => $total,
            'currency' => 'INR',
            'entitled_modules_count' => count($entitledModules),
            'calculated_at' => date('c'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

class BillingCycleService
{
    public const SUPPORTED = ['monthly', 'quarterly', 'yearly'];

    public static function normalize(string $cycle): string
    {
        $cycle = strtolower(trim($cycle));
        return in_array($cycle, self::SUPPORTED, true) ? $cycle : 'monthly';
    }

    public static function multiple(string $cycle): int
    {
        return match (self::normalize($cycle)) {
            'quarterly' => 3,
            'yearly' => 12,
            default => 1,
        };
    }

    public static function addToDate(string $cycle, ?string $from = null): string
    {
        $base = $from ? strtotime($from) : time();
        if ($base === false) {
            $base = time();
        }

        return match (self::normalize($cycle)) {
            'quarterly' => date('c', strtotime('+3 months', $base)),
            'yearly' => date('c', strtotime('+1 year', $base)),
            default => date('c', strtotime('+1 month', $base)),
        };
    }
}

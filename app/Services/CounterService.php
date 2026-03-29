<?php

declare(strict_types=1);

namespace App\Services;

class CounterService
{
    private const PREFIX_MAP = [
        'scheme' => 'SCH-',
        'module' => 'MOD-',
        'plan' => 'PLN-',
        'vendor' => 'VND-',
        'tenant' => 'TNT-',
        'signup' => 'SGN-',
        'subscription' => 'SUB-',
        'admin' => 'ADM-',
        'lead' => 'LED-',
        'customer' => 'CUS-',
        'quotation' => 'QUO-',
        'agreement' => 'AGR-',
        'receipt' => 'RCT-',
        'invoice' => 'INV-',
        'complaint' => 'CMP-',
        'report' => 'RPT-',
        'audit_entry' => 'AUD-',
    ];

    public static function next(string $counterKey): string
    {
        $state = RegistryService::getSystem('counters');
        $state['counters'][$counterKey] = (int) ($state['counters'][$counterKey] ?? 0) + 1;
        RegistryService::putSystem('counters', $state);

        $prefix = self::PREFIX_MAP[$counterKey] ?? strtoupper(substr($counterKey, 0, 3)) . '-';
        return $prefix . str_pad((string) $state['counters'][$counterKey], 4, '0', STR_PAD_LEFT);
    }
}

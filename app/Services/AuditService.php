<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\JsonStorage;

class AuditService
{
    public static function log(string $action, array $context = []): void
    {
        $file = DATA_PATH . '/platform/audit_log.json';
        $items = JsonStorage::read($file, []);
        $items[] = [
            'timestamp' => date('c'),
            'action' => $action,
            'context' => $context,
        ];
        JsonStorage::write($file, $items);
    }
}

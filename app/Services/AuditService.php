<?php

declare(strict_types=1);

namespace App\Services;

class AuditService
{
    public static function log(
        string $eventType,
        string $actorType = 'system',
        ?string $actorId = null,
        ?string $targetType = null,
        ?string $targetId = null,
        string $summary = '',
        array $payload = []
    ): void {
        $entry = [
            'audit_id' => CounterService::next('audit_entry'),
            'event_type' => $eventType,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'summary' => $summary,
            'payload' => $payload,
            'created_at' => date('c'),
        ];

        RegistryService::appendSystemLog('audit_log', $entry);
    }
}

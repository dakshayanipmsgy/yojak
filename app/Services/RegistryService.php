<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\JsonStorage;

class RegistryService
{
    public static function platformFile(string $name): string
    {
        return DATA_PATH . '/platform/' . $name . '.json';
    }

    public static function get(string $name, mixed $default = []): mixed
    {
        return JsonStorage::read(self::platformFile($name), $default);
    }

    public static function put(string $name, mixed $value): void
    {
        JsonStorage::write(self::platformFile($name), $value);
    }

    public static function nextId(string $counterKey, string $prefix): string
    {
        $counters = self::get('counters', []);
        $current = (int) ($counters[$counterKey] ?? 0) + 1;
        $counters[$counterKey] = $current;
        self::put('counters', $counters);
        return $prefix . str_pad((string) $current, 4, '0', STR_PAD_LEFT);
    }

    public static function findBy(array $records, string $key, mixed $value): ?array
    {
        foreach ($records as $record) {
            if (($record[$key] ?? null) === $value) {
                return $record;
            }
        }
        return null;
    }
}

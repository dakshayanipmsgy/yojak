<?php

declare(strict_types=1);

namespace App\Core;

class JsonStorage
{
    public static function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    public static function ensureFile(string $path, mixed $default): void
    {
        self::ensureDir(dirname($path));
        if (!file_exists($path) || trim((string) @file_get_contents($path)) === '') {
            self::write($path, $default);
        }
    }

    public static function read(string $path, mixed $default = []): mixed
    {
        if (!file_exists($path)) {
            self::ensureFile($path, $default);
        }

        $content = (string) @file_get_contents($path);
        if (trim($content) === '') {
            return $default;
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $default;
        }

        return $decoded;
    }

    public static function write(string $path, mixed $data): void
    {
        self::ensureDir(dirname($path));
        $tmp = $path . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        file_put_contents($tmp, $json, LOCK_EX);
        rename($tmp, $path);
    }

    public static function touchMeta(array $payload): array
    {
        $payload['meta'] = $payload['meta'] ?? [];
        $payload['meta']['version'] = $payload['meta']['version'] ?? 1;
        $payload['meta']['updated_at'] = date('c');
        return $payload;
    }
}

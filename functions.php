<?php
/**
 * Utility functions for file-based storage operations.
 */

if (!function_exists('read_json')) {
    /**
     * Read JSON data from a file path.
     *
     * @param string $path
     * @return array|null
     */
    function read_json(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('write_json')) {
    /**
     * Write JSON data to a file path using an exclusive lock.
     *
     * @param string $path
     * @param array $data
     * @return bool
     */
    function write_json(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($path, $json, LOCK_EX) !== false;
    }
}

if (!function_exists('generate_id')) {
    /**
     * Generate a unique identifier for future entities.
     *
     * @return string
     */
    function generate_id(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $e) {
            return uniqid('', true);
        }
    }
}

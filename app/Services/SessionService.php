<?php

declare(strict_types=1);

namespace App\Services;

class SessionService
{
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['csrf_token'];
    }

    public static function validateCsrf(?string $token): bool
    {
        $expected = $_SESSION['csrf_token'] ?? '';
        return is_string($token) && is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    public static function clearVendorSession(): void
    {
        unset($_SESSION['vendor']);
    }

    public static function clearAdminSession(): void
    {
        unset($_SESSION['admin']);
    }

    public static function destroyAll(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}

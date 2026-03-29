<?php

declare(strict_types=1);

namespace App\Services;

class AuthService
{
    public static function admin(): ?array
    {
        return $_SESSION['admin'] ?? null;
    }

    public static function vendor(): ?array
    {
        if (empty($_SESSION['vendor_id'])) {
            return null;
        }
        $vendors = RegistryService::get('vendors', []);
        foreach ($vendors as $vendor) {
            if ($vendor['vendor_id'] === $_SESSION['vendor_id']) {
                return $vendor;
            }
        }
        return null;
    }

    public static function loginAdmin(string $email, string $password): bool
    {
        $settings = RegistryService::get('superadmin_settings', []);
        if (($settings['email'] ?? '') === $email && password_verify($password, $settings['password_hash'] ?? '')) {
            $_SESSION['admin'] = ['email' => $email, 'logged_in_at' => date('c')];
            AuditService::log('admin_login_success', ['email' => $email]);
            return true;
        }
        return false;
    }

    public static function loginVendor(string $email, string $password): array
    {
        $vendors = RegistryService::get('vendors', []);
        foreach ($vendors as $vendor) {
            if (($vendor['email'] ?? '') === $email && password_verify($password, $vendor['password_hash'] ?? '')) {
                if (!AccessService::canAccessVendorWorkspace($vendor)) {
                    AuditService::log('vendor_login_blocked', ['vendor_id' => $vendor['vendor_id'], 'status' => $vendor['subscription_status']]);
                    return [false, 'Account is not eligible for workspace access.'];
                }
                $_SESSION['vendor_id'] = $vendor['vendor_id'];
                AuditService::log('vendor_login_success', ['vendor_id' => $vendor['vendor_id']]);
                return [true, ''];
            }
        }

        $pending = RegistryService::get('pending_signups', []);
        foreach ($pending as $entry) {
            if (($entry['email'] ?? '') === $email) {
                return [false, 'Signup exists but is pending/rejected review.'];
            }
        }

        return [false, 'Invalid credentials.'];
    }

    public static function logoutAll(): void
    {
        $_SESSION = [];
        session_destroy();
    }
}

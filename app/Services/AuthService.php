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
        return RegistryService::getVendorById((string) $_SESSION['vendor_id']);
    }

    public static function loginAdmin(string $email, string $password): bool
    {
        $admins = RegistryService::get('superadmin_accounts');
        foreach ($admins as $admin) {
            if (($admin['email'] ?? '') === $email && !empty($admin['active_flag']) && password_verify($password, $admin['password_hash'] ?? '')) {
                $_SESSION['admin'] = ['admin_id' => $admin['admin_id'], 'email' => $email, 'logged_in_at' => date('c')];
                AuditService::log('admin_login_success', 'admin', $admin['admin_id'], 'admin', $admin['admin_id'], 'Admin login success.');
                return true;
            }
        }
        return false;
    }

    public static function loginVendor(string $email, string $password): array
    {
        foreach (RegistryService::get('vendors') as $vendor) {
            if (($vendor['email'] ?? '') === $email && password_verify($password, $vendor['password_hash'] ?? '')) {
                if (!AccessService::canAccessVendorWorkspace($vendor)) {
                    AuditService::log('vendor_login_blocked', 'vendor', $vendor['vendor_id'], 'vendor', $vendor['vendor_id'], 'Vendor login blocked.');
                    return [false, 'Account is not eligible for workspace access.'];
                }
                $_SESSION['vendor_id'] = $vendor['vendor_id'];
                AuditService::log('vendor_login_success', 'vendor', $vendor['vendor_id'], 'vendor', $vendor['vendor_id'], 'Vendor login success.');
                return [true, ''];
            }
        }

        foreach (RegistryService::get('pending_signups') as $entry) {
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

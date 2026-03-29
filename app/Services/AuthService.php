<?php

declare(strict_types=1);

namespace App\Services;

class AuthService
{
    public static function admin(): ?array
    {
        return $_SESSION['admin'] ?? null;
    }

    public static function vendorSession(): ?array
    {
        return $_SESSION['vendor'] ?? null;
    }

    public static function vendor(): ?array
    {
        $session = self::vendorSession();
        if (empty($session['vendor_id'])) {
            return null;
        }
        return RegistryService::getVendorById((string) $session['vendor_id']);
    }

    public static function loginAdmin(string $email, string $password): bool
    {
        $admins = RegistryService::get('superadmin_accounts');
        foreach ($admins as $admin) {
            if (($admin['email'] ?? '') === strtolower(trim($email))
                && !empty($admin['active_flag'])
                && password_verify($password, (string) ($admin['password_hash'] ?? ''))
            ) {
                SessionService::regenerate();
                SessionService::clearVendorSession();
                $_SESSION['admin'] = [
                    'actor_type' => 'superadmin',
                    'admin_id' => $admin['admin_id'],
                    'email' => $admin['email'],
                    'login_time' => date('c'),
                ];
                AuditService::log('admin_login_success', 'admin', $admin['admin_id'], 'admin', $admin['admin_id'], 'Admin login success.');
                return true;
            }
        }

        AuditService::log('admin_login_failed', 'admin', null, 'admin', null, 'Admin login failed.', ['email' => strtolower(trim($email))]);
        return false;
    }

    public static function loginVendor(string $identifier, string $password): array
    {
        $email = SignupService::normalizeEmail($identifier);
        $mobile = SignupService::normalizeMobile($identifier);

        $vendor = RegistryService::getVendorByEmail($email);
        if (!$vendor && $mobile !== '') {
            $vendor = RegistryService::getVendorByMobile($mobile);
        }

        if ($vendor) {
            if (!password_verify($password, (string) ($vendor['password_hash'] ?? ''))) {
                AuditService::log('vendor_login_failed', 'vendor', $vendor['vendor_id'] ?? null, 'vendor', $vendor['vendor_id'] ?? null, 'Vendor login failed: bad password.');
                return [false, 'Invalid credentials.'];
            }

            $access = AccessService::evaluateVendorAccess($vendor);
            if (!$access['is_allowed']) {
                $event = match ($access['blocked_reason_code']) {
                    'pending' => 'vendor_login_blocked_pending',
                    'rejected' => 'vendor_login_blocked_rejected',
                    'suspended' => 'vendor_login_blocked_suspended',
                    'expired' => 'vendor_login_blocked_expired',
                    'cancelled', 'subscription_cancelled' => 'vendor_login_blocked_cancelled',
                    default => 'vendor_login_failed',
                };
                AuditService::log($event, 'vendor', $vendor['vendor_id'], 'vendor', $vendor['vendor_id'], 'Vendor login blocked.', ['reason' => $access['blocked_reason_code']]);
                return [false, $access['blocked_message']];
            }

            SessionService::regenerate();
            SessionService::clearAdminSession();
            $_SESSION['vendor'] = [
                'actor_type' => 'vendor',
                'vendor_id' => $vendor['vendor_id'],
                'tenant_id' => $vendor['tenant_id'],
                'current_scheme_key' => $vendor['default_scheme_key'] ?? (($vendor['enabled_schemes'][0] ?? 'pm_surya_ghar')),
                'login_time' => date('c'),
            ];
            AuditService::log('vendor_login_success', 'vendor', $vendor['vendor_id'], 'vendor', $vendor['vendor_id'], 'Vendor login success.');
            return [true, ''];
        }

        $pending = RegistryService::getPendingSignupByEmailOrMobile($identifier);
        if ($pending && password_verify($password, (string) ($pending['password_hash'] ?? ''))) {
            $status = (string) ($pending['verification_status'] ?? 'pending');
            if ($status === 'rejected') {
                AuditService::log('vendor_login_blocked_rejected', 'vendor', null, 'signup', $pending['signup_id'] ?? null, 'Rejected signup attempted login.');
                return [false, AccessService::blockedMessage('rejected')];
            }

            AuditService::log('vendor_login_blocked_pending', 'vendor', null, 'signup', $pending['signup_id'] ?? null, 'Pending signup attempted login.');
            return [false, AccessService::blockedMessage('pending')];
        }

        AuditService::log('vendor_login_failed', 'vendor', null, 'vendor', null, 'Vendor login failed: no matching identity.');
        return [false, 'Invalid credentials.'];
    }

    public static function logoutVendor(): void
    {
        $vendorId = self::vendorSession()['vendor_id'] ?? null;
        if ($vendorId) {
            AuditService::log('vendor_logout', 'vendor', (string) $vendorId, 'vendor', (string) $vendorId, 'Vendor logged out.');
        }
        SessionService::clearVendorSession();
    }

    public static function logoutAdmin(): void
    {
        $adminId = self::admin()['admin_id'] ?? null;
        if ($adminId) {
            AuditService::log('admin_logout', 'admin', (string) $adminId, 'admin', (string) $adminId, 'Admin logged out.');
        }
        SessionService::clearAdminSession();
    }

    public static function logoutAll(): void
    {
        self::logoutVendor();
        self::logoutAdmin();
        SessionService::destroyAll();
    }
}

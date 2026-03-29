<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Services\AccessService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\ProvisioningService;
use App\Services\RegistryService;
use App\Services\SessionService;
use App\Services\SignupService;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

function render(string $title, string $view, array $data = [], string $layout = 'public'): void
{
    extract($data);
    $contentView = BASE_PATH . '/app/Views/' . $layout . '/' . $view . '.php';
    require BASE_PATH . '/app/Views/layouts/' . $layout . '.php';
    exit;
}

function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function requireCsrfOrAbort(): void
{
    if (!SessionService::validateCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(422);
        echo 'Invalid CSRF token';
        exit;
    }
}

function requireAdmin(): array
{
    $admin = AuthService::admin();
    if (!$admin) {
        redirectTo('/admin/login');
    }
    return $admin;
}

function requireVendor(): array
{
    $vendor = AuthService::vendor();
    if (!$vendor) {
        redirectTo('/login');
    }

    $access = AccessService::evaluateVendorAccess($vendor);
    if (!$access['is_allowed']) {
        AuthService::logoutVendor();
        redirectTo('/login?error=' . urlencode((string) $access['blocked_message']));
    }

    return $vendor;
}

function requireSchemeAccess(array $vendor, string $schemeKey): void
{
    if (!AccessService::hasSchemeAccess($vendor, $schemeKey)) {
        AuditService::log('unauthorized_scheme_access_attempt', 'vendor', $vendor['vendor_id'] ?? null, 'scheme', $schemeKey, 'Unauthorized scheme access attempt.');
        http_response_code(403);
        render('Access denied', 'module', ['vendor' => $vendor, 'moduleKey' => 'scheme', 'schemeKey' => $schemeKey, 'title' => 'Access denied', 'description' => AccessService::blockedMessage('no_scheme_access')], 'vendor');
    }
}

function requireModuleAccess(array $vendor, string $schemeKey, string $moduleKey): void
{
    requireSchemeAccess($vendor, $schemeKey);
    if (!AccessService::hasModuleAccess($vendor, $moduleKey, $schemeKey)) {
        AuditService::log('unauthorized_module_access_attempt', 'vendor', $vendor['vendor_id'] ?? null, 'module', $moduleKey, 'Unauthorized module access attempt.', ['scheme_key' => $schemeKey]);
        http_response_code(403);
        render('Access denied', 'module', ['vendor' => $vendor, 'moduleKey' => $moduleKey, 'schemeKey' => $schemeKey, 'title' => 'Access denied', 'description' => 'You do not have access to this section.'], 'vendor');
    }
}

$schemes = RegistryService::get('schemes');
$modules = RegistryService::get('modules');
$plans = RegistryService::get('plans');
$settings = RegistryService::get('superadmin_settings')[0] ?? [];
$csrfToken = SessionService::csrfToken();

if ($path === '/logout') {
    AuthService::logoutVendor();
    redirectTo('/login');
}
if ($path === '/admin/logout') {
    AuthService::logoutAdmin();
    redirectTo('/admin/login');
}

if ($path === '/' || $path === '/homepage') render('Yojak - Platform', 'home', compact('schemes', 'settings'));
if ($path === '/schemes') {
    $publicSchemes = array_values(array_filter($schemes, fn($s) => !empty($s['public_visible']) && !empty($s['active_flag'])));
    render('Schemes', 'schemes', compact('publicSchemes'));
}
if ($path === '/scheme/pm-surya-ghar') render('PM Surya Ghar', 'scheme', ['scheme' => RegistryService::getSchemeByKey('pm_surya_ghar'), 'plans' => $plans]);
if ($path === '/pricing') render('Pricing', 'pricing', compact('plans'));
if ($path === '/signup') redirectTo('/signup/pm-surya-ghar');

if ($path === '/signup/pm-surya-ghar' && $method === 'GET') {
    render('Vendor Signup', 'signup', ['csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => RegistryService::getSchemeByKey('pm_surya_ghar')]);
}
if ($path === '/signup/pm-surya-ghar' && $method === 'POST') {
    requireCsrfOrAbort();
    $scheme = RegistryService::getSchemeByKey('pm_surya_ghar');
    [$ok, $error] = SignupService::validateSignupInput($_POST, $settings, $scheme);
    if (!$ok) {
        render('Vendor Signup', 'signup', ['error' => $error, 'csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => $scheme]);
    }

    $pending = RegistryService::get('pending_signups');
    $vendors = RegistryService::get('vendors');
    $email = SignupService::normalizeEmail((string) ($_POST['email'] ?? ''));
    $mobile = SignupService::normalizeMobile((string) ($_POST['mobile'] ?? ''));
    $duplicate = SignupService::findDuplicate($pending, $vendors, $email, $mobile);
    if ($duplicate) {
        render('Vendor Signup', 'signup', ['error' => $duplicate, 'csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => $scheme]);
    }

    $signup = SignupService::buildPendingSignup($_POST, 'pm_surya_ghar');
    $pending[] = $signup;
    RegistryService::put('pending_signups', $pending);
    AuditService::log('signup_submitted', 'vendor', null, 'signup', $signup['signup_id'], 'Signup submitted.', ['email' => $signup['email'], 'mobile' => $signup['mobile']]);

    render('Vendor Signup', 'signup', ['success' => 'Signup received. Your account is pending superadmin verification. Login is unavailable until approval.', 'csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => $scheme]);
}

if ($path === '/login' && $method === 'GET') {
    if (AuthService::vendor()) {
        redirectTo('/app/dashboard');
    }
    render('Vendor Login', 'login', ['error' => $_GET['error'] ?? null, 'csrfToken' => $csrfToken]);
}
if ($path === '/login' && $method === 'POST') {
    requireCsrfOrAbort();
    [$ok, $error] = AuthService::loginVendor((string) ($_POST['identifier'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($ok) {
        $vendor = AuthService::vendor();
        $schemeKey = $vendor['default_scheme_key'] ?? (($vendor['enabled_schemes'][0] ?? null));
        if ($schemeKey && AccessService::hasSchemeAccess($vendor, $schemeKey)) {
            redirectTo('/app/' . str_replace('_', '-', $schemeKey) . '/dashboard');
        }
        redirectTo('/app/dashboard');
    }
    render('Vendor Login', 'login', compact('error', 'csrfToken'));
}

if ($path === '/admin/login' && $method === 'GET') {
    if (AuthService::admin()) {
        redirectTo('/admin/dashboard');
    }
    render('Admin Login', 'login', ['admin' => true, 'csrfToken' => $csrfToken]);
}
if ($path === '/admin/login' && $method === 'POST') {
    requireCsrfOrAbort();
    if (AuthService::loginAdmin((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) {
        redirectTo('/admin/dashboard');
    }
    render('Admin Login', 'login', ['admin' => true, 'error' => 'Invalid credentials', 'csrfToken' => $csrfToken]);
}

if (str_starts_with($path, '/admin')) {
    $admin = requireAdmin();
    $vendors = RegistryService::get('vendors');
    $pending = RegistryService::get('pending_signups');

    if ($path === '/admin' || $path === '/admin/dashboard') {
        $counts = [
            'pending' => count(array_filter($pending, fn($p) => ($p['verification_status'] ?? 'pending') === 'pending')),
            'vendors' => count($vendors),
            'verified' => count(array_filter($vendors, fn($v) => ($v['verification_status'] ?? '') === 'verified')),
            'active' => count(array_filter($vendors, fn($v) => ($v['account_status'] ?? '') === 'active')),
            'suspended' => count(array_filter($vendors, fn($v) => ($v['account_status'] ?? '') === 'suspended')),
            'schemes' => count(array_filter($schemes, fn($s) => !empty($s['public_visible']))),
            'plans' => count($plans),
            'modules' => count($modules),
        ];
        render('Admin Dashboard', 'dashboard', compact('counts'), 'admin');
    }

    if ($path === '/admin/pending-signups') {
        if ($method === 'POST' && isset($_POST['action'], $_POST['signup_id'])) {
            requireCsrfOrAbort();
            foreach ($pending as &$row) {
                if (($row['signup_id'] ?? '') !== $_POST['signup_id']) {
                    continue;
                }
                if (($row['verification_status'] ?? 'pending') !== 'pending') {
                    break;
                }

                if ($_POST['action'] === 'reject') {
                    $row['verification_status'] = 'rejected';
                    $row['processed_at'] = date('c');
                    $row['processed_by'] = $admin['admin_id'];
                    $row['process_note'] = SignupService::sanitizeText((string) ($_POST['process_note'] ?? 'Rejected by admin'));
                    AuditService::log('signup_rejected', 'admin', $admin['admin_id'], 'signup', $row['signup_id'], 'Signup rejected.');
                }

                if ($_POST['action'] === 'verify') {
                    $planKey = (string) ($settings['default_trial_plan_key'] ?? 'growth');
                    $plan = RegistryService::getPlanByKey($planKey) ?? ($plans[0] ?? ['plan_key' => 'growth', 'trial_days' => 14]);
                    $vendor = ProvisioningService::provisionTenantForApprovedSignup(
                        $row,
                        ['pm_surya_ghar'],
                        (string) ($plan['plan_key'] ?? 'growth'),
                        (string) ($settings['default_billing_cycle'] ?? 'monthly'),
                        (int) ($plan['trial_days'] ?? 14),
                        $admin['admin_id'],
                        true
                    );
                    $row['verification_status'] = 'verified';
                    $row['processed_at'] = date('c');
                    $row['processed_by'] = $admin['admin_id'];
                    $row['process_note'] = 'Verified and provisioned as vendor ' . $vendor['vendor_id'];
                    AuditService::log('signup_verified', 'admin', $admin['admin_id'], 'vendor', $vendor['vendor_id'], 'Signup verified and provisioned.', ['signup_id' => $row['signup_id']]);
                }
                break;
            }
            unset($row);
            RegistryService::put('pending_signups', $pending);
            redirectTo('/admin/pending-signups');
        }
        render('Pending Signups', 'pending_signups', compact('pending', 'csrfToken'), 'admin');
    }

    if ($path === '/admin/vendors') {
        if ($method === 'POST' && isset($_POST['vendor_id'], $_POST['action'])) {
            requireCsrfOrAbort();
            $subscriptions = RegistryService::get('subscriptions');
            foreach ($vendors as &$v) {
                if (($v['vendor_id'] ?? '') !== $_POST['vendor_id']) {
                    continue;
                }
                if ($_POST['action'] === 'suspend') {
                    $v['account_status'] = 'suspended';
                    AuditService::log('vendor_suspended', 'admin', $admin['admin_id'], 'vendor', $v['vendor_id'], 'Vendor suspended.');
                }
                if ($_POST['action'] === 'activate' && ($v['verification_status'] ?? '') === 'verified') {
                    $v['account_status'] = 'active';
                    AuditService::log('vendor_activated', 'admin', $admin['admin_id'], 'vendor', $v['vendor_id'], 'Vendor activated.');
                }
                if ($_POST['action'] === 'cancel') {
                    $v['account_status'] = 'cancelled';
                    foreach ($subscriptions as &$sub) {
                        if (($sub['vendor_id'] ?? '') === $v['vendor_id']) {
                            $sub['subscription_status'] = 'cancelled';
                            $sub['cancelled_at'] = date('c');
                            $sub['updated_at'] = date('c');
                        }
                    }
                    unset($sub);
                    AuditService::log('vendor_cancelled', 'admin', $admin['admin_id'], 'vendor', $v['vendor_id'], 'Vendor cancelled.');
                }
                $v['updated_at'] = date('c');
                break;
            }
            unset($v);
            RegistryService::put('vendors', $vendors);
            RegistryService::put('subscriptions', $subscriptions);
            redirectTo('/admin/vendors');
        }

        $subscriptionsByVendor = [];
        foreach (RegistryService::get('subscriptions') as $sub) {
            $subscriptionsByVendor[$sub['vendor_id']] = $sub;
        }
        render('Vendors', 'vendors', compact('vendors', 'subscriptionsByVendor', 'csrfToken'), 'admin');
    }

    if ($path === '/admin/schemes') render('Schemes', 'schemes', compact('schemes'), 'admin');
    if ($path === '/admin/modules') render('Modules', 'modules', compact('modules'), 'admin');
    if ($path === '/admin/plans') render('Plans', 'plans', compact('plans'), 'admin');
    if ($path === '/admin/settings') {
        if ($method === 'POST') {
            requireCsrfOrAbort();
            $settings['platform_name'] = trim((string) ($_POST['platform_name'] ?? 'Yojak'));
            $settings['allow_signup_globally'] = isset($_POST['allow_signup_globally']);
            $settings['demo_mode'] = isset($_POST['demo_mode']);
            $settings['updated_at'] = date('c');
            RegistryService::put('superadmin_settings', [$settings]);
        }
        render('Settings', 'settings', compact('settings', 'csrfToken'), 'admin');
    }
}

if (str_starts_with($path, '/app')) {
    $vendor = requireVendor();

    if ($path === '/app' || $path === '/app/dashboard') {
        $schemePath = DATA_PATH . '/tenants/tenant_' . $vendor['tenant_id'] . '/schemes/pm_surya_ghar/records';
        $cards = [
            'lead_count' => count((array) (\App\Core\JsonStorage::read($schemePath . '/leads.json', ['items' => []])['items'] ?? [])),
            'draft_quotations' => count((array) (\App\Core\JsonStorage::read($schemePath . '/quotations.json', ['items' => []])['items'] ?? [])),
            'pending_agreements' => count((array) (\App\Core\JsonStorage::read($schemePath . '/agreements.json', ['items' => []])['items'] ?? [])),
            'open_complaints' => count((array) (\App\Core\JsonStorage::read($schemePath . '/complaints.json', ['items' => []])['items'] ?? [])),
        ];
        render('Vendor Dashboard', 'dashboard', compact('vendor', 'cards'), 'vendor');
    }
    if ($path === '/app/profile') render('Profile', 'profile', compact('vendor'), 'vendor');
    if ($path === '/app/subscription') render('Subscription', 'subscription', compact('vendor'), 'vendor');

    $moduleRoutes = [
        '/app/pm-surya-ghar/dashboard' => ['dashboard', 'PM Surya Ghar Dashboard'],
        '/app/pm-surya-ghar/leads' => ['leads', 'Leads'],
        '/app/pm-surya-ghar/customers' => ['customers', 'Customers'],
        '/app/pm-surya-ghar/quotations' => ['quotations', 'Quotations'],
        '/app/pm-surya-ghar/solar-finance' => ['solar-finance', 'Solar and Finance'],
        '/app/pm-surya-ghar/agreements' => ['agreements', 'Agreements'],
        '/app/pm-surya-ghar/payment-receipts' => ['payment-receipts', 'Payment Receipts'],
        '/app/pm-surya-ghar/invoices' => ['invoices', 'Invoices'],
        '/app/pm-surya-ghar/complaints' => ['complaints', 'Complaints'],
        '/app/pm-surya-ghar/templates-media' => ['templates-media', 'Templates & Media'],
        '/app/pm-surya-ghar/messaging-templates' => ['messaging-templates', 'Messaging Templates'],
        '/app/pm-surya-ghar/explainer-content' => ['explainer-content', 'Explainer Content'],
        '/app/pm-surya-ghar/rate-chart' => ['rate-chart', 'Rate Chart'],
        '/app/pm-surya-ghar/reports-exports' => ['reports-exports', 'Reports & Exports'],
        '/app/pm-surya-ghar/company-branding' => ['company-branding', 'Company Profile & Branding'],
        '/app/pm-surya-ghar/subscription-billing' => ['subscription-billing', 'Subscription & Billing'],
        '/app/pm-surya-ghar/scheme-settings' => ['scheme-settings', 'Scheme Settings'],
    ];

    if (isset($moduleRoutes[$path])) {
        [$moduleKey, $label] = $moduleRoutes[$path];
        $schemeKey = 'pm_surya_ghar';
        requireModuleAccess($vendor, $schemeKey, $moduleKey);
        render($label, 'module', ['vendor' => $vendor, 'moduleKey' => $moduleKey, 'schemeKey' => $schemeKey, 'title' => $label, 'description' => 'Module not implemented yet. This is a placeholder shell.'], 'vendor');
    }
}

http_response_code(404);
echo '404 Not Found';

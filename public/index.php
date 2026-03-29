<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Services\AccessService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\BillingCycleService;
use App\Services\ModuleService;
use App\Services\PlanService;
use App\Services\PricingService;
use App\Services\ProvisioningService;
use App\Services\RegistryService;
use App\Services\SessionService;
use App\Services\SignupService;
use App\Services\SubscriptionService;

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'];

function render(string $title, string $view, array $data = [], string $layout = 'public'): void
{
    extract($data);
    $contentView = BASE_PATH . '/app/Views/' . $layout . '/' . $view . '.php';
    require BASE_PATH . '/app/Views/layouts/' . $layout . '.php';
    exit;
}
function redirectTo(string $url): void { header('Location: ' . $url); exit; }
function requireCsrfOrAbort(): void { if (!SessionService::validateCsrf($_POST['csrf_token'] ?? null)) { http_response_code(422); echo 'Invalid CSRF token'; exit; } }
function requireAdmin(): array { $admin = AuthService::admin(); if (!$admin) redirectTo('/admin/login'); return $admin; }
function requireVendor(): array { $vendor = AuthService::vendor(); if (!$vendor) redirectTo('/login'); $access = AccessService::evaluateVendorAccess($vendor); if (!$access['is_allowed']) { AuthService::logoutVendor(); redirectTo('/login?error=' . urlencode((string) $access['blocked_message'])); } return RegistryService::getVendorById((string) $vendor['vendor_id']) ?? $vendor; }
function requireSchemeAccess(array $vendor, string $schemeKey): void { if (!AccessService::hasSchemeAccess($vendor, $schemeKey)) { http_response_code(403); render('Access denied', 'module', ['vendor' => $vendor, 'moduleKey' => 'scheme', 'schemeKey' => $schemeKey, 'title' => 'Access denied', 'description' => AccessService::blockedMessage('no_scheme_access')], 'vendor'); } }
function requireModuleAccess(array $vendor, string $schemeKey, string $moduleKey): void { requireSchemeAccess($vendor, $schemeKey); if (!AccessService::hasModuleAccess($vendor, $moduleKey, $schemeKey)) { http_response_code(403); render('Access denied', 'module', ['vendor' => $vendor, 'moduleKey' => $moduleKey, 'schemeKey' => $schemeKey, 'title' => 'Access denied', 'description' => 'You do not have access to this section.'], 'vendor'); } }
function parseKeys(string $input): array { return array_values(array_unique(array_filter(preg_split('/\s*,\s*/', trim($input)) ?: []))); }

$schemes = RegistryService::get('schemes');
$modules = ModuleService::normalizedAll();
$plans = PlanService::normalizedAll();
$settings = RegistryService::get('superadmin_settings')[0] ?? [];
$csrfToken = SessionService::csrfToken();

if ($path === '/logout') { AuthService::logoutVendor(); redirectTo('/login'); }
if ($path === '/admin/logout') { AuthService::logoutAdmin(); redirectTo('/admin/login'); }

if ($path === '/' || $path === '/homepage') render('Yojak - Platform', 'home', compact('schemes', 'settings'));
if ($path === '/schemes') { $publicSchemes = array_values(array_filter($schemes, fn($s) => !empty($s['public_visible']) && !empty($s['active_flag']))); render('Schemes', 'schemes', compact('publicSchemes')); }
if ($path === '/scheme/pm-surya-ghar') render('PM Surya Ghar', 'scheme', ['scheme' => RegistryService::getSchemeByKey('pm_surya_ghar'), 'plans' => $plans]);
if ($path === '/pricing') render('Pricing', 'pricing', ['plans' => $plans, 'modules' => $modules, 'cycle' => BillingCycleService::normalize((string) ($_GET['cycle'] ?? 'monthly'))]);
if ($path === '/signup') redirectTo('/signup/pm-surya-ghar');

if ($path === '/signup/pm-surya-ghar' && $method === 'GET') render('Vendor Signup', 'signup', ['csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => RegistryService::getSchemeByKey('pm_surya_ghar')]);
if ($path === '/signup/pm-surya-ghar' && $method === 'POST') {
    requireCsrfOrAbort();
    $scheme = RegistryService::getSchemeByKey('pm_surya_ghar');
    [$ok, $error] = SignupService::validateSignupInput($_POST, $settings, $scheme);
    if (!$ok) render('Vendor Signup', 'signup', ['error' => $error, 'csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => $scheme]);
    $pending = RegistryService::get('pending_signups');
    $vendors = RegistryService::get('vendors');
    $email = SignupService::normalizeEmail((string) ($_POST['email'] ?? ''));
    $mobile = SignupService::normalizeMobile((string) ($_POST['mobile'] ?? ''));
    $duplicate = SignupService::findDuplicate($pending, $vendors, $email, $mobile);
    if ($duplicate) render('Vendor Signup', 'signup', ['error' => $duplicate, 'csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => $scheme]);
    $signup = SignupService::buildPendingSignup($_POST, 'pm_surya_ghar');
    $pending[] = $signup;
    RegistryService::put('pending_signups', $pending);
    render('Vendor Signup', 'signup', ['success' => 'Signup received. Your account is pending superadmin verification. Login is unavailable until approval.', 'csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => $scheme]);
}

if ($path === '/login' && $method === 'GET') { if (AuthService::vendor()) redirectTo('/app/dashboard'); render('Vendor Login', 'login', ['error' => $_GET['error'] ?? null, 'csrfToken' => $csrfToken]); }
if ($path === '/login' && $method === 'POST') { requireCsrfOrAbort(); [$ok, $error] = AuthService::loginVendor((string) ($_POST['identifier'] ?? ''), (string) ($_POST['password'] ?? '')); if ($ok) redirectTo('/app/dashboard'); render('Vendor Login', 'login', compact('error', 'csrfToken')); }
if ($path === '/admin/login' && $method === 'GET') { if (AuthService::admin()) redirectTo('/admin/dashboard'); render('Admin Login', 'login', ['admin' => true, 'csrfToken' => $csrfToken]); }
if ($path === '/admin/login' && $method === 'POST') { requireCsrfOrAbort(); if (AuthService::loginAdmin((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) redirectTo('/admin/dashboard'); render('Admin Login', 'login', ['admin' => true, 'error' => 'Invalid credentials', 'csrfToken' => $csrfToken]); }

if (str_starts_with($path, '/admin')) {
    $admin = requireAdmin();
    $vendors = RegistryService::get('vendors');
    $pending = RegistryService::get('pending_signups');

    if ($path === '/admin' || $path === '/admin/dashboard') {
        $counts = ['pending' => count(array_filter($pending, fn($p) => ($p['verification_status'] ?? 'pending') === 'pending')), 'vendors' => count($vendors), 'verified' => count(array_filter($vendors, fn($v) => ($v['verification_status'] ?? '') === 'verified')), 'active' => count(array_filter($vendors, fn($v) => ($v['account_status'] ?? '') === 'active')), 'suspended' => count(array_filter($vendors, fn($v) => ($v['account_status'] ?? '') === 'suspended')), 'schemes' => count(array_filter($schemes, fn($s) => !empty($s['public_visible']))), 'plans' => count($plans), 'modules' => count($modules)];
        render('Admin Dashboard', 'dashboard', compact('counts'), 'admin');
    }

    if ($path === '/admin/pending-signups') {
        if ($method === 'POST' && isset($_POST['action'], $_POST['signup_id'])) {
            requireCsrfOrAbort();
            foreach ($pending as &$row) {
                if (($row['signup_id'] ?? '') !== $_POST['signup_id']) continue;
                if (($row['verification_status'] ?? 'pending') !== 'pending') break;
                if ($_POST['action'] === 'reject') {
                    $row['verification_status'] = 'rejected'; $row['processed_at'] = date('c'); $row['processed_by'] = $admin['admin_id']; $row['process_note'] = SignupService::sanitizeText((string) ($_POST['process_note'] ?? 'Rejected by admin'));
                }
                if ($_POST['action'] === 'verify') {
                    $planKey = (string) ($settings['default_trial_plan_key'] ?? 'growth');
                    $plan = RegistryService::getPlanByKey($planKey) ?? ($plans[0] ?? ['plan_key' => 'growth', 'trial_days' => 14]);
                    $vendor = ProvisioningService::provisionTenantForApprovedSignup($row, ['pm_surya_ghar'], (string) ($plan['plan_key'] ?? 'growth'), (string) ($settings['default_billing_cycle'] ?? 'monthly'), (int) ($plan['trial_days'] ?? 14), $admin['admin_id'], true);
                    $row['verification_status'] = 'verified'; $row['processed_at'] = date('c'); $row['processed_by'] = $admin['admin_id']; $row['process_note'] = 'Verified and provisioned as vendor ' . $vendor['vendor_id'];
                }
                break;
            }
            unset($row);
            RegistryService::put('pending_signups', $pending);
            redirectTo('/admin/pending-signups');
        }
        render('Pending Signups', 'pending_signups', compact('pending', 'csrfToken'), 'admin');
    }

    if ($path === '/admin/vendors' && $method === 'POST' && isset($_POST['vendor_id'], $_POST['action'])) {
        requireCsrfOrAbort();
        foreach ($vendors as &$v) {
            if (($v['vendor_id'] ?? '') !== $_POST['vendor_id']) continue;
            if ($_POST['action'] === 'suspend') $v['account_status'] = 'suspended';
            if ($_POST['action'] === 'activate' && ($v['verification_status'] ?? '') === 'verified') $v['account_status'] = 'active';
            if ($_POST['action'] === 'cancel') { $v['account_status'] = 'cancelled'; SubscriptionService::assignForVendor($v, ['subscription_status' => 'cancelled']); }
            $v['updated_at'] = date('c');
            break;
        }
        unset($v);
        RegistryService::put('vendors', $vendors);
        redirectTo('/admin/vendors');
    }

    if ($path === '/admin/modules' && $method === 'POST' && isset($_POST['module_key'])) {
        requireCsrfOrAbort();
        $all = RegistryService::get('modules');
        foreach ($all as &$m) {
            if (($m['module_key'] ?? '') !== $_POST['module_key']) continue;
            $m['module_name'] = trim((string) ($_POST['module_name'] ?? $m['module_name']));
            $m['description'] = trim((string) ($_POST['description'] ?? $m['description']));
            $m['monthly_price'] = (float) ($_POST['monthly_price'] ?? $m['monthly_price']);
            $m['quarterly_price'] = ($_POST['quarterly_price'] ?? '') === '' ? null : (float) $_POST['quarterly_price'];
            $m['yearly_price'] = ($_POST['yearly_price'] ?? '') === '' ? null : (float) $_POST['yearly_price'];
            $m['optional_setup_fee'] = (float) ($_POST['optional_setup_fee'] ?? 0);
            $m['enabled_flag'] = isset($_POST['enabled_flag']);
            $m['is_core_included'] = isset($_POST['is_core_included']);
            $m['dependency_list'] = parseKeys((string) ($_POST['dependency_list'] ?? ''));
            $m['nav_label'] = trim((string) ($_POST['nav_label'] ?? $m['nav_label']));
            $m['nav_order'] = (int) ($_POST['nav_order'] ?? $m['nav_order']);
            break;
        }
        unset($m);
        RegistryService::put('modules', $all);
        SubscriptionService::normalizeAll();
        redirectTo('/admin/modules');
    }

    if ($path === '/admin/plans' && $method === 'POST' && isset($_POST['plan_key'])) {
        requireCsrfOrAbort();
        $all = RegistryService::get('plans');
        foreach ($all as &$p) {
            if (($p['plan_key'] ?? '') !== $_POST['plan_key']) continue;
            $p['plan_name'] = trim((string) ($_POST['plan_name'] ?? $p['plan_name']));
            $p['description'] = trim((string) ($_POST['description'] ?? $p['description']));
            $p['scheme_key'] = trim((string) ($_POST['scheme_key'] ?? $p['scheme_key']));
            $p['included_modules'] = array_values(array_intersect(parseKeys((string) ($_POST['included_modules'] ?? '')), array_column($modules, 'module_key')));
            $p['monthly_price'] = (float) ($_POST['monthly_price'] ?? $p['monthly_price']);
            $p['quarterly_price'] = ($_POST['quarterly_price'] ?? '') === '' ? null : (float) $_POST['quarterly_price'];
            $p['yearly_price'] = ($_POST['yearly_price'] ?? '') === '' ? null : (float) $_POST['yearly_price'];
            $p['trial_days'] = (int) ($_POST['trial_days'] ?? $p['trial_days']);
            $p['recommended_flag'] = isset($_POST['recommended_flag']);
            $p['active_flag'] = isset($_POST['active_flag']);
            break;
        }
        unset($p);
        RegistryService::put('plans', $all);
        SubscriptionService::normalizeAll();
        redirectTo('/admin/plans');
    }

    if ($path === '/admin/subscriptions' && $method === 'POST' && isset($_POST['vendor_id'])) {
        requireCsrfOrAbort();
        $vendor = RegistryService::getVendorById((string) $_POST['vendor_id']);
        if ($vendor) {
            SubscriptionService::assignForVendor($vendor, [
                'plan_key' => (string) ($_POST['plan_key'] ?? ''),
                'subscription_mode' => (string) ($_POST['subscription_mode'] ?? 'plan'),
                'billing_cycle' => (string) ($_POST['billing_cycle'] ?? 'monthly'),
                'subscription_status' => (string) ($_POST['subscription_status'] ?? 'trial'),
                'trial_days_assigned' => (int) ($_POST['trial_days_assigned'] ?? 0),
                'direct_module_keys' => parseKeys((string) ($_POST['direct_module_keys'] ?? '')),
                'addon_module_keys' => parseKeys((string) ($_POST['addon_module_keys'] ?? '')),
                'removed_module_keys' => parseKeys((string) ($_POST['removed_module_keys'] ?? '')),
                'override_pricing_json' => (string) ($_POST['override_pricing_json'] ?? '{}'),
                'source_type' => 'admin',
                'source_ref' => $admin['admin_id'],
            ]);
        }
        redirectTo('/admin/subscriptions');
    }

    if ($path === '/admin/schemes') render('Schemes', 'schemes', compact('schemes'), 'admin');
    if ($path === '/admin/modules') render('Modules', 'modules', ['modules' => ModuleService::normalizedAll(), 'csrfToken' => $csrfToken], 'admin');
    if ($path === '/admin/plans') render('Plans', 'plans', ['plans' => PlanService::normalizedAll(), 'modules' => ModuleService::normalizedAll(), 'csrfToken' => $csrfToken], 'admin');
    if ($path === '/admin/subscriptions') {
        $subscriptions = RegistryService::get('subscriptions');
        render('Subscriptions', 'subscriptions', compact('vendors', 'subscriptions', 'plans', 'modules', 'csrfToken'), 'admin');
    }
    if ($path === '/admin/vendors') {
        $subscriptionsByVendor = [];
        foreach (RegistryService::get('subscriptions') as $sub) $subscriptionsByVendor[$sub['vendor_id']] = $sub;
        render('Vendors', 'vendors', compact('vendors', 'subscriptionsByVendor', 'csrfToken'), 'admin');
    }
    if ($path === '/admin/settings') {
        if ($method === 'POST') {
            requireCsrfOrAbort();
            $settings['platform_name'] = trim((string) ($_POST['platform_name'] ?? 'Yojak'));
            $settings['allow_signup_globally'] = isset($_POST['allow_signup_globally']);
            $settings['demo_mode'] = isset($_POST['demo_mode']);
            $settings['default_trial_plan_key'] = (string) ($_POST['default_trial_plan_key'] ?? 'growth');
            $settings['default_billing_cycle'] = BillingCycleService::normalize((string) ($_POST['default_billing_cycle'] ?? 'monthly'));
            $settings['updated_at'] = date('c');
            RegistryService::put('superadmin_settings', [$settings]);
        }
        render('Settings', 'settings', compact('settings', 'plans', 'csrfToken'), 'admin');
    }
}

if (str_starts_with($path, '/app')) {
    $vendor = requireVendor();
    $subscription = RegistryService::getSubscriptionForVendor((string) $vendor['vendor_id']);

    if ($path === '/app' || $path === '/app/dashboard') {
        render('Vendor Dashboard', 'dashboard', ['vendor' => $vendor, 'cards' => ['lead_count' => 0, 'draft_quotations' => 0, 'pending_agreements' => 0, 'open_complaints' => 0]], 'vendor');
    }
    if ($path === '/app/profile') render('Profile', 'profile', compact('vendor'), 'vendor');
    if ($path === '/app/subscription') render('Subscription', 'subscription', compact('vendor', 'subscription'), 'vendor');

    $moduleRoutes = [
        '/app/pm-surya-ghar/dashboard' => ['dashboard', 'PM Surya Ghar Dashboard'], '/app/pm-surya-ghar/leads' => ['leads', 'Leads'], '/app/pm-surya-ghar/customers' => ['customers', 'Customers'], '/app/pm-surya-ghar/quotations' => ['quotations', 'Quotations'], '/app/pm-surya-ghar/solar-finance' => ['solar-finance', 'Solar and Finance'], '/app/pm-surya-ghar/agreements' => ['agreements', 'Agreements'], '/app/pm-surya-ghar/payment-receipts' => ['payment-receipts', 'Payment Receipts'], '/app/pm-surya-ghar/invoices' => ['invoices', 'Invoices'], '/app/pm-surya-ghar/complaints' => ['complaints', 'Complaints'], '/app/pm-surya-ghar/templates-media' => ['templates-media', 'Templates & Media'], '/app/pm-surya-ghar/messaging-templates' => ['messaging-templates', 'Messaging Templates'], '/app/pm-surya-ghar/explainer-content' => ['explainer-content', 'Explainer Content'], '/app/pm-surya-ghar/rate-chart' => ['rate-chart', 'Rate Chart'], '/app/pm-surya-ghar/reports-exports' => ['reports-exports', 'Reports & Exports'], '/app/pm-surya-ghar/company-branding' => ['company-branding', 'Company Profile & Branding'], '/app/pm-surya-ghar/subscription-billing' => ['subscription-billing', 'Subscription & Billing'], '/app/pm-surya-ghar/scheme-settings' => ['scheme-settings', 'Scheme Settings'],
    ];
    if (isset($moduleRoutes[$path])) {
        [$moduleKey, $label] = $moduleRoutes[$path]; $schemeKey = 'pm_surya_ghar'; requireModuleAccess($vendor, $schemeKey, $moduleKey);
        render($label, 'module', ['vendor' => $vendor, 'moduleKey' => $moduleKey, 'schemeKey' => $schemeKey, 'title' => $label, 'description' => 'Module not implemented yet. This is a placeholder shell.'], 'vendor');
    }
}

http_response_code(404);
echo '404 Not Found';

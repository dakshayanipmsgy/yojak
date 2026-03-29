<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Services\AccessService;
use App\Services\AdminService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\BillingCycleService;
use App\Services\ModuleService;
use App\Services\PlanService;
use App\Services\ProvisioningService;
use App\Services\RegistryService;
use App\Services\SessionService;
use App\Services\SignupService;
use App\Services\SubscriptionService;
use App\Services\TenantStorageService;

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
function badgeClass(string $value): string { return match ($value) { 'active', 'verified' => 'badge ok', 'pending', 'trial' => 'badge warn', 'suspended' => 'badge warn', 'cancelled', 'rejected', 'expired' => 'badge bad', default => 'badge', }; }

$schemes = RegistryService::get('schemes');
$modules = ModuleService::normalizedAll();
$plans = PlanService::normalizedAll();
$settings = RegistryService::get('superadmin_settings')[0] ?? [];
$csrfToken = SessionService::csrfToken();

if ($path === '/logout') { AuthService::logoutVendor(); redirectTo('/login'); }
if ($path === '/admin/logout') { AuthService::logoutAdmin(); redirectTo('/admin/login'); }

if ($path === '/' || $path === '/homepage') {
    $publicSchemes = array_values(array_filter($schemes, fn(array $s): bool => !empty($s['public_visible']) && !empty($s['active_flag'])));
    usort($publicSchemes, fn(array $a, array $b): int => (int) ($a['public_sort_order'] ?? 999) <=> (int) ($b['public_sort_order'] ?? 999));
    render('Yojak - Platform', 'home', compact('publicSchemes', 'settings'));
}

if ($path === '/schemes') {
    $publicSchemes = array_values(array_filter($schemes, fn(array $s): bool => !empty($s['public_visible']) && !empty($s['active_flag'])));
    usort($publicSchemes, fn(array $a, array $b): int => (int) ($a['public_sort_order'] ?? 999) <=> (int) ($b['public_sort_order'] ?? 999));
    render('Schemes', 'schemes', compact('publicSchemes', 'settings'));
}

if (preg_match('#^/scheme/([a-z0-9_\-]+)$#', $path, $m)) {
    $slug = $m[1];
    $schemeKey = str_replace('-', '_', $slug);
    $scheme = RegistryService::getSchemeByKey($schemeKey);
    if (!$scheme || empty($scheme['active_flag']) || empty($scheme['public_visible'])) {
        http_response_code(404);
        echo 'Scheme not available.';
        exit;
    }
    $schemePlans = array_values(array_filter($plans, fn(array $p): bool => ($p['scheme_key'] ?? '') === $schemeKey && !empty($p['active_flag'])));
    render((string) ($scheme['public_title'] ?? 'Scheme'), 'scheme', compact('scheme', 'schemePlans', 'slug'));
}

if ($path === '/pricing') render('Pricing', 'pricing', ['plans' => $plans, 'modules' => $modules, 'cycle' => BillingCycleService::normalize((string) ($_GET['cycle'] ?? 'monthly'))]);
if ($path === '/signup') redirectTo('/schemes');

if (preg_match('#^/signup/([a-z0-9_\-]+)$#', $path, $m)) {
    $slug = $m[1];
    $schemeKey = str_replace('-', '_', $slug);
    $scheme = RegistryService::getSchemeByKey($schemeKey);
    if (!$scheme || empty($scheme['active_flag']) || empty($scheme['public_visible'])) {
        http_response_code(404);
        echo 'Signup unavailable for this scheme.';
        exit;
    }

    if ($method === 'GET') {
        render('Vendor Signup', 'signup', ['csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => $scheme, 'slug' => $slug]);
    }
    if ($method === 'POST') {
        requireCsrfOrAbort();
        [$ok, $error] = SignupService::validateSignupInput($_POST, $settings, $scheme);
        if (!$ok) render('Vendor Signup', 'signup', ['error' => $error, 'csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => $scheme, 'slug' => $slug]);
        $pending = RegistryService::get('pending_signups');
        $vendors = RegistryService::get('vendors');
        $email = SignupService::normalizeEmail((string) ($_POST['email'] ?? ''));
        $mobile = SignupService::normalizeMobile((string) ($_POST['mobile'] ?? ''));
        $duplicate = SignupService::findDuplicate($pending, $vendors, $email, $mobile);
        if ($duplicate) render('Vendor Signup', 'signup', ['error' => $duplicate, 'csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => $scheme, 'slug' => $slug]);
        $signup = SignupService::buildPendingSignup($_POST, $schemeKey);
        $pending[] = $signup;
        RegistryService::put('pending_signups', $pending);
        render('Vendor Signup', 'signup', ['success' => 'Signup received. Your account is pending superadmin verification. Login is unavailable until approval.', 'csrfToken' => $csrfToken, 'settings' => $settings, 'scheme' => $scheme, 'slug' => $slug]);
    }
}

if ($path === '/login' && $method === 'GET') { if (AuthService::vendor()) redirectTo('/app/dashboard'); render('Vendor Login', 'login', ['error' => $_GET['error'] ?? null, 'csrfToken' => $csrfToken]); }
if ($path === '/login' && $method === 'POST') { requireCsrfOrAbort(); [$ok, $error] = AuthService::loginVendor((string) ($_POST['identifier'] ?? ''), (string) ($_POST['password'] ?? '')); if ($ok) redirectTo('/app/dashboard'); render('Vendor Login', 'login', compact('error', 'csrfToken')); }
if ($path === '/admin/login' && $method === 'GET') { if (AuthService::admin()) redirectTo('/admin/dashboard'); render('Admin Login', 'login', ['admin' => true, 'csrfToken' => $csrfToken]); }
if ($path === '/admin/login' && $method === 'POST') { requireCsrfOrAbort(); if (AuthService::loginAdmin((string) ($_POST['email'] ?? ''), (string) ($_POST['password'] ?? ''))) redirectTo('/admin/dashboard'); render('Admin Login', 'login', ['admin' => true, 'error' => 'Invalid credentials', 'csrfToken' => $csrfToken]); }

if (str_starts_with($path, '/admin')) {
    $admin = requireAdmin();
    $vendors = RegistryService::get('vendors');
    $pending = RegistryService::get('pending_signups');
    $subs = RegistryService::get('subscriptions');
    $subscriptionsByVendor = [];
    foreach ($subs as $sub) {
        $subscriptionsByVendor[$sub['vendor_id']] = $sub;
    }

    if ($path === '/admin' || $path === '/admin/dashboard') {
        $counts = AdminService::dashboardCounts();
        $recentPending = array_slice(array_values(array_reverse($pending)), 0, 5);
        $recentVendors = array_slice(array_values(array_reverse($vendors)), 0, 5);
        $vendorsByPlan = AdminService::vendorsByPlan();
        $vendorsByScheme = AdminService::vendorsByScheme();
        render('Admin Dashboard', 'dashboard', compact('counts', 'recentPending', 'recentVendors', 'vendorsByPlan', 'vendorsByScheme'), 'admin');
    }

    if ($path === '/admin/pending-signups') {
        if ($method === 'POST' && isset($_POST['action'], $_POST['signup_id'])) {
            requireCsrfOrAbort();
            $action = (string) $_POST['action'];
            foreach ($pending as &$row) {
                if (($row['signup_id'] ?? '') !== $_POST['signup_id']) continue;
                if (($row['verification_status'] ?? 'pending') !== 'pending') break;
                if ($action === 'reject') {
                    $row['verification_status'] = 'rejected';
                    $row['processed_at'] = date('c');
                    $row['processed_by'] = $admin['admin_id'];
                    $row['process_note'] = SignupService::sanitizeText((string) ($_POST['process_note'] ?? 'Rejected by admin'));
                    AuditService::log('signup_rejected', 'admin', $admin['admin_id'], 'signup', (string) $row['signup_id'], 'Signup rejected by superadmin.', ['reason_note' => $row['process_note']]);
                    redirectTo('/admin/pending-signups?ok=Signup rejected');
                }
                if ($action === 'verify') {
                    $planKey = trim((string) ($_POST['plan_key'] ?? ($settings['default_trial_plan_key'] ?? '')));
                    $plan = RegistryService::getPlanByKey($planKey) ?? ($plans[0] ?? ['plan_key' => 'growth', 'trial_days' => 14]);
                    $billingCycle = BillingCycleService::normalize((string) ($_POST['billing_cycle'] ?? ($settings['default_billing_cycle'] ?? 'monthly')));
                    $trialDays = max(0, (int) ($_POST['trial_days'] ?? ($plan['trial_days'] ?? 14)));
                    $schemeKeys = parseKeys((string) ($_POST['enabled_scheme_keys'] ?? ($row['requested_scheme_key'] ?? 'pm_surya_ghar')));
                    $vendor = ProvisioningService::provisionTenantForApprovedSignup($row, $schemeKeys, (string) ($plan['plan_key'] ?? 'growth'), $billingCycle, $trialDays, $admin['admin_id'], true);
                    $row['verification_status'] = 'verified';
                    $row['processed_at'] = date('c');
                    $row['processed_by'] = $admin['admin_id'];
                    $row['process_note'] = 'Verified and provisioned as vendor ' . $vendor['vendor_id'];
                    AuditService::log('signup_verified', 'admin', $admin['admin_id'], 'signup', (string) $row['signup_id'], 'Signup verified and vendor provisioned.', ['vendor_id' => $vendor['vendor_id'], 'plan_key' => $plan['plan_key'] ?? null, 'billing_cycle' => $billingCycle, 'trial_days' => $trialDays]);
                    redirectTo('/admin/pending-signups?ok=Signup verified');
                }
                break;
            }
            unset($row);
            RegistryService::put('pending_signups', $pending);
            redirectTo('/admin/pending-signups');
        }

        $statusFilter = (string) ($_GET['status'] ?? 'all');
        $search = strtolower(trim((string) ($_GET['q'] ?? '')));
        $filteredPending = array_values(array_filter($pending, function (array $row) use ($statusFilter, $search): bool {
            $status = (string) ($row['verification_status'] ?? 'pending');
            if ($statusFilter !== 'all' && $status !== $statusFilter) {
                return false;
            }
            if ($search === '') {
                return true;
            }
            $hay = strtolower(implode(' ', [(string) ($row['company_name'] ?? ''), (string) ($row['email'] ?? ''), (string) ($row['mobile'] ?? '')]));
            return str_contains($hay, $search);
        }));

        render('Pending Signups', 'pending_signups', compact('pending', 'filteredPending', 'csrfToken', 'statusFilter', 'search', 'plans', 'schemes', 'settings'), 'admin');
    }

    if ($path === '/admin/pending-signups/view') {
        $signupId = (string) ($_GET['id'] ?? '');
        $signup = RegistryService::getPendingSignupById($signupId);
        if (!$signup) {
            http_response_code(404);
            echo 'Signup not found';
            exit;
        }
        render('Pending Signup Detail', 'pending_signup_view', compact('signup', 'csrfToken', 'plans', 'settings', 'schemes'), 'admin');
    }

    if ($path === '/admin/vendors' && $method === 'POST' && isset($_POST['vendor_id'], $_POST['action'])) {
        requireCsrfOrAbort();
        $action = (string) $_POST['action'];
        foreach ($vendors as &$v) {
            if (($v['vendor_id'] ?? '') !== $_POST['vendor_id']) continue;
            if ($action === 'suspend') { $v['account_status'] = 'suspended'; AuditService::log('vendor_suspended', 'admin', $admin['admin_id'], 'vendor', (string) $v['vendor_id'], 'Vendor account suspended.'); }
            if ($action === 'activate' && ($v['verification_status'] ?? '') === 'verified') { $v['account_status'] = 'active'; AuditService::log('vendor_activated', 'admin', $admin['admin_id'], 'vendor', (string) $v['vendor_id'], 'Vendor account activated.'); }
            if ($action === 'cancel') { $v['account_status'] = 'cancelled'; SubscriptionService::assignForVendor($v, ['subscription_status' => 'cancelled', 'source_type' => 'admin', 'source_ref' => $admin['admin_id']]); AuditService::log('vendor_cancelled', 'admin', $admin['admin_id'], 'vendor', (string) $v['vendor_id'], 'Vendor account cancelled.'); }
            if ($action === 'refresh_entitlements') { SubscriptionService::refreshVendorBySubscription((string) $v['vendor_id']); AuditService::log('tenant_entitlements_refreshed', 'admin', $admin['admin_id'], 'vendor', (string) $v['vendor_id'], 'Vendor entitlements refreshed from subscription.'); }
            if ($action === 'repair_storage') { App\Services\BootstrapService::repairTenantStorage((string) $v['tenant_id']); AuditService::log('tenant_storage_repaired', 'admin', $admin['admin_id'], 'tenant', (string) $v['tenant_id'], 'Tenant storage repaired from admin panel.', ['vendor_id' => $v['vendor_id']]); }
            $v['updated_at'] = date('c');
            break;
        }
        unset($v);
        RegistryService::put('vendors', $vendors);
        redirectTo('/admin/vendors?ok=Vendor updated');
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
            $m['scheme_keys'] = parseKeys((string) ($_POST['scheme_keys'] ?? implode(',', (array) ($m['scheme_keys'] ?? []))));
            $m['updated_at'] = date('c');
            AuditService::log('module_updated', 'admin', $admin['admin_id'], 'module', (string) ($m['module_key'] ?? ''), 'Module registry updated.', ['module_key' => $m['module_key']]);
            break;
        }
        unset($m);
        RegistryService::put('modules', $all);
        SubscriptionService::normalizeAll();
        redirectTo('/admin/modules?ok=Module saved');
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
            $p['excluded_modules'] = parseKeys((string) ($_POST['excluded_modules'] ?? implode(',', (array) ($p['excluded_modules'] ?? []))));
            $p['monthly_price'] = (float) ($_POST['monthly_price'] ?? $p['monthly_price']);
            $p['quarterly_price'] = ($_POST['quarterly_price'] ?? '') === '' ? null : (float) $_POST['quarterly_price'];
            $p['yearly_price'] = ($_POST['yearly_price'] ?? '') === '' ? null : (float) $_POST['yearly_price'];
            $p['trial_days'] = (int) ($_POST['trial_days'] ?? $p['trial_days']);
            $p['recommended_flag'] = isset($_POST['recommended_flag']);
            $p['active_flag'] = isset($_POST['active_flag']);
            $p['updated_at'] = date('c');
            AuditService::log('plan_updated', 'admin', $admin['admin_id'], 'plan', (string) ($p['plan_key'] ?? ''), 'Plan registry updated.', ['plan_key' => $p['plan_key']]);
            break;
        }
        unset($p);
        RegistryService::put('plans', $all);
        SubscriptionService::normalizeAll();
        redirectTo('/admin/plans?ok=Plan saved');
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
            AuditService::log('vendor_subscription_updated', 'admin', $admin['admin_id'], 'vendor', (string) $vendor['vendor_id'], 'Vendor subscription updated from admin.', ['plan_key' => $_POST['plan_key'] ?? null, 'status' => $_POST['subscription_status'] ?? null]);
        }
        redirectTo('/admin/subscriptions?ok=Subscription saved');
    }

    if ($path === '/admin/schemes' && $method === 'POST' && isset($_POST['scheme_key'])) {
        requireCsrfOrAbort();
        foreach ($schemes as &$scheme) {
            if (($scheme['scheme_key'] ?? '') !== $_POST['scheme_key']) {
                continue;
            }
            $scheme['scheme_name'] = trim((string) ($_POST['scheme_name'] ?? $scheme['scheme_name']));
            $scheme['public_title'] = trim((string) ($_POST['public_title'] ?? $scheme['public_title']));
            $scheme['description'] = trim((string) ($_POST['description'] ?? $scheme['description']));
            $scheme['active_flag'] = isset($_POST['active_flag']);
            $scheme['public_visible'] = isset($_POST['public_visible']);
            $scheme['signup_enabled'] = isset($_POST['signup_enabled']);
            $scheme['public_sort_order'] = (int) ($_POST['public_sort_order'] ?? $scheme['public_sort_order']);
            $scheme['workflow_definition_ref'] = trim((string) ($_POST['workflow_definition_ref'] ?? ($scheme['workflow_definition_ref'] ?? '')));
            $scheme['updated_at'] = date('c');
            AuditService::log('scheme_updated', 'admin', $admin['admin_id'], 'scheme', (string) $scheme['scheme_key'], 'Scheme registry updated.', ['scheme_key' => $scheme['scheme_key']]);
            break;
        }
        unset($scheme);
        RegistryService::put('schemes', $schemes);
        redirectTo('/admin/schemes?ok=Scheme saved');
    }

    if ($path === '/admin/vendors') {
        $q = strtolower(trim((string) ($_GET['q'] ?? '')));
        $verificationFilter = (string) ($_GET['verification_status'] ?? 'all');
        $accountFilter = (string) ($_GET['account_status'] ?? 'all');
        $subscriptionFilter = (string) ($_GET['subscription_status'] ?? 'all');
        $planFilter = (string) ($_GET['plan_key'] ?? 'all');
        $schemeFilter = (string) ($_GET['scheme_key'] ?? 'all');
        $sortBy = (string) ($_GET['sort'] ?? 'newest');

        $filteredVendors = array_values(array_filter($vendors, function (array $v) use ($q, $verificationFilter, $accountFilter, $subscriptionFilter, $planFilter, $schemeFilter, $subscriptionsByVendor): bool {
            $sub = $subscriptionsByVendor[$v['vendor_id']] ?? null;
            if ($verificationFilter !== 'all' && ($v['verification_status'] ?? '') !== $verificationFilter) return false;
            if ($accountFilter !== 'all' && ($v['account_status'] ?? '') !== $accountFilter) return false;
            if ($subscriptionFilter !== 'all' && (($sub['subscription_status'] ?? 'none') !== $subscriptionFilter)) return false;
            if ($planFilter !== 'all' && (($sub['plan_key'] ?? ($v['current_plan_key'] ?? '')) !== $planFilter)) return false;
            if ($schemeFilter !== 'all' && !in_array($schemeFilter, (array) ($v['enabled_schemes'] ?? []), true)) return false;
            if ($q !== '') {
                $hay = strtolower(implode(' ', [(string) ($v['vendor_id'] ?? ''), (string) ($v['company_name'] ?? ''), (string) ($v['email'] ?? ''), (string) ($v['mobile'] ?? '')]));
                if (!str_contains($hay, $q)) return false;
            }
            return true;
        }));

        usort($filteredVendors, function (array $a, array $b) use ($sortBy, $subscriptionsByVendor): int {
            $subA = $subscriptionsByVendor[$a['vendor_id']] ?? [];
            $subB = $subscriptionsByVendor[$b['vendor_id']] ?? [];
            return match ($sortBy) {
                'company' => strcmp((string) ($a['company_name'] ?? ''), (string) ($b['company_name'] ?? '')),
                'status' => strcmp((string) ($a['account_status'] ?? ''), (string) ($b['account_status'] ?? '')),
                'renewal' => strcmp((string) ($subA['renewal_date'] ?? ''), (string) ($subB['renewal_date'] ?? '')),
                default => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')),
            };
        });

        render('Vendors', 'vendors', compact('vendors', 'filteredVendors', 'subscriptionsByVendor', 'csrfToken', 'verificationFilter', 'accountFilter', 'subscriptionFilter', 'planFilter', 'schemeFilter', 'sortBy', 'q', 'plans', 'schemes'), 'admin');
    }

    if ($path === '/admin/vendors/view') {
        $vendorId = (string) ($_GET['id'] ?? '');
        $vendor = RegistryService::getVendorById($vendorId);
        if (!$vendor) {
            http_response_code(404);
            echo 'Vendor not found';
            exit;
        }
        $subscription = RegistryService::getSubscriptionForVendor($vendorId);
        $tenantPath = TenantStorageService::getTenantPath((string) $vendor['tenant_id']);
        $tenantMetaPath = $tenantPath . '/meta/tenant_meta.json';
        $tenantMeta = file_exists($tenantMetaPath) ? RegistryService::getConfigData($tenantMetaPath) : [];
        render('Vendor Detail', 'vendor_view', compact('vendor', 'subscription', 'tenantPath', 'tenantMeta', 'csrfToken'), 'admin');
    }

    if ($path === '/admin/schemes') {
        $search = strtolower(trim((string) ($_GET['q'] ?? '')));
        $filteredSchemes = array_values(array_filter($schemes, function (array $s) use ($search): bool {
            if ($search === '') return true;
            $hay = strtolower(implode(' ', [(string) ($s['scheme_key'] ?? ''), (string) ($s['scheme_name'] ?? ''), (string) ($s['public_title'] ?? '')]));
            return str_contains($hay, $search);
        }));
        render('Schemes', 'schemes', compact('schemes', 'filteredSchemes', 'csrfToken', 'search'), 'admin');
    }

    if ($path === '/admin/modules') {
        $search = strtolower(trim((string) ($_GET['q'] ?? '')));
        $status = (string) ($_GET['status'] ?? 'all');
        $filteredModules = array_values(array_filter(ModuleService::normalizedAll(), function (array $m) use ($search, $status): bool {
            if ($status === 'enabled' && empty($m['enabled_flag'])) return false;
            if ($status === 'disabled' && !empty($m['enabled_flag'])) return false;
            if ($search === '') return true;
            $hay = strtolower(implode(' ', [(string) ($m['module_key'] ?? ''), (string) ($m['module_name'] ?? ''), (string) ($m['nav_label'] ?? '')]));
            return str_contains($hay, $search);
        }));
        render('Modules', 'modules', ['modules' => $filteredModules, 'csrfToken' => $csrfToken, 'search' => $search, 'status' => $status, 'schemes' => $schemes], 'admin');
    }

    if ($path === '/admin/plans') {
        $search = strtolower(trim((string) ($_GET['q'] ?? '')));
        $schemeFilter = (string) ($_GET['scheme_key'] ?? 'all');
        $filteredPlans = array_values(array_filter(PlanService::normalizedAll(), function (array $p) use ($search, $schemeFilter): bool {
            if ($schemeFilter !== 'all' && ($p['scheme_key'] ?? '') !== $schemeFilter) return false;
            if ($search === '') return true;
            $hay = strtolower(implode(' ', [(string) ($p['plan_key'] ?? ''), (string) ($p['plan_name'] ?? ''), (string) ($p['description'] ?? '')]));
            return str_contains($hay, $search);
        }));
        render('Plans', 'plans', ['plans' => $filteredPlans, 'modules' => ModuleService::normalizedAll(), 'csrfToken' => $csrfToken, 'search' => $search, 'schemeFilter' => $schemeFilter, 'schemes' => $schemes], 'admin');
    }

    if ($path === '/admin/subscriptions') {
        $subscriptions = RegistryService::get('subscriptions');
        render('Subscriptions', 'subscriptions', compact('vendors', 'subscriptions', 'plans', 'modules', 'csrfToken'), 'admin');
    }

    if ($path === '/admin/settings') {
        if ($method === 'POST') {
            requireCsrfOrAbort();
            $settings['platform_name'] = trim((string) ($_POST['platform_name'] ?? 'Yojak'));
            $settings['allow_signup_globally'] = isset($_POST['allow_signup_globally']);
            $settings['maintenance_mode'] = isset($_POST['maintenance_mode']);
            $settings['demo_mode'] = isset($_POST['demo_mode']);
            $settings['public_footer_text'] = trim((string) ($_POST['public_footer_text'] ?? ($settings['public_footer_text'] ?? '')));
            $settings['default_trial_plan_key'] = (string) ($_POST['default_trial_plan_key'] ?? 'growth');
            $settings['default_billing_cycle'] = BillingCycleService::normalize((string) ($_POST['default_billing_cycle'] ?? 'monthly'));
            $settings['updated_at'] = date('c');
            RegistryService::put('superadmin_settings', [$settings]);
            AuditService::log('platform_settings_updated', 'admin', $admin['admin_id'], 'settings', 'superadmin_settings', 'Platform settings updated.', ['default_trial_plan_key' => $settings['default_trial_plan_key'], 'default_billing_cycle' => $settings['default_billing_cycle']]);
            redirectTo('/admin/settings?ok=Settings saved');
        }
        $defaultsSummary = AdminService::defaultsSummary();
        render('Settings', 'settings', compact('settings', 'plans', 'csrfToken', 'defaultsSummary'), 'admin');
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

<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Services\AccessService;
use App\Services\AdminService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\BillingCycleService;
use App\Services\ModuleService;
use App\Services\PmSuryaGharOpsService;
use App\Services\PlanService;
use App\Services\ProvisioningService;
use App\Services\RegistryService;
use App\Services\SessionService;
use App\Services\SignupService;
use App\Services\SchemeWorkspaceService;
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
if ($path === '/login' && $method === 'POST') {
    requireCsrfOrAbort();
    [$ok, $error] = AuthService::loginVendor((string) ($_POST['identifier'] ?? ''), (string) ($_POST['password'] ?? ''));
    if ($ok) {
        $vendor = AuthService::vendor() ?? [];
        $defaultLanding = '/app/dashboard';
        if ($vendor !== [] && AccessService::hasSchemeAccess($vendor, 'pm_surya_ghar')) {
            $defaultLanding = '/app/pm-surya-ghar/dashboard';
        }
        redirectTo($defaultLanding);
    }
    render('Vendor Login', 'login', compact('error', 'csrfToken'));
}
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


if ($path === '/app/pm-surya-ghar/quotations/public') {
    $tenantId = (string) ($_GET['tenant_id'] ?? '');
    $token = (string) ($_GET['token'] ?? '');
    if ($tenantId === '' || $token === '') {
        http_response_code(404);
        echo 'Quotation not found';
        exit;
    }
    $quotes = TenantStorageService::getTenantSchemeRecords($tenantId, 'pm_surya_ghar', 'quotations');
    $quotation = null;
    foreach ($quotes as $q) {
        if (($q['public_share_enabled'] ?? false) && (string) ($q['public_share_token'] ?? '') === $token) {
            $quotation = $q;
            break;
        }
    }
    if ($quotation === null) {
        http_response_code(404);
        echo 'Quotation not found';
        exit;
    }
    render('Public Quotation', 'pm_surya_ghar', ['page' => 'quotation_public', 'data' => ['quotation' => $quotation], 'publicMode' => true], 'vendor');
}

if (str_starts_with($path, '/app')) {
    $vendor = requireVendor();
    $subscription = RegistryService::getSubscriptionForVendor((string) $vendor['vendor_id']);
    $tenantId = (string) ($vendor['tenant_id'] ?? '');
    $enabledSchemes = TenantStorageService::getTenantEnabledSchemes($tenantId);
    $platformDashboardCards = [
        'enabled_schemes' => count($enabledSchemes),
        'enabled_modules' => count((array) ($vendor['enabled_modules'] ?? [])),
        'subscription_status' => (string) ($subscription['subscription_status'] ?? $vendor['subscription_status'] ?? 'unknown'),
    ];
    $schemeSummaries = [];
    if (in_array('pm_surya_ghar', $enabledSchemes, true) && AccessService::hasSchemeAccess($vendor, 'pm_surya_ghar')) {
        $pmWorkspace = SchemeWorkspaceService::pmSuryaGharMetadata($tenantId);

    if (str_starts_with($path, '/app/pm-surya-ghar/')) {
        $schemeKey = 'pm_surya_ghar';
        $subPath = substr($path, strlen('/app/pm-surya-ghar'));

        $moduleMatch = [
            'leads' => '/leads',
            'customers' => '/customers',
            'solar-finance' => '/solar-finance',
            'quotations' => '/quotations',
        ];
        foreach ($moduleMatch as $mod => $prefix) {
            if (str_starts_with($subPath, $prefix)) {
                requireModuleAccess($vendor, $schemeKey, $mod);
            }
        }

        $pageData = ['notice' => null, 'errors' => []];
        if ($method === 'POST') {
            requireCsrfOrAbort();
        }

        if ($subPath === '/leads/sample-csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="leads_sample.csv"');
            echo "contact_name,company_name,mobile,alternate_mobile,email,address,city,state,pincode,monthly_bill,monthly_units,property_type,preferred_system_type,funding_interest,notes,source_type,source_detail,follow_up_date,best_time_to_call
";
            echo "Riya Sharma,Sunrise Homes,9876543210,,riya@example.com,MG Road,Indore,Madhya Pradesh,452001,3500,420,residential,on-grid,self funded,Interested in 3kw,walk_in,expo,2026-04-02,10:00-12:00
";
            exit;
        }

        if (str_starts_with($subPath, '/leads')) {
            $payload = PmSuryaGharOpsService::readRecords($tenantId, 'leads');
            $leads = $payload['items'] ?? [];
            $customersPayload = PmSuryaGharOpsService::readRecords($tenantId, 'customers');
            $customers = $customersPayload['items'] ?? [];
            if ($method === 'POST') {
                $action = (string) ($_POST['action'] ?? 'save');
                if ($action === 'import_preview') {
                    $tmp = [];
                    $headers = [];
                    if (isset($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
                        if (($h = fopen($_FILES['csv_file']['tmp_name'], 'r')) !== false) {
                            $headers = array_map('trim', fgetcsv($h) ?: []);
                            while (($row = fgetcsv($h)) !== false) {
                                $tmp[] = array_combine($headers, array_pad($row, count($headers), '')) ?: [];
                            }
                            fclose($h);
                        }
                    }
                    $_SESSION['lead_import_preview'] = $tmp;
                    $pageData['notice'] = 'CSV preview loaded: ' . count($tmp) . ' rows';
                } elseif ($action === 'import_commit') {
                    $rows = (array) ($_SESSION['lead_import_preview'] ?? []);
                    $summary = ['imported' => 0, 'skipped' => 0, 'errored' => 0];
                    foreach ($rows as $r) {
                        $mobileNorm = PmSuryaGharOpsService::normalizeMobile((string) ($r['mobile'] ?? ''));
                        $emailNorm = PmSuryaGharOpsService::normalizeEmail((string) ($r['email'] ?? ''));
                        if (($r['contact_name'] ?? '') === '' || ($mobileNorm === '' && $emailNorm === '')) {
                            $summary['errored']++;
                            continue;
                        }
                        if (PmSuryaGharOpsService::duplicateLead($leads, $mobileNorm, $emailNorm)) {
                            $summary['skipped']++;
                            continue;
                        }
                        $leadId = PmSuryaGharOpsService::nextSchemeId($tenantId, 'leads', 'LED-');
                        $leads[] = [
                            'lead_id' => $leadId, 'tenant_id' => $tenantId, 'scheme_key' => $schemeKey,
                            'status' => 'new', 'contact_name' => (string) ($r['contact_name'] ?? ''), 'company_name' => (string) ($r['company_name'] ?? ''),
                            'mobile' => (string) ($r['mobile'] ?? ''), 'email' => (string) ($r['email'] ?? ''), 'city' => (string) ($r['city'] ?? ''), 'state' => (string) ($r['state'] ?? ''),
                            'monthly_bill' => (float) ($r['monthly_bill'] ?? 0), 'monthly_units' => (float) ($r['monthly_units'] ?? 0),
                            'property_type' => (string) ($r['property_type'] ?? ''), 'preferred_system_type' => (string) ($r['preferred_system_type'] ?? ''),
                            'funding_interest' => (string) ($r['funding_interest'] ?? ''), 'notes' => (string) ($r['notes'] ?? ''),
                            'source_type' => (string) ($r['source_type'] ?? ''), 'source_detail' => (string) ($r['source_detail'] ?? ''),
                            'follow_up_date' => (string) ($r['follow_up_date'] ?? ''), 'best_time_to_call' => (string) ($r['best_time_to_call'] ?? ''),
                            'intro_message_sent_flag' => false, 'detailed_message_sent_flag' => false, 'call_not_picked_count' => 0, 'archived_flag' => false,
                            'created_at' => date('c'), 'updated_at' => date('c'),
                        ];
                        $summary['imported']++;
                    }
                    $payload['items'] = $leads;
                    PmSuryaGharOpsService::writeRecords($tenantId, 'leads', $payload);
                    PmSuryaGharOpsService::writeIndex($tenantId, 'lead', $leads, 'lead_id');
                    $pageData['notice'] = 'Import complete: ' . json_encode($summary);
                } else {
                    $leadId = (string) ($_POST['lead_id'] ?? '');
                    if ($action === 'create') {
                        $mobileNorm = PmSuryaGharOpsService::normalizeMobile((string) ($_POST['mobile'] ?? ''));
                        $emailNorm = PmSuryaGharOpsService::normalizeEmail((string) ($_POST['email'] ?? ''));
                        if (PmSuryaGharOpsService::duplicateLead($leads, $mobileNorm, $emailNorm)) {
                            $pageData['errors'][] = 'Duplicate lead by mobile/email.';
                        } else {
                            $leadId = PmSuryaGharOpsService::nextSchemeId($tenantId, 'leads', 'LED-');
                            $leads[] = ['lead_id' => $leadId, 'tenant_id' => $tenantId, 'scheme_key' => $schemeKey, 'source_type' => (string) ($_POST['source_type'] ?? ''), 'source_detail' => (string) ($_POST['source_detail'] ?? ''), 'status' => (string) ($_POST['status'] ?? 'new'), 'owner_name' => (string) ($_POST['owner_name'] ?? ''), 'contact_name' => (string) ($_POST['contact_name'] ?? ''), 'company_name' => (string) ($_POST['company_name'] ?? ''), 'mobile' => (string) ($_POST['mobile'] ?? ''), 'alternate_mobile' => (string) ($_POST['alternate_mobile'] ?? ''), 'email' => (string) ($_POST['email'] ?? ''), 'address' => (string) ($_POST['address'] ?? ''), 'city' => (string) ($_POST['city'] ?? ''), 'state' => (string) ($_POST['state'] ?? ''), 'pincode' => (string) ($_POST['pincode'] ?? ''), 'monthly_bill' => (float) ($_POST['monthly_bill'] ?? 0), 'monthly_units' => (float) ($_POST['monthly_units'] ?? 0), 'property_type' => (string) ($_POST['property_type'] ?? ''), 'preferred_system_type' => (string) ($_POST['preferred_system_type'] ?? ''), 'funding_interest' => (string) ($_POST['funding_interest'] ?? ''), 'notes' => (string) ($_POST['notes'] ?? ''), 'tags' => (string) ($_POST['tags'] ?? ''), 'follow_up_date' => (string) ($_POST['follow_up_date'] ?? ''), 'best_time_to_call' => (string) ($_POST['best_time_to_call'] ?? ''), 'call_not_picked_count' => 0, 'intro_message_sent_flag' => false, 'detailed_message_sent_flag' => false, 'archived_flag' => false, 'merge_history' => [], 'created_at' => date('c'), 'updated_at' => date('c'), 'created_by_context' => (string) $vendor['vendor_id']];
                            $pageData['notice'] = 'Lead created: ' . $leadId;
                        }
                    }
                    foreach ($leads as &$lead) {
                        if ((string) ($lead['lead_id'] ?? '') !== $leadId) { continue; }
                        if ($action === 'archive') { $lead['archived_flag'] = true; $lead['status'] = 'archived'; }
                        if ($action === 'call_not_picked') { $lead['call_not_picked_count'] = (int) ($lead['call_not_picked_count'] ?? 0) + 1; $lead['status'] = 'attempted_contact'; }
                        if ($action === 'mark_intro_sent') { $lead['intro_message_sent_flag'] = true; $lead['intro_message_sent_at'] = date('c'); }
                        if ($action === 'mark_detailed_sent') { $lead['detailed_message_sent_flag'] = true; $lead['detailed_message_sent_at'] = date('c'); $lead['status'] = 'information_shared'; }
                        if ($action === 'follow_up') { $lead['follow_up_date'] = (string) ($_POST['follow_up_date'] ?? ''); $lead['last_contact_note'] = (string) ($_POST['last_contact_note'] ?? ''); $lead['last_contact_at'] = date('c'); }
                        if ($action === 'convert_customer') {
                            $mobileNorm = PmSuryaGharOpsService::normalizeMobile((string) ($lead['mobile'] ?? ''));
                            $emailNorm = PmSuryaGharOpsService::normalizeEmail((string) ($lead['email'] ?? ''));
                            $dup = PmSuryaGharOpsService::duplicateCustomer($customers, $mobileNorm, $emailNorm);
                            if ($dup) {
                                $lead['converted_customer_id'] = (string) $dup['customer_id'];
                            } else {
                                $customerId = PmSuryaGharOpsService::nextSchemeId($tenantId, 'customers', 'CUS-');
                                $customers[] = ['customer_id' => $customerId, 'tenant_id' => $tenantId, 'scheme_key' => $schemeKey, 'source_lead_id' => $leadId, 'customer_name' => (string) ($lead['contact_name'] ?? ''), 'company_name' => (string) ($lead['company_name'] ?? ''), 'mobile' => (string) ($lead['mobile'] ?? ''), 'alternate_mobile' => (string) ($lead['alternate_mobile'] ?? ''), 'email' => (string) ($lead['email'] ?? ''), 'address' => (string) ($lead['address'] ?? ''), 'city' => (string) ($lead['city'] ?? ''), 'state' => (string) ($lead['state'] ?? ''), 'pincode' => (string) ($lead['pincode'] ?? ''), 'property_type' => (string) ($lead['property_type'] ?? ''), 'preferred_system_type' => (string) ($lead['preferred_system_type'] ?? ''), 'funding_preference' => (string) ($lead['funding_interest'] ?? ''), 'monthly_bill' => (float) ($lead['monthly_bill'] ?? 0), 'monthly_units' => (float) ($lead['monthly_units'] ?? 0), 'notes' => (string) ($lead['notes'] ?? ''), 'tags' => (string) ($lead['tags'] ?? ''), 'active_flag' => true, 'created_at' => date('c'), 'updated_at' => date('c')];
                                $lead['converted_customer_id'] = $customerId;
                            }
                            $lead['status'] = 'converted_to_customer';
                        }
                        if ($action === 'merge') {
                            $targetId = (string) ($_POST['target_lead_id'] ?? '');
                            foreach ($leads as &$target) {
                                if ((string) ($target['lead_id'] ?? '') !== $targetId) { continue; }
                                $target['notes'] = trim((string) ($target['notes'] ?? '') . "
[Merged {$leadId}] " . (string) ($lead['notes'] ?? ''));
                                $target['merge_history'][] = ['merged_lead_id' => $leadId, 'merged_at' => date('c')];
                                break;
                            }
                            unset($target);
                            $lead['duplicate_master_lead_id'] = $targetId;
                            $lead['archived_flag'] = true;
                            $lead['status'] = 'archived';
                        }
                        $lead['updated_at'] = date('c');
                        break;
                    }
                    unset($lead);
                    $payload['items'] = $leads;
                    $customersPayload['items'] = $customers;
                    PmSuryaGharOpsService::writeRecords($tenantId, 'leads', $payload);
                    PmSuryaGharOpsService::writeRecords($tenantId, 'customers', $customersPayload);
                    PmSuryaGharOpsService::writeIndex($tenantId, 'lead', $leads, 'lead_id');
                    PmSuryaGharOpsService::writeIndex($tenantId, 'customer', $customers, 'customer_id');
                }
            }
            $pageData['leads'] = $leads;
            $pageData['customers'] = $customers;
            $pageData['preview_rows'] = (array) ($_SESSION['lead_import_preview'] ?? []);
            render('Leads', 'pm_surya_ghar', ['vendor' => $vendor, 'workspace' => $pmWorkspace, 'navigation' => SchemeWorkspaceService::buildSchemeNavigation($vendor, $pmWorkspace), 'routeContext' => ['label' => 'Leads'], 'pageContext' => ['context_type' => 'scheme', 'title' => 'Leads'], 'page' => 'leads', 'data' => $pageData, 'csrfToken' => $csrfToken], 'vendor');
        }

        if (str_starts_with($subPath, '/customers')) {
            $payload = PmSuryaGharOpsService::readRecords($tenantId, 'customers');
            $customers = $payload['items'] ?? [];
            if ($method === 'POST') {
                $action = (string) ($_POST['action'] ?? 'create');
                if ($action === 'create') {
                    $mobileNorm = PmSuryaGharOpsService::normalizeMobile((string) ($_POST['mobile'] ?? ''));
                    $emailNorm = PmSuryaGharOpsService::normalizeEmail((string) ($_POST['email'] ?? ''));
                    if (!PmSuryaGharOpsService::duplicateCustomer($customers, $mobileNorm, $emailNorm)) {
                        $id = PmSuryaGharOpsService::nextSchemeId($tenantId, 'customers', 'CUS-');
                        $customers[] = ['customer_id' => $id, 'tenant_id' => $tenantId, 'scheme_key' => $schemeKey, 'source_lead_id' => (string) ($_POST['source_lead_id'] ?? ''), 'customer_name' => (string) ($_POST['customer_name'] ?? ''), 'company_name' => (string) ($_POST['company_name'] ?? ''), 'mobile' => (string) ($_POST['mobile'] ?? ''), 'email' => (string) ($_POST['email'] ?? ''), 'address' => (string) ($_POST['address'] ?? ''), 'city' => (string) ($_POST['city'] ?? ''), 'state' => (string) ($_POST['state'] ?? ''), 'monthly_bill' => (float) ($_POST['monthly_bill'] ?? 0), 'monthly_units' => (float) ($_POST['monthly_units'] ?? 0), 'property_type' => (string) ($_POST['property_type'] ?? ''), 'preferred_system_type' => (string) ($_POST['preferred_system_type'] ?? ''), 'funding_preference' => (string) ($_POST['funding_preference'] ?? ''), 'active_flag' => true, 'created_at' => date('c'), 'updated_at' => date('c')];
                        $pageData['notice'] = 'Customer created';
                    } else {
                        $pageData['errors'][] = 'Duplicate customer by mobile/email';
                    }
                }
                $payload['items'] = $customers;
                PmSuryaGharOpsService::writeRecords($tenantId, 'customers', $payload);
                PmSuryaGharOpsService::writeIndex($tenantId, 'customer', $customers, 'customer_id');
            }
            $pageData['customers'] = $customers;
            $pageData['quotations'] = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'quotations');
            $pageData['solar_finance'] = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'solar_finance');
            render('Customers', 'pm_surya_ghar', ['vendor' => $vendor, 'workspace' => $pmWorkspace, 'navigation' => SchemeWorkspaceService::buildSchemeNavigation($vendor, $pmWorkspace), 'routeContext' => ['label' => 'Customers'], 'pageContext' => ['context_type' => 'scheme', 'title' => 'Customers'], 'page' => 'customers', 'data' => $pageData, 'csrfToken' => $csrfToken], 'vendor');
        }

        if (str_starts_with($subPath, '/solar-finance')) {
            $payload = PmSuryaGharOpsService::readRecords($tenantId, 'solar_finance');
            $items = $payload['items'] ?? [];
            if ($method === 'POST') {
                $action = (string) ($_POST['action'] ?? 'create');
                if ($action === 'create') {
                    $id = PmSuryaGharOpsService::nextSchemeId($tenantId, 'solar_finance', 'SFR-');
                    $input = ['monthly_bill' => (float) ($_POST['monthly_bill'] ?? 0), 'monthly_units' => (float) ($_POST['monthly_units'] ?? 0), 'electricity_rate_assumption' => (float) ($_POST['electricity_rate_assumption'] ?? 8), 'selected_system_size' => (float) ($_POST['selected_system_size'] ?? 0)];
                    $calc = PmSuryaGharOpsService::calculations($input);
                    $rateSnapshot = TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'rate_chart');
                    $calcDefaults = TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'calculations');
                    $finance = ['funding_scenario_preference' => (string) ($_POST['funding_scenario_preference'] ?? 'self_funded')];
                    $snap = ['rate' => PmSuryaGharOpsService::snapshot($tenantId, 'solar_finance', $id . '_rate', $rateSnapshot), 'assumptions' => PmSuryaGharOpsService::snapshot($tenantId, 'solar_finance', $id . '_calc', $calcDefaults), 'finance' => PmSuryaGharOpsService::snapshot($tenantId, 'solar_finance', $id . '_finance', $finance)];
                    $items[] = ['solar_finance_id' => $id, 'tenant_id' => $tenantId, 'scheme_key' => $schemeKey, 'source_lead_id' => (string) ($_POST['source_lead_id'] ?? ''), 'customer_id' => (string) ($_POST['customer_id'] ?? ''), 'status' => 'completed', 'report_title' => (string) ($_POST['report_title'] ?? ('Analysis ' . $id)), 'customer_name_snapshot' => (string) ($_POST['customer_name_snapshot'] ?? ''), 'mobile_snapshot' => (string) ($_POST['mobile_snapshot'] ?? ''), 'city_snapshot' => (string) ($_POST['city_snapshot'] ?? ''), 'state_snapshot' => (string) ($_POST['state_snapshot'] ?? ''), 'input_mode' => (string) ($_POST['input_mode'] ?? 'monthly_bill'), 'monthly_bill' => $input['monthly_bill'], 'monthly_units' => $input['monthly_units'], 'electricity_rate_assumption' => $input['electricity_rate_assumption'], 'property_type' => (string) ($_POST['property_type'] ?? ''), 'system_type' => (string) ($_POST['system_type'] ?? 'on-grid'), 'recommended_system_size' => $calc['recommended_system_size'], 'selected_system_size' => (float) ($_POST['selected_system_size'] ?? $calc['recommended_system_size']), 'on_grid_or_hybrid' => (string) ($_POST['system_type'] ?? 'on-grid'), 'rate_chart_snapshot' => ['file' => $snap['rate']], 'calculations_snapshot' => ['file' => $snap['assumptions']], 'subsidy_snapshot' => ['value' => $calc['pricing']['subsidy']], 'finance_snapshot' => ['file' => $snap['finance']], 'scenario_results' => $calc['funding_options_summary'], 'graphs_data' => $calc['graphs_data'], 'solar_at_a_glance' => $calc['solar_at_a_glance'], 'financial_clarity' => $calc['financial_clarity'], 'monthly_outflow_comparison' => $calc['monthly_outflow_comparison'], 'cumulative_expense_25y' => $calc['cumulative_expense_25y'], 'payback_data' => $calc['payback_data'], 'funding_options_summary' => $calc['funding_options_summary'], 'notes' => (string) ($_POST['notes'] ?? ''), 'created_at' => date('c'), 'updated_at' => date('c')];
                }
                $payload['items'] = $items;
                PmSuryaGharOpsService::writeRecords($tenantId, 'solar_finance', $payload);
                PmSuryaGharOpsService::writeIndex($tenantId, 'solar_finance', $items, 'solar_finance_id');
            }
            $pageData['items'] = $items;
            $pageData['customers'] = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'customers');
            $pageData['leads'] = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'leads');
            render('Solar & Finance', 'pm_surya_ghar', ['vendor' => $vendor, 'workspace' => $pmWorkspace, 'navigation' => SchemeWorkspaceService::buildSchemeNavigation($vendor, $pmWorkspace), 'routeContext' => ['label' => 'Solar & Finance'], 'pageContext' => ['context_type' => 'scheme', 'title' => 'Solar & Finance'], 'page' => 'solar_finance', 'data' => $pageData, 'csrfToken' => $csrfToken], 'vendor');
        }

        if (str_starts_with($subPath, '/quotations')) {
            $payload = PmSuryaGharOpsService::readRecords($tenantId, 'quotations');
            $quotes = $payload['items'] ?? [];
            $customers = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'customers');
            $solarFinance = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'solar_finance');
            if ($method === 'POST') {
                $action = (string) ($_POST['action'] ?? 'create');
                if ($action === 'create' || $action === 'revise') {
                    $sourceQuote = null;
                    $rootId = '';
                    $revNo = 1;
                    if ($action === 'revise') {
                        $sourceId = (string) ($_POST['source_quotation_id'] ?? '');
                        foreach ($quotes as &$q) {
                            if ((string) ($q['quotation_id'] ?? '') === $sourceId) {
                                $sourceQuote = $q;
                                $q['quotation_status'] = 'superseded';
                                break;
                            }
                        }
                        unset($q);
                        $rootId = (string) (($sourceQuote['quotation_root_id'] ?? '') ?: ($sourceQuote['quotation_id'] ?? ''));
                        $revNo = ((int) ($sourceQuote['revision_no'] ?? 1)) + 1;
                    }
                    $id = PmSuryaGharOpsService::nextSchemeId($tenantId, 'quotations', 'QUO-');
                    if ($rootId === '') { $rootId = $id; }
                    $customerId = (string) ($_POST['customer_id'] ?? '');
                    $solarId = (string) ($_POST['source_solar_finance_id'] ?? '');
                    $customerSnapshot = [];
                    foreach ($customers as $c) { if ((string) ($c['customer_id'] ?? '') === $customerId) { $customerSnapshot = $c; break; } }
                    $sfSnapshot = [];
                    foreach ($solarFinance as &$sf) { if ((string) ($sf['solar_finance_id'] ?? '') === $solarId) { $sfSnapshot = $sf; $sf['status'] = 'quoted'; break; } }
                    unset($sf);
                    $rateSnap = TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'rate_chart');
                    $branding = TenantStorageService::getTenantBranding($tenantId);
                    $templates = TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'templates');
                    $snapFiles = ['rate' => PmSuryaGharOpsService::snapshot($tenantId, 'quotations', $id . '_rate', $rateSnap), 'finance' => PmSuryaGharOpsService::snapshot($tenantId, 'quotations', $id . '_finance', (array) ($sfSnapshot['finance_snapshot'] ?? [])), 'branding' => PmSuryaGharOpsService::snapshot($tenantId, 'quotations', $id . '_branding', $branding), 'template' => PmSuryaGharOpsService::snapshot($tenantId, 'quotations', $id . '_template', $templates), 'customer' => PmSuryaGharOpsService::snapshot($tenantId, 'quotations', $id . '_customer', $customerSnapshot)];
                    $quotes[] = ['quotation_id' => $id, 'quotation_root_id' => $rootId, 'revision_no' => $revNo, 'tenant_id' => $tenantId, 'scheme_key' => $schemeKey, 'customer_id' => $customerId, 'source_lead_id' => (string) ($_POST['source_lead_id'] ?? ''), 'source_solar_finance_id' => $solarId, 'quotation_status' => 'draft', 'title' => (string) ($_POST['title'] ?? ('Quotation ' . $id)), 'customer_snapshot' => $customerSnapshot, 'company_branding_snapshot' => ['file' => $snapFiles['branding']], 'template_snapshot' => ['file' => $snapFiles['template']], 'annexure_snapshot' => (array) ($_POST['annexure_snapshot'] ?? []), 'message_context_snapshot' => [], 'rate_chart_snapshot' => ['file' => $snapFiles['rate']], 'calculations_snapshot' => (array) ($sfSnapshot['calculations_snapshot'] ?? []), 'finance_snapshot' => ['file' => $snapFiles['finance']], 'quotation_items' => [['label' => 'Solar System Package', 'amount' => (float) ($_POST['item_amount'] ?? 0)]], 'pricing_summary' => (array) ($sfSnapshot['pricing'] ?? ['total' => (float) ($_POST['item_amount'] ?? 0)]), 'solar_at_a_glance' => (array) ($sfSnapshot['solar_at_a_glance'] ?? []), 'monthly_outflow_comparison' => (array) ($sfSnapshot['monthly_outflow_comparison'] ?? []), 'cumulative_expense_25y' => (array) ($sfSnapshot['cumulative_expense_25y'] ?? []), 'payback_data' => (array) ($sfSnapshot['payback_data'] ?? []), 'financial_clarity' => (array) ($sfSnapshot['financial_clarity'] ?? []), 'funding_options_summary' => (array) ($sfSnapshot['funding_options_summary'] ?? []), 'public_share_token' => bin2hex(random_bytes(16)), 'public_share_enabled' => false, 'accepted_at' => null, 'supersedes_quotation_id' => $sourceQuote['quotation_id'] ?? null, 'superseded_by_quotation_id' => null, 'created_at' => date('c'), 'updated_at' => date('c')];
                    if ($sourceQuote !== null) {
                        foreach ($quotes as &$q2) {
                            if ((string) ($q2['quotation_id'] ?? '') === (string) ($sourceQuote['quotation_id'] ?? '')) {
                                $q2['superseded_by_quotation_id'] = $id;
                            }
                        }
                        unset($q2);
                    }
                    $sfPayload = PmSuryaGharOpsService::readRecords($tenantId, 'solar_finance');
                    $sfPayload['items'] = $solarFinance;
                    PmSuryaGharOpsService::writeRecords($tenantId, 'solar_finance', $sfPayload);
                }
                foreach ($quotes as &$q) {
                    if ((string) ($q['quotation_id'] ?? '') !== (string) ($_POST['quotation_id'] ?? '')) { continue; }
                    if ($action === 'accept') { $q['quotation_status'] = 'accepted'; $q['accepted_at'] = date('c'); }
                    if ($action === 'share_enable') { $q['public_share_enabled'] = true; $q['quotation_status'] = 'shared'; }
                    $q['updated_at'] = date('c');
                    break;
                }
                unset($q);
                $payload['items'] = $quotes;
                PmSuryaGharOpsService::writeRecords($tenantId, 'quotations', $payload);
                PmSuryaGharOpsService::writeIndex($tenantId, 'quotation', $quotes, 'quotation_id', 'quotation_status');
            }
            if ($subPath === '/quotations/print') {
                $id = (string) ($_GET['id'] ?? '');
                foreach ($quotes as $q) {
                    if ((string) ($q['quotation_id'] ?? '') !== $id) { continue; }
                    header('Content-Type: text/html; charset=UTF-8');
                    echo '<html><body><h1>Quotation ' . htmlspecialchars($id) . ' Rev ' . (int) ($q['revision_no'] ?? 1) . '</h1>';
                    echo '<p>Status: ' . htmlspecialchars((string) ($q['quotation_status'] ?? 'draft')) . '</p>';
                    echo '<h3>Customer</h3><pre>' . htmlspecialchars(json_encode($q['customer_snapshot'] ?? [], JSON_PRETTY_PRINT)) . '</pre>';
                    echo '<h3>Pricing</h3><pre>' . htmlspecialchars(json_encode($q['pricing_summary'] ?? [], JSON_PRETTY_PRINT)) . '</pre>';
                    echo '<h3>Finance</h3><pre>' . htmlspecialchars(json_encode($q['funding_options_summary'] ?? [], JSON_PRETTY_PRINT)) . '</pre>';
                    echo '</body></html>';
                    exit;
                }
            }
            $pageData['quotes'] = $quotes;
            $pageData['customers'] = $customers;
            $pageData['leads'] = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'leads');
            $pageData['solar_finance'] = $solarFinance;
            render('Quotations', 'pm_surya_ghar', ['vendor' => $vendor, 'workspace' => $pmWorkspace, 'navigation' => SchemeWorkspaceService::buildSchemeNavigation($vendor, $pmWorkspace), 'routeContext' => ['label' => 'Quotations'], 'pageContext' => ['context_type' => 'scheme', 'title' => 'Quotations'], 'page' => 'quotations', 'data' => $pageData, 'csrfToken' => $csrfToken, 'tenantId' => $tenantId], 'vendor');
        }
    }
        $schemeSummaries[] = [
            'scheme_name' => (string) ($pmWorkspace['scheme']['scheme_name'] ?? 'PM Surya Ghar'),
            'scheme_slug' => (string) ($pmWorkspace['scheme_slug'] ?? 'pm-surya-ghar'),
            'description' => (string) ($pmWorkspace['scheme']['description'] ?? ''),
            'dashboard_path' => '/app/pm-surya-ghar/dashboard',
            'summary' => $pmWorkspace['dashboard_summary'],
        ];
    }

    if ($path === '/app' || $path === '/app/dashboard') {
        render('Vendor Dashboard', 'dashboard', [
            'vendor' => $vendor,
            'subscription' => $subscription,
            'cards' => $platformDashboardCards,
            'schemeSummaries' => $schemeSummaries,
            'pageContext' => ['context_type' => 'platform', 'title' => 'Vendor Dashboard', 'breadcrumbs' => [['label' => 'Dashboard']]],
        ], 'vendor');
    }
    if ($path === '/app/profile') render('Profile', 'profile', ['vendor' => $vendor, 'pageContext' => ['context_type' => 'platform', 'title' => 'Company Profile', 'breadcrumbs' => [['label' => 'Dashboard', 'path' => '/app/dashboard'], ['label' => 'Profile']]]], 'vendor');
    if ($path === '/app/subscription') render('Subscription', 'subscription', ['vendor' => $vendor, 'subscription' => $subscription, 'pageContext' => ['context_type' => 'platform', 'title' => 'Subscription', 'breadcrumbs' => [['label' => 'Dashboard', 'path' => '/app/dashboard'], ['label' => 'Subscription']]]], 'vendor');

    $pmWorkspace = SchemeWorkspaceService::pmSuryaGharMetadata($tenantId);
    $routeContext = SchemeWorkspaceService::getRouteContext($pmWorkspace, $path);
    if ($routeContext !== null) {
        $schemeKey = 'pm_surya_ghar';
        requireModuleAccess($vendor, $schemeKey, (string) ($routeContext['module_key'] ?? ''));
        $pmNavigation = SchemeWorkspaceService::buildSchemeNavigation($vendor, $pmWorkspace);
        $pageContext = [
            'context_type' => 'scheme',
            'scheme_name' => (string) ($pmWorkspace['scheme']['scheme_name'] ?? 'PM Surya Ghar'),
            'title' => (string) ($routeContext['label'] ?? 'Module'),
            'breadcrumbs' => (array) ($routeContext['breadcrumbs'] ?? []),
        ];
        if (($routeContext['module_key'] ?? '') === 'dashboard') {
            render((string) ($routeContext['label'] ?? 'Scheme Dashboard'), 'scheme_dashboard', [
                'vendor' => $vendor,
                'subscription' => $subscription,
                'workspace' => $pmWorkspace,
                'navigation' => $pmNavigation,
                'routeContext' => $routeContext,
                'pageContext' => $pageContext,
            ], 'vendor');
        }
        render((string) ($routeContext['label'] ?? 'Module'), 'module', [
            'vendor' => $vendor,
            'schemeKey' => $schemeKey,
            'workspace' => $pmWorkspace,
            'navigation' => $pmNavigation,
            'routeContext' => $routeContext,
            'pageContext' => $pageContext,
            'description' => (string) ($routeContext['description'] ?? 'Module shell is ready for implementation.'),
        ], 'vendor');
    }
}

http_response_code(404);
echo '404 Not Found';

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
            'agreements' => '/agreements',
            'payment-receipts' => '/payment-receipts',
            'invoices' => '/invoices',
            'complaints' => '/complaints',
            'reports-exports' => '/reports-exports',
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
        $findById = static function (array $rows, string $field, string $id): array {
            foreach ($rows as $row) {
                if ((string) ($row[$field] ?? '') === $id) {
                    return $row;
                }
            }
            return [];
        };
        $statusOf = static function (array $row, string $fallback = 'draft'): string {
            foreach ($row as $k => $v) {
                if (str_ends_with((string) $k, '_status')) {
                    return (string) $v;
                }
            }
            return (string) ($row['status'] ?? $fallback);
        };
        $makePrintableHtml = static function (string $title, array $company, array $customer, array $bodySections): string {
            $html = '<html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif;padding:20px;color:#1f2937}h1,h2,h3{margin-bottom:8px}.muted{color:#6b7280}table{width:100%;border-collapse:collapse}td,th{border:1px solid #d1d5db;padding:6px;vertical-align:top}.section{margin:16px 0}@media print{.no-print{display:none}}</style></head><body>';
            $html .= '<h1>' . htmlspecialchars($title) . '</h1>';
            $html .= '<p class="muted">' . htmlspecialchars((string) ($company['company_name'] ?? 'Vendor')) . ' · ' . htmlspecialchars((string) ($company['gst_number'] ?? '')) . '</p>';
            $html .= '<div class="section"><h3>Customer</h3><pre>' . htmlspecialchars(json_encode($customer, JSON_PRETTY_PRINT)) . '</pre></div>';
            foreach ($bodySections as $label => $val) {
                $html .= '<div class="section"><h3>' . htmlspecialchars((string) $label) . '</h3><pre>' . htmlspecialchars(json_encode($val, JSON_PRETTY_PRINT)) . '</pre></div>';
            }
            $html .= '<p class="muted">Generated at ' . htmlspecialchars(date('c')) . '</p></body></html>';
            return $html;
        };

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
            if ($subPath === '/customers/view') {
                $customerId = (string) ($_GET['id'] ?? '');
                $pageData['customer_detail'] = $findById($customers, 'customer_id', $customerId);
                $agreements = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'agreements');
                $receipts = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'receipts');
                $invoices = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'invoices');
                $complaints = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'complaints');
                $history = [
                    'Quotation History' => [],
                    'Solar & Finance History' => [],
                    'Agreement History' => [],
                    'Payment Receipt History' => [],
                    'Invoice History' => [],
                    'Complaint History' => [],
                ];
                foreach ($pageData['quotations'] as $q) {
                    if ((string) ($q['customer_id'] ?? '') !== $customerId) continue;
                    $history['Quotation History'][] = ['id' => (string) ($q['quotation_id'] ?? ''), 'status' => $statusOf($q), 'created_at' => (string) ($q['created_at'] ?? ''), 'view_path' => '/app/pm-surya-ghar/quotations/view?id=' . urlencode((string) ($q['quotation_id'] ?? ''))];
                }
                foreach ($pageData['solar_finance'] as $sf) {
                    if ((string) ($sf['customer_id'] ?? '') !== $customerId) continue;
                    $history['Solar & Finance History'][] = ['id' => (string) ($sf['solar_finance_id'] ?? ''), 'status' => $statusOf($sf), 'created_at' => (string) ($sf['created_at'] ?? ''), 'view_path' => '/app/pm-surya-ghar/solar-finance'];
                }
                foreach ($agreements as $a) { if ((string) ($a['customer_id'] ?? '') === $customerId) $history['Agreement History'][] = ['id' => (string) ($a['agreement_id'] ?? ''), 'status' => $statusOf($a), 'created_at' => (string) ($a['created_at'] ?? ''), 'view_path' => '/app/pm-surya-ghar/agreements/view?id=' . urlencode((string) ($a['agreement_id'] ?? ''))]; }
                foreach ($receipts as $r) { if ((string) ($r['customer_id'] ?? '') === $customerId) $history['Payment Receipt History'][] = ['id' => (string) ($r['receipt_id'] ?? ''), 'status' => $statusOf($r), 'created_at' => (string) ($r['created_at'] ?? ''), 'view_path' => '/app/pm-surya-ghar/payment-receipts/view?id=' . urlencode((string) ($r['receipt_id'] ?? ''))]; }
                foreach ($invoices as $i) { if ((string) ($i['customer_id'] ?? '') === $customerId) $history['Invoice History'][] = ['id' => (string) ($i['invoice_id'] ?? ''), 'status' => $statusOf($i), 'created_at' => (string) ($i['created_at'] ?? ''), 'view_path' => '/app/pm-surya-ghar/invoices/view?id=' . urlencode((string) ($i['invoice_id'] ?? ''))]; }
                foreach ($complaints as $c) { if ((string) ($c['customer_id'] ?? '') === $customerId) $history['Complaint History'][] = ['id' => (string) ($c['complaint_id'] ?? ''), 'status' => $statusOf($c, 'open'), 'created_at' => (string) ($c['created_at'] ?? ''), 'view_path' => '/app/pm-surya-ghar/complaints/view?id=' . urlencode((string) ($c['complaint_id'] ?? ''))]; }
                $pageData['history_blocks'] = $history;
            }
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
            $filteredItems = $items;
            $q = strtolower(trim((string) ($_GET['q'] ?? '')));
            $statusFilter = (string) ($_GET['status'] ?? '');
            $sort = (string) ($_GET['sort'] ?? 'newest');
            $filteredItems = array_values(array_filter($filteredItems, function (array $row) use ($q, $statusFilter): bool {
                if ($statusFilter !== '' && (string) ($row['agreement_status'] ?? '') !== $statusFilter) return false;
                if ($q === '') return true;
                $hay = strtolower(implode(' ', [(string) ($row['agreement_id'] ?? ''), (string) ($row['customer_id'] ?? ''), (string) ($row['source_quotation_id'] ?? ''), (string) (($row['customer_snapshot']['mobile'] ?? ''))]));
                return str_contains($hay, $q);
            }));
            usort($filteredItems, fn(array $a, array $b): int => $sort === 'oldest' ? strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')) : strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
            $pageData['items'] = $filteredItems;
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
            $agreements = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'agreements');
            $receipts = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'receipts');
            $invoices = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'invoices');
            $downstreamCounts = [];
            foreach ($quotes as $q) {
                $qid = (string) ($q['quotation_id'] ?? '');
                $downstreamCounts[$qid] = ['agreements' => 0, 'receipts' => 0, 'invoices' => 0];
                foreach ($agreements as $a) { if ((string) ($a['source_quotation_id'] ?? '') === $qid) $downstreamCounts[$qid]['agreements']++; }
                foreach ($receipts as $r) { if ((string) ($r['source_quotation_id'] ?? '') === $qid) $downstreamCounts[$qid]['receipts']++; }
                foreach ($invoices as $i) { if ((string) ($i['source_quotation_id'] ?? '') === $qid) $downstreamCounts[$qid]['invoices']++; }
            }
            $pageData['quotes'] = $quotes;
            $pageData['customers'] = $customers;
            $pageData['leads'] = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'leads');
            $pageData['solar_finance'] = $solarFinance;
            $pageData['downstream_counts'] = $downstreamCounts;
            if ($subPath === '/quotations/view') {
                $id = (string) ($_GET['id'] ?? '');
                $pageData['quotation_detail'] = $findById($quotes, 'quotation_id', $id);
                $pageData['downstream_detail'] = [
                    'agreements' => array_values(array_filter($agreements, fn(array $a): bool => (string) ($a['source_quotation_id'] ?? '') === $id)),
                    'receipts' => array_values(array_filter($receipts, fn(array $r): bool => (string) ($r['source_quotation_id'] ?? '') === $id)),
                    'invoices' => array_values(array_filter($invoices, fn(array $i): bool => (string) ($i['source_quotation_id'] ?? '') === $id)),
                ];
            }
            render('Quotations', 'pm_surya_ghar', ['vendor' => $vendor, 'workspace' => $pmWorkspace, 'navigation' => SchemeWorkspaceService::buildSchemeNavigation($vendor, $pmWorkspace), 'routeContext' => ['label' => 'Quotations'], 'pageContext' => ['context_type' => 'scheme', 'title' => 'Quotations'], 'page' => 'quotations', 'data' => $pageData, 'csrfToken' => $csrfToken, 'tenantId' => $tenantId], 'vendor');
        }

        if (str_starts_with($subPath, '/agreements')) {
            $payload = PmSuryaGharOpsService::readRecords($tenantId, 'agreements');
            $items = $payload['items'] ?? [];
            $customers = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'customers');
            $quotations = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'quotations');
            if ($method === 'POST' && (string) ($_POST['action'] ?? '') === 'create') {
                $customerId = (string) ($_POST['customer_id'] ?? '');
                $quotationId = (string) ($_POST['source_quotation_id'] ?? '');
                if ($customerId === '') {
                    $pageData['errors'][] = 'Customer is required.';
                } else {
                    $id = PmSuryaGharOpsService::nextSchemeId($tenantId, 'agreements', 'AGR-');
                    $customer = $findById($customers, 'customer_id', $customerId);
                    $quotation = $quotationId !== '' ? $findById($quotations, 'quotation_id', $quotationId) : [];
                    if ($quotationId !== '' && strtolower((string) ($quotation['quotation_status'] ?? '')) !== 'accepted') {
                        $pageData['errors'][] = 'Agreement should originate from accepted quotation.';
                    } else {
                        $branding = TenantStorageService::getTenantBranding($tenantId);
                        $templates = TenantStorageService::getTenantSchemeConfig($tenantId, $schemeKey, 'templates');
                        $settings = TenantStorageService::getTenantSchemeSettings($tenantId, $schemeKey);
                        $agreement = ['agreement_id' => $id, 'tenant_id' => $tenantId, 'scheme_key' => $schemeKey, 'customer_id' => $customerId, 'source_lead_id' => (string) ($quotation['source_lead_id'] ?? ''), 'source_quotation_id' => $quotationId, 'agreement_status' => (string) ($_POST['agreement_status'] ?? 'draft'), 'agreement_title' => (string) ($_POST['agreement_title'] ?? ('Agreement ' . $id)), 'agreement_number_display' => $id, 'customer_snapshot' => $customer, 'quotation_snapshot_summary' => ['quotation_id' => (string) ($quotation['quotation_id'] ?? ''), 'pricing_summary' => (array) ($quotation['pricing_summary'] ?? [])], 'branding_snapshot' => $branding, 'template_snapshot' => (array) ($templates['data'] ?? []), 'terms_snapshot' => (array) ($settings['agreement_terms'] ?? []), 'agreement_sections' => ['project_summary' => (array) ($quotation['solar_at_a_glance'] ?? []), 'commercial_terms' => (array) ($quotation['pricing_summary'] ?? []), 'obligations' => (array) ($settings['agreement_obligations'] ?? [])], 'payment_terms_snapshot' => (array) ($settings['payment_terms'] ?? []), 'warranty_snapshot' => (array) ($settings['warranty_terms'] ?? []), 'next_steps_snapshot' => (array) ($settings['next_steps'] ?? []), 'notes' => (string) ($_POST['notes'] ?? ''), 'signed_status_placeholder' => 'pending', 'accepted_context' => ['accepted_at' => (string) ($quotation['accepted_at'] ?? '')], 'created_at' => date('c'), 'updated_at' => date('c')];
                        $agreement['snapshot_file'] = PmSuryaGharOpsService::snapshot($tenantId, 'agreements', $id, $agreement);
                        $items[] = $agreement;
                        $payload['items'] = $items;
                        PmSuryaGharOpsService::writeRecords($tenantId, 'agreements', $payload);
                        PmSuryaGharOpsService::writeIndex($tenantId, 'agreement', $items, 'agreement_id', 'agreement_status');
                    }
                }
            }
            if ($subPath === '/agreements/create-from-quotation') {
                $pageData['prefill'] = ['source_quotation_id' => (string) ($_GET['quotation_id'] ?? '')];
                if ($pageData['prefill']['source_quotation_id'] !== '') {
                    $q = $findById($quotations, 'quotation_id', (string) $pageData['prefill']['source_quotation_id']);
                    $pageData['prefill']['customer_id'] = (string) ($q['customer_id'] ?? '');
                }
            }
            if ($subPath === '/agreements/print') {
                $id = (string) ($_GET['id'] ?? '');
                $detail = $findById($items, 'agreement_id', $id);
                if ($detail !== []) {
                    $html = $makePrintableHtml('Agreement ' . $id, (array) ($detail['branding_snapshot'] ?? []), (array) ($detail['customer_snapshot'] ?? []), ['Reference' => ['quotation' => $detail['source_quotation_id'] ?? ''], 'Sections' => (array) ($detail['agreement_sections'] ?? []), 'Terms' => (array) ($detail['terms_snapshot'] ?? [])]);
                    $docDir = TenantStorageService::getTenantSchemePath($tenantId, $schemeKey) . '/documents/agreements';
                    \App\Core\JsonStorage::ensureDir($docDir);
                    file_put_contents($docDir . '/agreement_' . $id . '.html', $html);
                    header('Content-Type: text/html; charset=UTF-8');
                    echo $html;
                    exit;
                }
            }
            if ($subPath === '/agreements/view' || $subPath === '/agreements/edit') {
                $pageData['detail'] = $findById($items, 'agreement_id', (string) ($_GET['id'] ?? ''));
            }
            $filteredItems = $items;
            $q = strtolower(trim((string) ($_GET['q'] ?? '')));
            $statusFilter = (string) ($_GET['status'] ?? '');
            $sort = (string) ($_GET['sort'] ?? 'newest');
            $filteredItems = array_values(array_filter($filteredItems, function (array $row) use ($q, $statusFilter): bool {
                if ($statusFilter !== '' && (string) ($row['agreement_status'] ?? '') !== $statusFilter) return false;
                if ($q === '') return true;
                $hay = strtolower(implode(' ', [(string) ($row['agreement_id'] ?? ''), (string) ($row['customer_id'] ?? ''), (string) ($row['source_quotation_id'] ?? ''), (string) (($row['customer_snapshot']['mobile'] ?? ''))]));
                return str_contains($hay, $q);
            }));
            usort($filteredItems, fn(array $a, array $b): int => $sort === 'oldest' ? strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? '')) : strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
            $pageData['items'] = $filteredItems;
            render('Agreements', 'pm_surya_ghar', ['vendor' => $vendor, 'workspace' => $pmWorkspace, 'navigation' => SchemeWorkspaceService::buildSchemeNavigation($vendor, $pmWorkspace), 'routeContext' => ['label' => 'Agreements'], 'pageContext' => ['context_type' => 'scheme', 'title' => 'Agreements'], 'page' => 'agreements', 'data' => $pageData, 'csrfToken' => $csrfToken], 'vendor');
        }

        if (str_starts_with($subPath, '/payment-receipts')) {
            $payload = PmSuryaGharOpsService::readRecords($tenantId, 'receipts');
            $items = $payload['items'] ?? [];
            $customers = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'customers');
            $agreements = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'agreements');
            if ($method === 'POST' && (string) ($_POST['action'] ?? '') === 'create') {
                $customerId = (string) ($_POST['customer_id'] ?? '');
                if ($customerId === '') {
                    $pageData['errors'][] = 'Customer is required.';
                } else {
                    $id = PmSuryaGharOpsService::nextSchemeId($tenantId, 'receipts', 'REC-');
                    $customer = $findById($customers, 'customer_id', $customerId);
                    $link = ['quotation' => (string) ($_POST['source_quotation_id'] ?? ''), 'agreement' => (string) ($_POST['source_agreement_id'] ?? '')];
                    $receipt = ['receipt_id' => $id, 'tenant_id' => $tenantId, 'scheme_key' => $schemeKey, 'customer_id' => $customerId, 'source_quotation_id' => $link['quotation'], 'source_agreement_id' => $link['agreement'], 'receipt_status' => (string) ($_POST['receipt_status'] ?? 'issued'), 'receipt_number_display' => $id, 'receipt_date' => (string) ($_POST['receipt_date'] ?? date('Y-m-d')), 'amount_received' => (float) ($_POST['amount_received'] ?? 0), 'payment_mode' => (string) ($_POST['payment_mode'] ?? 'other'), 'transaction_reference' => (string) ($_POST['transaction_reference'] ?? ''), 'bank_reference' => (string) ($_POST['bank_reference'] ?? ''), 'received_from_name' => (string) ($_POST['received_from_name'] ?? ($customer['customer_name'] ?? '')), 'purpose' => (string) ($_POST['purpose'] ?? 'Advance payment'), 'notes' => (string) ($_POST['notes'] ?? ''), 'customer_snapshot' => $customer, 'branding_snapshot' => TenantStorageService::getTenantBranding($tenantId), 'linked_document_snapshot_summary' => ['quotation' => $link['quotation'], 'agreement' => $findById($agreements, 'agreement_id', $link['agreement'])], 'created_at' => date('c'), 'updated_at' => date('c')];
                    $receipt['snapshot_file'] = PmSuryaGharOpsService::snapshot($tenantId, 'receipts', $id, $receipt);
                    $items[] = $receipt;
                    $payload['items'] = $items;
                    PmSuryaGharOpsService::writeRecords($tenantId, 'receipts', $payload);
                    PmSuryaGharOpsService::writeIndex($tenantId, 'receipt', $items, 'receipt_id', 'receipt_status');
                }
            }
            if ($subPath === '/payment-receipts/create-from-agreement') {
                $aid = (string) ($_GET['agreement_id'] ?? '');
                $a = $findById($agreements, 'agreement_id', $aid);
                $pageData['prefill'] = ['source_agreement_id' => $aid, 'source_quotation_id' => (string) ($a['source_quotation_id'] ?? ''), 'customer_id' => (string) ($a['customer_id'] ?? '')];
            }
            if ($subPath === '/payment-receipts/create-from-quotation') {
                $qid = (string) ($_GET['quotation_id'] ?? '');
                $quotes = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'quotations');
                $q = $findById($quotes, 'quotation_id', $qid);
                $pageData['prefill'] = ['source_quotation_id' => $qid, 'customer_id' => (string) ($q['customer_id'] ?? '')];
            }
            if ($subPath === '/payment-receipts/create' && (string) ($_GET['customer_id'] ?? '') !== '') {
                $pageData['prefill'] = ['customer_id' => (string) ($_GET['customer_id'] ?? '')];
            }
            if ($subPath === '/payment-receipts/print') {
                $id = (string) ($_GET['id'] ?? '');
                $detail = $findById($items, 'receipt_id', $id);
                if ($detail !== []) {
                    $html = $makePrintableHtml('Payment Receipt ' . $id, (array) ($detail['branding_snapshot'] ?? []), (array) ($detail['customer_snapshot'] ?? []), ['Receipt' => $detail, 'Linked Docs' => (array) ($detail['linked_document_snapshot_summary'] ?? [])]);
                    $docDir = TenantStorageService::getTenantSchemePath($tenantId, $schemeKey) . '/documents/receipts';
                    \App\Core\JsonStorage::ensureDir($docDir);
                    file_put_contents($docDir . '/receipt_' . $id . '.html', $html);
                    header('Content-Type: text/html; charset=UTF-8');
                    echo $html;
                    exit;
                }
            }
            if ($subPath === '/payment-receipts/view' || $subPath === '/payment-receipts/edit') {
                $pageData['detail'] = $findById($items, 'receipt_id', (string) ($_GET['id'] ?? ''));
            }
            $filteredItems = $items;
            $q = strtolower(trim((string) ($_GET['q'] ?? '')));
            $statusFilter = (string) ($_GET['status'] ?? '');
            $modeFilter = (string) ($_GET['payment_mode'] ?? '');
            $filteredItems = array_values(array_filter($filteredItems, function (array $row) use ($q, $statusFilter, $modeFilter): bool {
                if ($statusFilter !== '' && (string) ($row['receipt_status'] ?? '') !== $statusFilter) return false;
                if ($modeFilter !== '' && (string) ($row['payment_mode'] ?? '') !== $modeFilter) return false;
                if ($q === '') return true;
                $hay = strtolower(implode(' ', [(string) ($row['receipt_id'] ?? ''), (string) ($row['transaction_reference'] ?? ''), (string) ($row['customer_id'] ?? ''), (string) ($row['source_quotation_id'] ?? ''), (string) ($row['source_agreement_id'] ?? '')]));
                return str_contains($hay, $q);
            }));
            $pageData['items'] = $filteredItems;
            render('Payment Receipts', 'pm_surya_ghar', ['vendor' => $vendor, 'workspace' => $pmWorkspace, 'navigation' => SchemeWorkspaceService::buildSchemeNavigation($vendor, $pmWorkspace), 'routeContext' => ['label' => 'Payment Receipts'], 'pageContext' => ['context_type' => 'scheme', 'title' => 'Payment Receipts'], 'page' => 'payment_receipts', 'data' => $pageData, 'csrfToken' => $csrfToken], 'vendor');
        }

        if (str_starts_with($subPath, '/invoices')) {
            $payload = PmSuryaGharOpsService::readRecords($tenantId, 'invoices');
            $items = $payload['items'] ?? [];
            $customers = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'customers');
            if ($subPath === '/invoices/create-from-quotation') {
                $qid = (string) ($_GET['quotation_id'] ?? '');
                $quotes = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'quotations');
                $q = $findById($quotes, 'quotation_id', $qid);
                $pageData['prefill'] = ['source_quotation_id' => $qid, 'customer_id' => (string) ($q['customer_id'] ?? '')];
            }
            if ($subPath === '/invoices/create-from-agreement') {
                $aid = (string) ($_GET['agreement_id'] ?? '');
                $agreements = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'agreements');
                $a = $findById($agreements, 'agreement_id', $aid);
                $pageData['prefill'] = ['source_agreement_id' => $aid, 'source_quotation_id' => (string) ($a['source_quotation_id'] ?? ''), 'customer_id' => (string) ($a['customer_id'] ?? '')];
            }
            if ($method === 'POST' && (string) ($_POST['action'] ?? '') === 'create') {
                $customerId = (string) ($_POST['customer_id'] ?? '');
                if ($customerId === '') {
                    $pageData['errors'][] = 'Customer is required.';
                } else {
                    $id = PmSuryaGharOpsService::nextSchemeId($tenantId, 'invoices', 'INV-');
                    $rows = preg_split('/\R+/', trim((string) ($_POST['items_text'] ?? ''))) ?: [];
                    $invoiceItems = [];
                    foreach ($rows as $row) {
                        if (trim($row) === '') continue;
                        [$desc, $qty, $unit, $rate, $taxRate] = array_pad(array_map('trim', explode('|', $row)), 5, '');
                        $quantity = max(1.0, (float) $qty);
                        $unitRate = (float) $rate;
                        $line = $quantity * $unitRate;
                        $taxPercent = (float) $taxRate;
                        $taxAmount = $line * ($taxPercent / 100);
                        $invoiceItems[] = ['description' => $desc, 'quantity' => $quantity, 'unit' => ($unit !== '' ? $unit : 'nos'), 'unit_rate' => $unitRate, 'line_total' => round($line, 2), 'tax_rate' => $taxPercent, 'tax_amount' => round($taxAmount, 2)];
                    }
                    if ($invoiceItems === []) $invoiceItems[] = ['description' => 'Solar package', 'quantity' => 1, 'unit' => 'nos', 'unit_rate' => 0, 'line_total' => 0, 'tax_rate' => 0, 'tax_amount' => 0];
                    $subtotal = array_sum(array_column($invoiceItems, 'line_total'));
                    $tax = array_sum(array_column($invoiceItems, 'tax_amount'));
                    $customer = $findById($customers, 'customer_id', $customerId);
                    $branding = TenantStorageService::getTenantBranding($tenantId);
                    $invoice = ['invoice_id' => $id, 'tenant_id' => $tenantId, 'scheme_key' => $schemeKey, 'customer_id' => $customerId, 'source_quotation_id' => (string) ($_POST['source_quotation_id'] ?? ''), 'source_agreement_id' => (string) ($_POST['source_agreement_id'] ?? ''), 'invoice_status' => (string) ($_POST['invoice_status'] ?? 'draft'), 'invoice_number_display' => (string) ($_POST['invoice_number_display'] ?? $id), 'invoice_date' => (string) ($_POST['invoice_date'] ?? date('Y-m-d')), 'due_date' => (string) ($_POST['due_date'] ?? date('Y-m-d', strtotime('+15 days'))), 'customer_snapshot' => $customer, 'branding_snapshot' => $branding, 'gst_snapshot' => ['vendor_gst_number' => (string) ($_POST['vendor_gst_number'] ?? ($branding['gst_number'] ?? '')), 'customer_gst_number' => (string) ($_POST['customer_gst_number'] ?? ''), 'place_of_supply' => (string) ($_POST['place_of_supply'] ?? '')], 'invoice_items' => $invoiceItems, 'subtotal' => round($subtotal, 2), 'tax_summary' => ['tax_amount' => round($tax, 2)], 'total_amount' => round($subtotal + $tax, 2), 'notes' => (string) ($_POST['notes'] ?? ''), 'payment_instructions_snapshot' => ['bank_name' => (string) ($branding['bank_name'] ?? ''), 'upi' => (string) ($branding['upi_id'] ?? '')], 'created_at' => date('c'), 'updated_at' => date('c')];
                    $invoice['snapshot_file'] = PmSuryaGharOpsService::snapshot($tenantId, 'invoices', $id, $invoice);
                    $items[] = $invoice;
                    $payload['items'] = $items;
                    PmSuryaGharOpsService::writeRecords($tenantId, 'invoices', $payload);
                    PmSuryaGharOpsService::writeIndex($tenantId, 'invoice', $items, 'invoice_id', 'invoice_status');
                }
            }
            if ($subPath === '/invoices/print') {
                $id = (string) ($_GET['id'] ?? '');
                $detail = $findById($items, 'invoice_id', $id);
                if ($detail !== []) {
                    $html = $makePrintableHtml('Invoice ' . $id, (array) ($detail['branding_snapshot'] ?? []), (array) ($detail['customer_snapshot'] ?? []), ['GST' => (array) ($detail['gst_snapshot'] ?? []), 'Items' => (array) ($detail['invoice_items'] ?? []), 'Totals' => ['subtotal' => $detail['subtotal'] ?? 0, 'tax' => $detail['tax_summary'] ?? [], 'total' => $detail['total_amount'] ?? 0]]);
                    $docDir = TenantStorageService::getTenantSchemePath($tenantId, $schemeKey) . '/documents/invoices';
                    \App\Core\JsonStorage::ensureDir($docDir);
                    file_put_contents($docDir . '/invoice_' . $id . '.html', $html);
                    header('Content-Type: text/html; charset=UTF-8');
                    echo $html;
                    exit;
                }
            }
            if ($subPath === '/invoices/view' || $subPath === '/invoices/edit') {
                $pageData['detail'] = $findById($items, 'invoice_id', (string) ($_GET['id'] ?? ''));
            }
            $filteredItems = $items;
            $q = strtolower(trim((string) ($_GET['q'] ?? '')));
            $statusFilter = (string) ($_GET['status'] ?? '');
            $filteredItems = array_values(array_filter($filteredItems, function (array $row) use ($q, $statusFilter): bool {
                if ($statusFilter !== '' && (string) ($row['invoice_status'] ?? '') !== $statusFilter) return false;
                if ($q === '') return true;
                $hay = strtolower(implode(' ', [(string) ($row['invoice_id'] ?? ''), (string) ($row['invoice_number_display'] ?? ''), (string) ($row['customer_id'] ?? ''), (string) ($row['source_quotation_id'] ?? ''), (string) ($row['source_agreement_id'] ?? '')]));
                return str_contains($hay, $q);
            }));
            $pageData['items'] = $filteredItems;
            render('Invoices', 'pm_surya_ghar', ['vendor' => $vendor, 'workspace' => $pmWorkspace, 'navigation' => SchemeWorkspaceService::buildSchemeNavigation($vendor, $pmWorkspace), 'routeContext' => ['label' => 'Invoices'], 'pageContext' => ['context_type' => 'scheme', 'title' => 'Invoices'], 'page' => 'invoices', 'data' => $pageData, 'csrfToken' => $csrfToken], 'vendor');
        }

        if (str_starts_with($subPath, '/complaints')) {
            $payload = PmSuryaGharOpsService::readRecords($tenantId, 'complaints');
            $items = $payload['items'] ?? [];
            $customers = TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'customers');
            if ($method === 'POST' && (string) ($_POST['action'] ?? '') === 'create') {
                $customerId = (string) ($_POST['customer_id'] ?? '');
                if ($customerId === '') {
                    $pageData['errors'][] = 'Customer is required.';
                } else {
                    $id = PmSuryaGharOpsService::nextSchemeId($tenantId, 'complaints', 'CMP-');
                    $items[] = ['complaint_id' => $id, 'tenant_id' => $tenantId, 'scheme_key' => $schemeKey, 'customer_id' => $customerId, 'source_quotation_id' => (string) ($_POST['source_quotation_id'] ?? ''), 'source_agreement_id' => (string) ($_POST['source_agreement_id'] ?? ''), 'source_invoice_id' => (string) ($_POST['source_invoice_id'] ?? ''), 'source_receipt_id' => (string) ($_POST['source_receipt_id'] ?? ''), 'category' => (string) ($_POST['category'] ?? 'other'), 'complaint_status' => (string) ($_POST['complaint_status'] ?? 'open'), 'priority' => (string) ($_POST['priority'] ?? 'medium'), 'title' => (string) ($_POST['title'] ?? ''), 'description' => (string) ($_POST['description'] ?? ''), 'assignee_text' => (string) ($_POST['assignee_text'] ?? ''), 'assignee_group' => (string) ($_POST['assignee_group'] ?? ''), 'raised_date' => (string) ($_POST['raised_date'] ?? date('Y-m-d')), 'last_updated_at' => date('c'), 'resolution_note' => (string) ($_POST['resolution_note'] ?? ''), 'customer_snapshot' => $findById($customers, 'customer_id', $customerId), 'tags' => (string) ($_POST['tags'] ?? ''), 'highlighted_flag' => isset($_POST['highlighted_flag']), 'notes_history' => [], 'created_at' => date('c'), 'updated_at' => date('c')];
                    $payload['items'] = $items;
                    PmSuryaGharOpsService::writeRecords($tenantId, 'complaints', $payload);
                    PmSuryaGharOpsService::writeIndex($tenantId, 'complaint', $items, 'complaint_id', 'complaint_status');
                }
            }
            if ($subPath === '/complaints/export') {
                $status = (string) ($_GET['status'] ?? '');
                $category = (string) ($_GET['category'] ?? '');
                $assignee = strtolower(trim((string) ($_GET['assignee'] ?? '')));
                $from = (string) ($_GET['from'] ?? '');
                $to = (string) ($_GET['to'] ?? '');
                $filtered = array_values(array_filter($items, function (array $row) use ($status, $category, $assignee, $from, $to): bool {
                    if ($status !== '' && (string) ($row['complaint_status'] ?? '') !== $status) return false;
                    if ($category !== '' && (string) ($row['category'] ?? '') !== $category) return false;
                    if ($assignee !== '' && !str_contains(strtolower((string) ($row['assignee_text'] ?? '')), $assignee)) return false;
                    $d = substr((string) ($row['raised_date'] ?? ''), 0, 10);
                    if ($from !== '' && $d < $from) return false;
                    if ($to !== '' && $d > $to) return false;
                    return true;
                }));
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="complaints_export.csv"');
                $out = fopen('php://output', 'w');
                fputcsv($out, ['complaint_id', 'customer_id', 'customer_name', 'category', 'status', 'assignee_text', 'raised_date', 'updated_at']);
                foreach ($filtered as $r) {
                    fputcsv($out, [(string) ($r['complaint_id'] ?? ''), (string) ($r['customer_id'] ?? ''), (string) (($r['customer_snapshot']['customer_name'] ?? '')), (string) ($r['category'] ?? ''), (string) ($r['complaint_status'] ?? ''), (string) ($r['assignee_text'] ?? ''), (string) ($r['raised_date'] ?? ''), (string) ($r['updated_at'] ?? '')]);
                }
                fclose($out);
                exit;
            }
            if ($subPath === '/complaints/view' || $subPath === '/complaints/edit') {
                $pageData['detail'] = $findById($items, 'complaint_id', (string) ($_GET['id'] ?? ''));
            }
            $filteredItems = $items;
            $q = strtolower(trim((string) ($_GET['q'] ?? '')));
            $statusFilter = (string) ($_GET['status'] ?? '');
            $categoryFilter = (string) ($_GET['category'] ?? '');
            $filteredItems = array_values(array_filter($filteredItems, function (array $row) use ($q, $statusFilter, $categoryFilter): bool {
                if ($statusFilter !== '' && (string) ($row['complaint_status'] ?? '') !== $statusFilter) return false;
                if ($categoryFilter !== '' && (string) ($row['category'] ?? '') !== $categoryFilter) return false;
                if ($q === '') return true;
                $hay = strtolower(implode(' ', [(string) ($row['complaint_id'] ?? ''), (string) ($row['title'] ?? ''), (string) ($row['customer_id'] ?? ''), (string) ($row['category'] ?? ''), (string) ($row['assignee_text'] ?? '')]));
                return str_contains($hay, $q);
            }));
            $pageData['items'] = $filteredItems;
            render('Complaints', 'pm_surya_ghar', ['vendor' => $vendor, 'workspace' => $pmWorkspace, 'navigation' => SchemeWorkspaceService::buildSchemeNavigation($vendor, $pmWorkspace), 'routeContext' => ['label' => 'Complaints'], 'pageContext' => ['context_type' => 'scheme', 'title' => 'Complaints'], 'page' => 'complaints', 'data' => $pageData, 'csrfToken' => $csrfToken], 'vendor');
        }

        if (str_starts_with($subPath, '/reports-exports')) {
            $allRecords = [
                'leads' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'leads'),
                'customers' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'customers'),
                'quotations' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'quotations'),
                'solar_finance' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'solar_finance'),
                'agreements' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'agreements'),
                'receipts' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'receipts'),
                'invoices' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'invoices'),
                'complaints' => TenantStorageService::getTenantSchemeRecords($tenantId, $schemeKey, 'complaints'),
            ];
            $summary = ['total_leads' => count($allRecords['leads']), 'active_leads' => count(array_filter($allRecords['leads'], fn(array $r): bool => strtolower((string) ($r['status'] ?? '')) !== 'archived')), 'converted_customers' => count(array_filter($allRecords['leads'], fn(array $r): bool => (string) ($r['status'] ?? '') === 'converted_to_customer')), 'total_customers' => count($allRecords['customers']), 'solar_finance_reports_count' => count($allRecords['solar_finance']), 'draft_quotations' => count(array_filter($allRecords['quotations'], fn(array $r): bool => (string) ($r['quotation_status'] ?? '') === 'draft')), 'accepted_quotations' => count(array_filter($allRecords['quotations'], fn(array $r): bool => (string) ($r['quotation_status'] ?? '') === 'accepted')), 'agreements_count' => count($allRecords['agreements']), 'receipts_count' => count($allRecords['receipts']), 'invoices_count' => count($allRecords['invoices']), 'open_complaints_count' => count(array_filter($allRecords['complaints'], fn(array $r): bool => in_array((string) ($r['complaint_status'] ?? ''), ['open', 'in_progress'], true)))];
            $exportMap = [
                '/reports-exports/leads' => ['file' => 'leads_export.csv', 'headers' => ['lead_id', 'contact_name', 'company_name', 'mobile', 'email', 'city', 'state', 'status', 'follow_up_date', 'best_time_to_call', 'intro_message_sent', 'detailed_message_sent', 'created_at'], 'rows' => array_map(fn(array $r): array => [(string) ($r['lead_id'] ?? ''), (string) ($r['contact_name'] ?? ''), (string) ($r['company_name'] ?? ''), (string) ($r['mobile'] ?? ''), (string) ($r['email'] ?? ''), (string) ($r['city'] ?? ''), (string) ($r['state'] ?? ''), (string) ($r['status'] ?? ''), (string) ($r['follow_up_date'] ?? ''), (string) ($r['best_time_to_call'] ?? ''), !empty($r['intro_message_sent_flag']) ? 'yes' : 'no', !empty($r['detailed_message_sent_flag']) ? 'yes' : 'no', (string) ($r['created_at'] ?? '')], $allRecords['leads'])],
                '/reports-exports/customers' => ['file' => 'customers_export.csv', 'headers' => ['customer_id', 'customer_name', 'mobile', 'email', 'city', 'state', 'source_lead_id', 'monthly_bill', 'monthly_units', 'created_at'], 'rows' => array_map(fn(array $r): array => [(string) ($r['customer_id'] ?? ''), (string) ($r['customer_name'] ?? ''), (string) ($r['mobile'] ?? ''), (string) ($r['email'] ?? ''), (string) ($r['city'] ?? ''), (string) ($r['state'] ?? ''), (string) ($r['source_lead_id'] ?? ''), (string) ($r['monthly_bill'] ?? ''), (string) ($r['monthly_units'] ?? ''), (string) ($r['created_at'] ?? '')], $allRecords['customers'])],
                '/reports-exports/quotations' => ['file' => 'quotations_export.csv', 'headers' => ['quotation_id', 'quotation_root_id', 'revision_no', 'customer_id', 'customer_name', 'status', 'source_solar_finance_id', 'created_at', 'accepted_at', 'public_share_enabled'], 'rows' => array_map(fn(array $r): array => [(string) ($r['quotation_id'] ?? ''), (string) ($r['quotation_root_id'] ?? ''), (string) ($r['revision_no'] ?? ''), (string) ($r['customer_id'] ?? ''), (string) (($r['customer_snapshot']['customer_name'] ?? '')), (string) ($r['quotation_status'] ?? ''), (string) ($r['source_solar_finance_id'] ?? ''), (string) ($r['created_at'] ?? ''), (string) ($r['accepted_at'] ?? ''), !empty($r['public_share_enabled']) ? 'yes' : 'no'], $allRecords['quotations'])],
                '/reports-exports/solar-finance' => ['file' => 'solar_finance_export.csv', 'headers' => ['solar_finance_id', 'customer_id', 'lead_id', 'system_type', 'selected_system_size', 'funding_scenario_summary', 'status', 'created_at'], 'rows' => array_map(fn(array $r): array => [(string) ($r['solar_finance_id'] ?? ''), (string) ($r['customer_id'] ?? ''), (string) ($r['source_lead_id'] ?? ''), (string) ($r['system_type'] ?? ''), (string) ($r['selected_system_size'] ?? ''), json_encode($r['funding_options_summary'] ?? []), (string) ($r['status'] ?? ''), (string) ($r['created_at'] ?? '')], $allRecords['solar_finance'])],
                '/reports-exports/complaints' => ['file' => 'complaints_export.csv', 'headers' => ['complaint_id', 'customer_id', 'customer_name', 'category', 'status', 'assignee_text', 'raised_date', 'updated_at'], 'rows' => array_map(fn(array $r): array => [(string) ($r['complaint_id'] ?? ''), (string) ($r['customer_id'] ?? ''), (string) (($r['customer_snapshot']['customer_name'] ?? '')), (string) ($r['category'] ?? ''), (string) ($r['complaint_status'] ?? ''), (string) ($r['assignee_text'] ?? ''), (string) ($r['raised_date'] ?? ''), (string) ($r['updated_at'] ?? '')], $allRecords['complaints'])],
                '/reports-exports/summary' => ['file' => 'summary_export.csv', 'headers' => ['metric', 'value'], 'rows' => array_map(fn(string $k, mixed $v): array => [$k, (string) $v], array_keys($summary), array_values($summary))],
            ];
            if (isset($exportMap[$subPath])) {
                $exp = $exportMap[$subPath];
                $docDir = TenantStorageService::getTenantSchemePath($tenantId, $schemeKey) . '/documents/reports';
                \App\Core\JsonStorage::ensureDir($docDir);
                $filePath = $docDir . '/' . $exp['file'];
                $fp = fopen($filePath, 'w');
                fputcsv($fp, $exp['headers']);
                foreach ($exp['rows'] as $row) fputcsv($fp, $row);
                fclose($fp);
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="' . $exp['file'] . '"');
                readfile($filePath);
                exit;
            }
            $pageData['summary'] = $summary;
            render('Reports & Exports', 'pm_surya_ghar', ['vendor' => $vendor, 'workspace' => $pmWorkspace, 'navigation' => SchemeWorkspaceService::buildSchemeNavigation($vendor, $pmWorkspace), 'routeContext' => ['label' => 'Reports & Exports'], 'pageContext' => ['context_type' => 'scheme', 'title' => 'Reports & Exports'], 'page' => 'reports_exports', 'data' => $pageData, 'csrfToken' => $csrfToken], 'vendor');
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

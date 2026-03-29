<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Services\AccessService;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\ProvisioningService;
use App\Services\RegistryService;

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

function requireAdmin(): void
{
    if (!AuthService::admin()) {
        redirectTo('/admin/login');
    }
}

function requireVendor(): array
{
    $vendor = AuthService::vendor();
    if (!$vendor) {
        redirectTo('/login');
    }
    if (!AccessService::canAccessVendorWorkspace($vendor)) {
        AuthService::logoutAll();
        redirectTo('/login?error=blocked');
    }
    return $vendor;
}

function modulePage(string $moduleKey, string $title, string $description): void
{
    $vendor = requireVendor();
    $schemeKey = 'pm-surya-ghar';
    if (!AccessService::hasSchemeAccess($vendor, $schemeKey) || !AccessService::hasModuleAccess($vendor, $moduleKey)) {
        http_response_code(403);
        render('Access denied', 'module', compact('vendor', 'moduleKey', 'schemeKey', 'title', 'description'), 'vendor');
    }
    render($title, 'module', compact('vendor', 'moduleKey', 'schemeKey', 'title', 'description'), 'vendor');
}

$schemes = RegistryService::get('schemes', []);
$modules = RegistryService::get('modules', []);
$plans = RegistryService::get('plans', []);
$settings = RegistryService::get('superadmin_settings', []);

if ($path === '/logout') {
    AuthService::logoutAll();
    session_start();
    redirectTo('/login');
}

if ($path === '/' || $path === '/homepage') {
    render('Yojak - Platform', 'home', compact('schemes', 'settings'));
}
if ($path === '/schemes') {
    $publicSchemes = array_values(array_filter($schemes, fn($s) => !empty($s['public_visible']) && !empty($s['active_flag'])));
    render('Schemes', 'schemes', compact('publicSchemes'));
}
if ($path === '/scheme/pm-surya-ghar') {
    $scheme = RegistryService::findBy($schemes, 'scheme_key', 'pm-surya-ghar');
    render('PM Surya Ghar', 'scheme', compact('scheme', 'plans'));
}
if ($path === '/pricing') {
    render('Pricing', 'pricing', compact('plans'));
}
if ($path === '/signup') {
    redirectTo('/signup/pm-surya-ghar');
}
if ($path === '/signup/pm-surya-ghar' && $method === 'GET') {
    render('Vendor Signup', 'signup', []);
}
if ($path === '/signup/pm-surya-ghar' && $method === 'POST') {
    $required = ['owner_name', 'company_name', 'mobile', 'email', 'city', 'state', 'password'];
    foreach ($required as $field) {
        if (empty($_POST[$field])) {
            render('Vendor Signup', 'signup', ['error' => 'Please fill all required fields.']);
        }
    }
    $pending = RegistryService::get('pending_signups', []);
    $vendors = RegistryService::get('vendors', []);
    $email = strtolower(trim($_POST['email']));
    $mobile = trim($_POST['mobile']);

    foreach (array_merge($pending, $vendors) as $r) {
        if (($r['email'] ?? '') === $email || ($r['mobile'] ?? '') === $mobile) {
            render('Vendor Signup', 'signup', ['error' => 'Email or mobile already exists.']);
        }
    }

    $signup = [
        'signup_id' => RegistryService::nextId('signup', 'SGN'),
        'scheme_key' => 'pm-surya-ghar',
        'owner_name' => htmlspecialchars(trim($_POST['owner_name'])),
        'company_name' => htmlspecialchars(trim($_POST['company_name'])),
        'mobile' => htmlspecialchars($mobile),
        'email' => htmlspecialchars($email),
        'city' => htmlspecialchars(trim($_POST['city'])),
        'state' => htmlspecialchars(trim($_POST['state'])),
        'address' => htmlspecialchars(trim($_POST['address'] ?? '')),
        'business_details' => htmlspecialchars(trim($_POST['business_details'] ?? '')),
        'gst_number' => htmlspecialchars(trim($_POST['gst_number'] ?? '')),
        'website' => htmlspecialchars(trim($_POST['website'] ?? '')),
        'notes' => htmlspecialchars(trim($_POST['notes'] ?? '')),
        'password_hash' => password_hash($_POST['password'], PASSWORD_DEFAULT),
        'verification_status' => 'pending',
        'account_status' => 'inactive',
        'subscription_status' => 'none',
        'requested_plan_key' => 'growth',
        'status' => 'pending',
        'submitted_at' => date('c'),
    ];
    $pending[] = $signup;
    RegistryService::put('pending_signups', $pending);
    AuditService::log('signup_submitted', ['signup_id' => $signup['signup_id'], 'email' => $signup['email']]);
    render('Vendor Signup', 'signup', ['success' => 'Signup received. Waiting for superadmin verification.']);
}
if ($path === '/login' && $method === 'GET') {
    render('Vendor Login', 'login', ['error' => $_GET['error'] ?? null]);
}
if ($path === '/login' && $method === 'POST') {
    [$ok, $error] = AuthService::loginVendor(strtolower(trim($_POST['email'] ?? '')), (string) ($_POST['password'] ?? ''));
    if ($ok) {
        redirectTo('/app/dashboard');
    }
    render('Vendor Login', 'login', compact('error'));
}
if ($path === '/admin/login' && $method === 'GET') {
    render('Admin Login', 'login', ['admin' => true]);
}
if ($path === '/admin/login' && $method === 'POST') {
    if (AuthService::loginAdmin(strtolower(trim($_POST['email'] ?? '')), (string) ($_POST['password'] ?? ''))) {
        redirectTo('/admin/dashboard');
    }
    render('Admin Login', 'login', ['admin' => true, 'error' => 'Invalid credentials']);
}

if (str_starts_with($path, '/admin')) {
    requireAdmin();
    $vendors = RegistryService::get('vendors', []);
    $pending = RegistryService::get('pending_signups', []);

    if ($path === '/admin' || $path === '/admin/dashboard') {
        $counts = [
            'pending' => count(array_filter($pending, fn($p) => ($p['status'] ?? '') === 'pending')),
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
            foreach ($pending as &$row) {
                if ($row['signup_id'] === $_POST['signup_id'] && ($row['status'] ?? '') === 'pending') {
                    if ($_POST['action'] === 'reject') {
                        $row['status'] = 'rejected';
                        $row['verification_status'] = 'rejected';
                        AuditService::log('vendor_rejected', ['signup_id' => $row['signup_id']]);
                    }
                    if ($_POST['action'] === 'verify') {
                        $plan = RegistryService::findBy($plans, 'plan_key', 'growth') ?? $plans[0];
                        $vendor = [
                            'vendor_id' => RegistryService::nextId('vendor', 'VEN'),
                            'tenant_id' => RegistryService::nextId('tenant', 'TEN'),
                            'owner_name' => $row['owner_name'],
                            'company_name' => $row['company_name'],
                            'mobile' => $row['mobile'],
                            'email' => $row['email'],
                            'city' => $row['city'],
                            'state' => $row['state'],
                            'password_hash' => $row['password_hash'],
                            'verification_status' => 'verified',
                            'account_status' => 'active',
                            'subscription_status' => 'trial',
                            'plan_key' => $plan['plan_key'],
                            'trial_end_date' => date('Y-m-d', strtotime('+' . (int)($plan['trial_days'] ?? 14) . ' days')),
                            'enabled_schemes' => ['pm-surya-ghar'],
                            'enabled_modules' => array_values(array_unique(array_merge($plan['included_modules'], ['dashboard', 'company-branding', 'subscription-billing']))),
                            'created_at' => date('c'),
                        ];
                        $vendors[] = $vendor;
                        RegistryService::put('vendors', $vendors);
                        ProvisioningService::provisionTenant($vendor, $plan, $schemes[0]);
                        $subs = RegistryService::get('subscriptions', []);
                        $subs[] = ['vendor_id' => $vendor['vendor_id'], 'tenant_id' => $vendor['tenant_id'], 'plan_key' => $plan['plan_key'], 'status' => 'trial', 'trial_end_date' => $vendor['trial_end_date']];
                        RegistryService::put('subscriptions', $subs);
                        $row['status'] = 'verified';
                        AuditService::log('vendor_verified', ['signup_id' => $row['signup_id'], 'vendor_id' => $vendor['vendor_id']]);
                    }
                }
            }
            unset($row);
            RegistryService::put('pending_signups', $pending);
            redirectTo('/admin/pending-signups');
        }
        render('Pending Signups', 'pending_signups', compact('pending'), 'admin');
    }

    if ($path === '/admin/vendors') {
        if ($method === 'POST' && isset($_POST['vendor_id'], $_POST['action'])) {
            foreach ($vendors as &$v) {
                if ($v['vendor_id'] === $_POST['vendor_id']) {
                    if ($_POST['action'] === 'suspend') {
                        $v['account_status'] = 'suspended';
                        AuditService::log('vendor_suspended', ['vendor_id' => $v['vendor_id']]);
                    }
                    if ($_POST['action'] === 'activate') {
                        $v['account_status'] = 'active';
                        AuditService::log('vendor_activated', ['vendor_id' => $v['vendor_id']]);
                    }
                    if ($_POST['action'] === 'cancel') {
                        $v['account_status'] = 'cancelled';
                        $v['subscription_status'] = 'cancelled';
                    }
                }
            }
            unset($v);
            RegistryService::put('vendors', $vendors);
            redirectTo('/admin/vendors');
        }
        render('Vendors', 'vendors', compact('vendors'), 'admin');
    }

    if ($path === '/admin/schemes') render('Schemes', 'schemes', compact('schemes'), 'admin');
    if ($path === '/admin/modules') render('Modules', 'modules', compact('modules'), 'admin');
    if ($path === '/admin/plans') render('Plans', 'plans', compact('plans'), 'admin');
    if ($path === '/admin/settings') {
        if ($method === 'POST') {
            $settings['platform_name'] = trim($_POST['platform_name'] ?? 'Yojak');
            $settings['allow_signup_globally'] = isset($_POST['allow_signup_globally']);
            $settings['demo_mode'] = isset($_POST['demo_mode']);
            RegistryService::put('superadmin_settings', $settings);
        }
        render('Settings', 'settings', compact('settings'), 'admin');
    }
}

if (str_starts_with($path, '/app')) {
    $vendor = requireVendor();

    if ($path === '/app' || $path === '/app/dashboard') {
        $schemePath = DATA_PATH . '/tenants/tenant_' . $vendor['tenant_id'] . '/pm-surya-ghar';
        $cards = [
            'lead_count' => count((array) \App\Core\JsonStorage::read($schemePath . '/leads.json', [])),
            'draft_quotations' => count((array) \App\Core\JsonStorage::read($schemePath . '/quotations.json', [])),
            'pending_agreements' => count((array) \App\Core\JsonStorage::read($schemePath . '/agreements.json', [])),
            'open_complaints' => count((array) \App\Core\JsonStorage::read($schemePath . '/complaints.json', [])),
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
        [$key, $label] = $moduleRoutes[$path];
        modulePage($key, $label, 'Module not implemented yet. This is a placeholder shell.');
    }
}

http_response_code(404);
echo '404 Not Found';

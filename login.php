<?php
session_start();
require_once __DIR__ . '/functions.php';

const GLOBAL_CONFIG_PATH = __DIR__ . '/storage/system/global_config.json';

$departmentId = strtolower(trim($_POST['department_id'] ?? ''));
$departmentId = preg_replace('/[^a-z0-9_\-]/', '', $departmentId);
$userId = trim($_POST['user_id'] ?? '');
$password = $_POST['password'] ?? '';

if ($departmentId === '' || $userId === '' || $password === '') {
    header('Location: index.php?error=' . urlencode('All fields are required.'));
    exit;
}

// First, check superadmin credentials
$config = read_json(GLOBAL_CONFIG_PATH) ?? [];
$superadmin = $config['superadmin'] ?? null;

if (
    $superadmin
    && $userId === ($superadmin['username'] ?? '')
    && password_verify($password, $superadmin['password_hash'] ?? '')
) {
    $_SESSION['dept_id'] = null;
    $_SESSION['user_id'] = $userId;
    $_SESSION['role_id'] = 'superadmin';
    header('Location: dashboard.php');
    exit;
}

$baseDeptPath = __DIR__ . '/storage/departments/' . $departmentId;
if (!is_dir($baseDeptPath)) {
    header('Location: index.php?error=' . urlencode('Invalid Department.'));
    exit;
}

$departmentMeta = read_json($baseDeptPath . '/department.json') ?? [];
if (($departmentMeta['status'] ?? 'active') === 'suspended') {
    header('Location: index.php?error=' . urlencode('This department is suspended. Please contact the Superadmin.'));
    exit;
}

if (($departmentMeta['status'] ?? 'active') === 'archived') {
    header('Location: index.php?error=' . urlencode('This department is archived and cannot be accessed.'));
    exit;
}

$usersPath = $baseDeptPath . '/users/users.json';
$users = read_json($usersPath);

if (!is_array($users)) {
    header('Location: index.php?error=' . urlencode('User store not found for this department.'));
    exit;
}

$matchedUser = null;
foreach ($users as $user) {
    if (($user['id'] ?? '') === $userId) {
        $matchedUser = $user;
        break;
    }
}

if (!$matchedUser || !password_verify($password, $matchedUser['password_hash'] ?? '')) {
    header('Location: index.php?error=' . urlencode('Invalid credentials. Please check your details.'));
    exit;
}

if (($matchedUser['status'] ?? 'active') !== 'active') {
    header('Location: index.php?error=' . urlencode('Your account is not active. Please contact your Department Administrator.'));
    exit;
}

$rolesPath = $baseDeptPath . '/roles/roles.json';
$roleData = read_json($rolesPath);
$roles = ensure_status(is_array($roleData) ? $roleData : []);
foreach ($roles as $role) {
    if (($role['id'] ?? '') === ($matchedUser['roles'][0] ?? '')) {
        if (($role['status'] ?? 'active') !== 'active') {
            header('Location: index.php?error=' . urlencode('Your assigned role is not active. Please contact your Department Administrator.'));
            exit;
        }
        break;
    }
}

// Set session with scoped identifiers
$_SESSION['dept_id'] = $departmentId;
$_SESSION['user_id'] = $userId;
$matchedRoles = $matchedUser['roles'] ?? [];
$_SESSION['role_id'] = is_array($matchedRoles) && count($matchedRoles) > 0 ? $matchedRoles[0] : null;

header('Location: dashboard.php');
exit;

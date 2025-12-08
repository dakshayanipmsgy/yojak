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

// Set session with scoped identifiers
$_SESSION['dept_id'] = $departmentId;
$_SESSION['user_id'] = $userId;
$matchedRoles = $matchedUser['roles'] ?? [];
$_SESSION['role_id'] = is_array($matchedRoles) && count($matchedRoles) > 0 ? $matchedRoles[0] : null;

header('Location: dashboard.php');
exit;

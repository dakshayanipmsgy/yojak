<?php
session_start();
require_once __DIR__ . '/functions.php';

const GLOBAL_CONFIG_PATH = __DIR__ . '/storage/system/global_config.json';

$authError = null;
$authSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $departmentId = trim($_POST['department_id'] ?? '');
    $userId = trim($_POST['user_id'] ?? '');
    $password = $_POST['password'] ?? '';

    $config = read_json(GLOBAL_CONFIG_PATH) ?? [];
    $superadmin = $config['superadmin'] ?? null;

    if ($superadmin && $userId === ($superadmin['username'] ?? '') && password_verify($password, $superadmin['password_hash'] ?? '')) {
        $_SESSION['user'] = [
            'role' => 'superadmin',
            'username' => $userId,
            'department' => $departmentId,
        ];
        header('Location: dashboard.php');
        exit;
    } else {
        $authError = 'Invalid credentials. Please check your details.';
    }
}

$isLoggedIn = isset($_SESSION['user']);
if ($isLoggedIn && ($_SESSION['user']['role'] ?? '') === 'superadmin') {
    header('Location: dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yojak | Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="main-shell">
        <section class="login-card">
            <div class="brand">
                <div class="logo-placeholder">YJ</div>
                <div class="title">Yojak | Secure Access</div>
            </div>

            <?php if ($isLoggedIn): ?>
                <div class="status success">Welcome, <?php echo htmlspecialchars($_SESSION['user']['username']); ?>. You are logged in.</div>
                <p class="muted">Dashboard placeholder. Future pages will live here.</p>
            <?php else: ?>
                <?php if ($authError): ?>
                    <div class="status error"><?php echo htmlspecialchars($authError); ?></div>
                <?php endif; ?>

                <?php if ($authSuccess): ?>
                    <div class="status success"><?php echo htmlspecialchars($authSuccess); ?></div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <div class="form-group">
                        <label for="department_id">Department ID</label>
                        <input id="department_id" name="department_id" type="text" placeholder="e.g., IT-001" required>
                    </div>
                    <div class="form-group">
                        <label for="user_id">User ID</label>
                        <input id="user_id" name="user_id" type="text" placeholder="admin" required>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" placeholder="••••••••" required>
                    </div>
                    <button type="submit">Login</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>

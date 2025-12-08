<?php
session_start();

$authError = $_GET['error'] ?? null;
$authSuccess = $_GET['success'] ?? null;

$isLoggedIn = isset($_SESSION['user_id'], $_SESSION['role_id']);
if ($isLoggedIn) {
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

                <form method="post" action="login.php" autocomplete="off">
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

<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['dept_id'], $_SESSION['role_id'])) {
    header('Location: index.php');
    exit;
}

$deptId = $_SESSION['dept_id'];
$roleId = $_SESSION['role_id'];

if (!checkPermission('admin.' . $deptId)) {
    header('Location: dashboard.php');
    exit;
}

$deptPath = __DIR__ . '/storage/departments/' . $deptId;
$rolesPath = $deptPath . '/roles/roles.json';
$usersPath = $deptPath . '/users/users.json';

$deptRoles = read_json($rolesPath) ?? [];
$deptUsers = read_json($usersPath) ?? [];

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $customId = trim($_POST['user_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $selectedRole = $_POST['role_id'] ?? '';

    if ($firstName === '' || $lastName === '' || $password === '' || $selectedRole === '') {
        $error = 'All fields are required.';
    } else {
        $result = createUser($deptId, $firstName, $lastName, $password, $selectedRole, $customId);
        if ($result['success']) {
            $message = $result['message'] . ' ID: ' . ($result['user_id'] ?? '');
            $deptUsers = getDepartmentUsers($deptId);
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create User</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>
    <main class="dashboard-shell">
        <section class="dashboard-card">
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Create User</h1>
                    <p class="muted">Add a new team member with a custom identifier.</p>
                </div>
                <div class="actions" style="gap: 8px;">
                    <a class="btn-secondary button-as-link" href="bulk_upload.php">Bulk Import</a>
                    <a class="btn-secondary button-as-link" href="dashboard.php#manage-users">Back</a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="status error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div class="status success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>

            <div class="panel">
                <form method="post" autocomplete="off">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input id="first_name" name="first_name" type="text" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input id="last_name" name="last_name" type="text" required>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="user_id">User ID</label>
                            <input id="user_id" name="user_id" type="text" required>
                            <p class="muted">Default suggestion uses the first letter of the first name and the full surname. You can edit this before saving.</p>
                        </div>
                        <div class="form-group">
                            <label for="role_id">Assign Role</label>
                            <select id="role_id" name="role_id" required>
                                <option value="" disabled selected>Select Role</option>
                                <?php foreach ($deptRoles as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role['id']); ?>"><?php echo htmlspecialchars($role['name'] ?? $role['id']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" required>
                    </div>
                    <button type="submit">Create User</button>
                </form>
            </div>

            <div class="panel">
                <h3>Existing Users</h3>
                <?php if (empty($deptUsers)): ?>
                    <p class="muted">No users created yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deptUsers as $user): ?>
                                <tr>
                                    <td><span class="badge"><?php echo htmlspecialchars($user['id'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($user['name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($user['roles'][0] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($user['status'] ?? 'active'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </section>
    </main>
    <script>
        const firstNameInput = document.getElementById('first_name');
        const lastNameInput = document.getElementById('last_name');
        const userIdInput = document.getElementById('user_id');

        let userIdTouched = false;

        userIdInput.addEventListener('input', () => {
            userIdTouched = true;
        });

        function updateUserIdSuggestion() {
            if (userIdTouched) {
                return;
            }
            const first = firstNameInput.value.trim();
            const last = lastNameInput.value.trim();
            if (!first && !last) {
                userIdInput.value = '';
                return;
            }
            const suggestion = (first.slice(0, 1) + last).toLowerCase().replace(/[^a-z0-9._-]/g, '');
            userIdInput.value = suggestion;
        }

        firstNameInput.addEventListener('input', updateUserIdSuggestion);
        lastNameInput.addEventListener('input', updateUserIdSuggestion);
    </script>
</body>
</html>

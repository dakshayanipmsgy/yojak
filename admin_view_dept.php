<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['role_id']) || $_SESSION['role_id'] !== 'superadmin') {
    header('Location: dashboard.php');
    exit;
}

$deptId = preg_replace('/[^a-z0-9_\-]/', '', trim($_GET['dept_id'] ?? ($_POST['dept_id'] ?? '')));
if ($deptId === '') {
    header('Location: dashboard.php');
    exit;
}

$deptPath = __DIR__ . '/storage/departments/' . $deptId;
if (!is_dir($deptPath)) {
    header('Location: dashboard.php');
    exit;
}

$meta = read_json($deptPath . '/department.json') ?? ['id' => $deptId];
$roles = ensure_status(read_json($deptPath . '/roles/roles.json') ?? []);
$users = ensure_status(read_json($deptPath . '/users/users.json') ?? []);

if (!is_array($roles)) {
    $roles = [];
}
if (!is_array($users)) {
    $users = [];
}

$adminRoleId = 'admin.' . $deptId;
$adminUser = null;
foreach ($users as $user) {
    $userRoles = $user['roles'] ?? [];
    if (in_array($adminRoleId, $userRoles, true)) {
        $adminUser = $user;
        break;
    }
}

$updateError = null;
$updateSuccess = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_admin_user') {
    if ($adminUser === null) {
        $updateError = 'No Department Administrator record found to update.';
    } else {
        $newUserId = strtolower(preg_replace('/[^a-z0-9._-]/', '', trim($_POST['admin_user_id'] ?? '')));
        $newName = trim($_POST['admin_user_name'] ?? '');
        $newPassword = $_POST['admin_user_password'] ?? '';
        $newStatus = $_POST['admin_user_status'] ?? ($adminUser['status'] ?? 'active');

        if ($newUserId === '') {
            $updateError = 'User ID cannot be empty.';
        } elseif (!in_array($newStatus, ['active', 'suspended', 'archived'], true)) {
            $updateError = 'Invalid status value.';
        } else {
            foreach ($users as $user) {
                if (($user['id'] ?? '') === $newUserId && ($user['id'] ?? '') !== ($adminUser['id'] ?? '')) {
                    $updateError = 'Another user already has that ID.';
                    break;
                }
            }
        }

        if ($updateError === null) {
            foreach ($users as &$user) {
                if (($user['id'] ?? '') === ($adminUser['id'] ?? '')) {
                    $user['id'] = $newUserId;
                    if ($newName !== '') {
                        $user['name'] = $newName;
                    }
                    $user['status'] = $newStatus;
                    if ($newPassword !== '') {
                        $user['password_hash'] = password_hash($newPassword, PASSWORD_BCRYPT);
                    }
                    break;
                }
            }
            unset($user);

            if (write_json($deptPath . '/users/users.json', $users)) {
                $updateSuccess = 'Department Administrator updated successfully.';
                $users = ensure_status(read_json($deptPath . '/users/users.json') ?? []);
                $adminUser = null;
                foreach ($users as $user) {
                    $userRoles = $user['roles'] ?? [];
                    if (in_array($adminRoleId, $userRoles, true)) {
                        $adminUser = $user;
                        break;
                    }
                }
            } else {
                $updateError = 'Failed to persist admin changes.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Department</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>
    <main class="dashboard-shell">
        <section class="dashboard-card">
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Department Overview</h1>
                    <p class="muted">ID: <?php echo htmlspecialchars($deptId); ?> | Name: <?php echo htmlspecialchars($meta['name'] ?? $deptId); ?></p>
                </div>
                <div class="actions">
                    <a class="btn-secondary button-as-link" href="dashboard.php">Back</a>
                </div>
            </div>

            <?php if ($updateError): ?>
                <div class="status error"><?php echo htmlspecialchars($updateError); ?></div>
            <?php endif; ?>
            <?php if ($updateSuccess): ?>
                <div class="status success"><?php echo htmlspecialchars($updateSuccess); ?></div>
            <?php endif; ?>

            <div class="panel">
                <h3>Roles (Read-only)</h3>
                <?php if (empty($roles)): ?>
                    <p class="muted">No roles defined for this department.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role ID</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($roles as $role): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($role['name'] ?? $role['id']); ?></td>
                                    <td><span class="badge"><?php echo htmlspecialchars($role['id'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($role['status'] ?? 'active'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="panel">
                <h3>Users (Read-only)</h3>
                <?php if (empty($users)): ?>
                    <p class="muted">No users found.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>User ID</th>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <?php $hasAdminRole = in_array($adminRoleId, $user['roles'] ?? [], true); ?>
                                <tr>
                                    <td><span class="badge"><?php echo htmlspecialchars($user['id'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($user['name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars(implode(', ', $user['roles'] ?? [])); ?></td>
                                    <td><?php echo htmlspecialchars($user['status'] ?? 'active'); ?></td>
                                    <td>
                                        <?php if ($hasAdminRole): ?>
                                            <a class="button-as-link" href="#admin-editor">Edit Admin</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div class="panel" id="admin-editor">
                <h3>Department Administrator Controls</h3>
                <?php if ($adminUser === null): ?>
                    <p class="muted">No administrator user found for this department.</p>
                <?php else: ?>
                    <form class="inline-form" method="post" autocomplete="off">
                        <input type="hidden" name="action" value="update_admin_user">
                        <input type="hidden" name="dept_id" value="<?php echo htmlspecialchars($deptId); ?>">
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="admin_user_id">Admin User ID</label>
                                <input id="admin_user_id" name="admin_user_id" type="text" value="<?php echo htmlspecialchars($adminUser['id'] ?? ''); ?>" required>
                                <p class="muted">Superadmin can rename the account identifier.</p>
                            </div>
                            <div class="form-group">
                                <label for="admin_user_name">Display Name</label>
                                <input id="admin_user_name" name="admin_user_name" type="text" value="<?php echo htmlspecialchars($adminUser['name'] ?? ''); ?>" placeholder="Optional">
                            </div>
                        </div>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="admin_user_password">Password</label>
                                <input id="admin_user_password" name="admin_user_password" type="password" placeholder="Leave blank to keep current password">
                            </div>
                            <div class="form-group">
                                <label for="admin_user_status">Status</label>
                                <select id="admin_user_status" name="admin_user_status">
                                    <option value="active" <?php echo ($adminUser['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="suspended" <?php echo ($adminUser['status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    <option value="archived" <?php echo ($adminUser['status'] ?? '') === 'archived' ? 'selected' : ''; ?>>Archived</option>
                                </select>
                                <p class="muted">Status changes are applied immediately (no requests needed).</p>
                            </div>
                        </div>
                        <button type="submit">Save Administrator</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>

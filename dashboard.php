<?php
session_start();
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['role_id'], $_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$roleId = $_SESSION['role_id'];
$deptId = $_SESSION['dept_id'] ?? null;

$creationSuccess = null;
$creationError = null;
$teamSuccess = null;
$teamError = null;
$departments = [];
$deptMeta = [];
$deptRoles = [];
$deptUsers = [];

if ($roleId === 'superadmin') {
    $departmentsDir = __DIR__ . '/storage/departments';
    if (!is_dir($departmentsDir)) {
        mkdir($departmentsDir, 0755, true);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $deptName = trim($_POST['department_name'] ?? '');
        $newDeptId = trim($_POST['department_id'] ?? '');
        $deptPassword = $_POST['admin_password'] ?? '';

        if ($deptName === '' || $newDeptId === '' || $deptPassword === '') {
            $creationError = 'All fields are required to create a department.';
        } else {
            $result = createDepartment($deptName, $newDeptId, $deptPassword);
            if ($result['success']) {
                $creationSuccess = $result['message'];
            } else {
                $creationError = $result['message'];
            }
        }
    }

    function listDepartments(string $departmentsDir): array
    {
        $departments = [];
        foreach (scandir($departmentsDir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $departmentsDir . '/' . $entry;
            if (!is_dir($path)) {
                continue;
            }

            $meta = read_json($path . '/department.json') ?? [];
            $users = read_json($path . '/users/users.json') ?? [];

            $fileCount = 0;
            $documentsPath = $path . '/documents';
            if (is_dir($documentsPath)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($documentsPath, FilesystemIterator::SKIP_DOTS)
                );
                foreach ($iterator as $fileInfo) {
                    if ($fileInfo->isFile()) {
                        $fileCount++;
                    }
                }
            }

            $departments[] = [
                'id' => $meta['id'] ?? $entry,
                'name' => $meta['name'] ?? $entry,
                'created_at' => $meta['created_at'] ?? date('c', filemtime($path)),
                'user_count' => is_array($users) ? count($users) : 0,
                'file_count' => $fileCount,
            ];
        }

        return $departments;
    }

    $departments = listDepartments($departmentsDir);
} elseif ($roleId && $deptId && checkPermission('admin.' . $deptId)) {
    $deptPath = __DIR__ . '/storage/departments/' . $deptId;
    $metaPath = $deptPath . '/department.json';
    $rolesPath = $deptPath . '/roles/roles.json';
    $usersPath = $deptPath . '/users/users.json';

    $deptMeta = read_json($metaPath) ?? ['id' => $deptId, 'name' => $deptId];
    $deptRoles = read_json($rolesPath) ?? [];
    $deptUsers = read_json($usersPath) ?? [];

    if (!is_array($deptRoles)) {
        $deptRoles = [];
    }
    if (!is_array($deptUsers)) {
        $deptUsers = [];
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        if ($action === 'add_role') {
            $roleName = trim($_POST['role_name'] ?? '');
            if ($roleName === '') {
                $teamError = 'Role name is required.';
            } else {
                $slug = slugify_label($roleName);
                $newRoleId = $slug . '.' . $deptId;

                foreach ($deptRoles as $existingRole) {
                    if (($existingRole['id'] ?? '') === $newRoleId) {
                        $teamError = 'Role already exists.';
                        break;
                    }
                }

                if (!$teamError) {
                    $deptRoles[] = [
                        'id' => $newRoleId,
                        'name' => $roleName,
                        'permissions' => [],
                    ];
                    if (write_json($rolesPath, $deptRoles)) {
                        $teamSuccess = 'Role created successfully.';
                    } else {
                        $teamError = 'Unable to save new role.';
                    }
                }
            }
        } elseif ($action === 'add_user') {
            $fullName = trim($_POST['full_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $roleSelection = $_POST['role_id'] ?? '';

            if ($fullName === '' || $password === '' || $roleSelection === '') {
                $teamError = 'All user fields are required.';
            } else {
                $roleSlug = explode('.', $roleSelection)[0] ?? 'member';
                $baseUserId = 'user.' . $roleSlug . '.' . $deptId;

                $existingIds = array_column($deptUsers, 'id');
                $userIdCandidate = $baseUserId;
                $counter = 2;
                while (in_array($userIdCandidate, $existingIds, true)) {
                    $userIdCandidate = $baseUserId . '_' . $counter;
                    $counter++;
                }

                $deptUsers[] = [
                    'id' => $userIdCandidate,
                    'name' => $fullName,
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'roles' => [$roleSelection],
                ];

                if (write_json($usersPath, $deptUsers)) {
                    $teamSuccess = 'User created successfully with ID: ' . $userIdCandidate;
                } else {
                    $teamError = 'Unable to save new user.';
                }
            }
        }
    }
} else {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $roleId === 'superadmin' ? 'Superadmin Dashboard' : 'Department Dashboard'; ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="dashboard-shell">
        <section class="dashboard-card">
            <?php if ($roleId === 'superadmin'): ?>
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Superadmin Dashboard</h1>
                        <p class="muted">Manage departments. View counts only—no file access.</p>
                    </div>
                    <div class="actions">
                        <a href="index.php" class="btn-secondary button-as-link">Back to Login</a>
                    </div>
                </div>

                <?php if ($creationError): ?>
                    <div class="status error"><?php echo htmlspecialchars($creationError); ?></div>
                <?php endif; ?>
                <?php if ($creationSuccess): ?>
                    <div class="status success"><?php echo htmlspecialchars($creationSuccess); ?></div>
                <?php endif; ?>

                <div class="panel">
                    <h3>Create New Department</h3>
                    <form class="inline-form" method="post" autocomplete="off">
                        <div class="form-group">
                            <label for="department_name">Department Name</label>
                            <input id="department_name" name="department_name" type="text" placeholder="e.g., Road Construction Dept" required>
                        </div>
                        <div class="form-group">
                            <label for="department_id">Department ID</label>
                            <input id="department_id" name="department_id" type="text" placeholder="e.g., road_dept" required>
                        </div>
                        <div class="form-group">
                            <label for="admin_password">Initial Admin Password</label>
                            <input id="admin_password" name="admin_password" type="password" placeholder="••••••••" required>
                        </div>
                        <button type="submit">Create New Department</button>
                    </form>
                </div>

                <div class="panel">
                    <h3>Departments</h3>
                    <?php if (empty($departments)): ?>
                        <p class="muted">No departments created yet.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Department Name</th>
                                    <th>Department ID</th>
                                    <th>Created Date</th>
                                    <th>User Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($departments as $dept): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                            <div class="file-count">Files: <?php echo (int) $dept['file_count']; ?></div>
                                        </td>
                                        <td><span class="badge"><?php echo htmlspecialchars($dept['id']); ?></span></td>
                                        <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($dept['created_at']))); ?></td>
                                        <td><?php echo (int) $dept['user_count']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Department Dashboard</h1>
                        <p class="muted">Department: <?php echo htmlspecialchars($deptMeta['name'] ?? $deptId); ?></p>
                    </div>
                    <div class="actions">
                        <span class="badge">ID: <?php echo htmlspecialchars($deptId); ?></span>
                        <a href="index.php" class="btn-secondary button-as-link">Back to Login</a>
                    </div>
                </div>

                <?php if ($teamError): ?>
                    <div class="status error"><?php echo htmlspecialchars($teamError); ?></div>
                <?php endif; ?>
                <?php if ($teamSuccess): ?>
                    <div class="status success"><?php echo htmlspecialchars($teamSuccess); ?></div>
                <?php endif; ?>

                <div class="panel">
                    <h3>Manage Roles</h3>
                    <form class="inline-form" method="post" autocomplete="off">
                        <input type="hidden" name="action" value="add_role">
                        <div class="form-group">
                            <label for="role_name">Role Name</label>
                            <input id="role_name" name="role_name" type="text" placeholder="e.g., Junior Engineer" required>
                        </div>
                        <button type="submit">Create Role</button>
                    </form>
                    <?php if (empty($deptRoles)): ?>
                        <p class="muted">No roles defined yet.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Role Name</th>
                                    <th>Role ID</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deptRoles as $role): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($role['name'] ?? $role['id']); ?></td>
                                        <td><span class="badge"><?php echo htmlspecialchars($role['id']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="panel">
                    <h3>Manage Users</h3>
                    <form class="inline-form" method="post" autocomplete="off">
                        <input type="hidden" name="action" value="add_user">
                        <div class="form-group">
                            <label for="full_name">Full Name</label>
                            <input id="full_name" name="full_name" type="text" placeholder="e.g., Priya Sharma" required>
                        </div>
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input id="password" name="password" type="password" placeholder="Temporary password" required>
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
                        <button type="submit">Create User</button>
                    </form>

                    <?php if (empty($deptUsers)): ?>
                        <p class="muted">No users created yet.</p>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>User ID</th>
                                    <th>Name</th>
                                    <th>Role</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deptUsers as $user): ?>
                                    <tr>
                                        <td><span class="badge"><?php echo htmlspecialchars($user['id']); ?></span></td>
                                        <td><?php echo htmlspecialchars($user['name'] ?? ''); ?></td>
                                        <td><?php echo htmlspecialchars(($user['roles'][0] ?? '') ?: ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>

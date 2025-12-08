<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['role_id'])) {
    header('Location: index.php');
    exit;
}

$roleId = $_SESSION['role_id'];
$deptId = $_SESSION['dept_id'] ?? null;

$creationSuccess = null;
$creationError = null;
$teamSuccess = null;
$teamError = null;
$inboxDocs = [];
$outboxDocs = [];
$deptUsersMap = [];
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
        $adminUserId = trim($_POST['admin_user_id'] ?? '');

        if ($deptName === '' || $newDeptId === '' || $deptPassword === '') {
            $creationError = 'All fields are required to create a department.';
        } else {
            $result = createDepartment($deptName, $newDeptId, $deptPassword, $adminUserId);
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
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $roleSelection = $_POST['role_id'] ?? '';
            $customId = trim($_POST['user_id'] ?? '');

            if ($firstName === '' || $lastName === '' || $password === '' || $roleSelection === '') {
                $teamError = 'All user fields are required.';
            } else {
                $result = createUser($deptId, $firstName, $lastName, $password, $roleSelection, $customId);
                if ($result['success']) {
                    $teamSuccess = $result['message'] . ' ID: ' . ($result['user_id'] ?? '');
                    $deptUsers = getDepartmentUsers($deptId);
                } else {
                    $teamError = $result['message'];
                }
            }
        }
    }
$isGeneralUser = false;
} else {
    if (!$deptId) {
        header('Location: index.php');
        exit;
    }

    $deptPath = __DIR__ . '/storage/departments/' . $deptId;
    $metaPath = $deptPath . '/department.json';
    $deptMeta = read_json($metaPath) ?? ['id' => $deptId, 'name' => $deptId];
    $deptUsers = getDepartmentUsers($deptId);
    foreach ($deptUsers as $user) {
        $deptUsersMap[$user['id'] ?? ''] = $user['name'] ?? ($user['id'] ?? '');
    }

    $documentsPath = $deptPath . '/documents';
    $currentDate = date('Y-m-d');
    if (is_dir($documentsPath)) {
        foreach (scandir($documentsPath) as $file) {
            if ($file === '.' || $file === '..' || !str_ends_with($file, '.json')) {
                continue;
            }

            $doc = read_json($documentsPath . '/' . $file);
            if (!is_array($doc)) {
                continue;
            }

            $lastHistory = null;
            if (isset($doc['history']) && is_array($doc['history']) && !empty($doc['history'])) {
                $lastHistory = $doc['history'][count($doc['history']) - 1];
            }

            if (($doc['current_owner'] ?? '') === ($_SESSION['user_id'] ?? '')) {
                $dueDate = $doc['due_date'] ?? null;
                $urgency = 'normal';
                if ($dueDate) {
                    if ($currentDate > $dueDate) {
                        $urgency = 'expired';
                    } elseif ($currentDate === $dueDate) {
                        $urgency = 'urgent';
                    }
                }

                $inboxDocs[] = [
                    'id' => $doc['id'] ?? '',
                    'title' => $doc['title'] ?? '',
                    'from' => $lastHistory['from'] ?? ($doc['created_by'] ?? ''),
                    'time' => $lastHistory['time'] ?? ($doc['created_at'] ?? ''),
                    'due_date' => $dueDate,
                    'urgency' => $urgency,
                ];
            }

            if (($doc['created_by'] ?? '') === ($_SESSION['user_id'] ?? '') && ($doc['current_owner'] ?? '') !== ($_SESSION['user_id'] ?? '')) {
                $outboxDocs[] = [
                    'id' => $doc['id'] ?? '',
                    'title' => $doc['title'] ?? '',
                    'current_owner' => $doc['current_owner'] ?? '',
                ];
            }
        }
    }
    if (!empty($inboxDocs)) {
        $urgencyOrder = ['expired' => 0, 'urgent' => 1, 'normal' => 2];
        usort($inboxDocs, function (array $a, array $b) use ($urgencyOrder) {
            $aPriority = $urgencyOrder[$a['urgency'] ?? 'normal'] ?? 2;
            $bPriority = $urgencyOrder[$b['urgency'] ?? 'normal'] ?? 2;

            if ($aPriority !== $bPriority) {
                return $aPriority <=> $bPriority;
            }

            $timeA = strtotime($a['time'] ?? '0');
            $timeB = strtotime($b['time'] ?? '0');
            return $timeB <=> $timeA;
        });
    }
    $isGeneralUser = true;
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
    <?php include __DIR__ . '/navbar.php'; ?>
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
                            <label for="admin_user_id">Initial Admin User ID</label>
                            <input id="admin_user_id" name="admin_user_id" type="text" placeholder="e.g., jdoe" aria-describedby="admin-user-help">
                            <p id="admin-user-help" class="muted">If left empty, the default ID user.admin.{department_id} will be used.</p>
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
                        <?php if (!$isGeneralUser): ?>
                            <a href="backup.php" class="btn-primary button-as-link">Download Department Data (.zip)</a>
                        <?php endif; ?>
                        <a href="index.php" class="btn-secondary button-as-link">Back to Login</a>
                    </div>
                </div>

                <?php if ($isGeneralUser): ?>
                    <div class="panel">
                        <h3>Welcome</h3>
                        <p class="muted">Use the quick actions below to work with contractor templates.</p>
                        <div class="actions" style="flex-wrap: wrap;">
                            <a class="button-as-link" href="create_document.php">Generate Document</a>
                        </div>
                    </div>
                    <div class="panel" id="inbox">
                        <h3>Inbox</h3>
                        <?php if (empty($inboxDocs)): ?>
                            <p class="muted">No documents in your inbox.</p>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Received From</th>
                                        <th>Due</th>
                                        <th>Date</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($inboxDocs as $doc): ?>
                                        <tr class="<?php echo htmlspecialchars($doc['urgency'] !== 'normal' ? $doc['urgency'] : ''); ?>">
                                            <td><?php echo htmlspecialchars($doc['title']); ?></td>
                                            <td><?php echo htmlspecialchars($deptUsersMap[$doc['from']] ?? $doc['from']); ?></td>
                                            <td>
                                                <?php if (!empty($doc['due_date'])): ?>
                                                    <div class="due-date-value"><?php echo htmlspecialchars(date('M d, Y', strtotime($doc['due_date']))); ?></div>
                                                    <?php if (($doc['urgency'] ?? '') === 'expired'): ?>
                                                        <div class="overdue-label">OVERDUE</div>
                                                    <?php elseif (($doc['urgency'] ?? '') === 'urgent'): ?>
                                                        <div class="due-today-label">Due Today</div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="muted">No due date</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($doc['time'] ?? 'now'))); ?></td>
                                            <td><a class="button-as-link" href="view_document.php?id=<?php echo urlencode($doc['id']); ?>">Open</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div class="panel" id="sent-items">
                        <h3>Sent Items</h3>
                        <?php if (empty($outboxDocs)): ?>
                            <p class="muted">No documents have been sent yet.</p>
                        <?php else: ?>
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Currently With</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($outboxDocs as $doc): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($doc['title']); ?></td>
                                            <td><?php echo htmlspecialchars($deptUsersMap[$doc['current_owner']] ?? $doc['current_owner']); ?></td>
                                            <td><a class="button-as-link" href="view_document.php?id=<?php echo urlencode($doc['id']); ?>">Open</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php if ($teamError): ?>
                        <div class="status error"><?php echo htmlspecialchars($teamError); ?></div>
                    <?php endif; ?>
                    <?php if ($teamSuccess): ?>
                        <div class="status success"><?php echo htmlspecialchars($teamSuccess); ?></div>
                    <?php endif; ?>

                    <div class="panel">
                        <h3>Quick Actions</h3>
                        <div class="actions" style="flex-wrap: wrap;">
                            <a class="button-as-link" href="manage_contractors.php">Manage Contractors</a>
                            <a class="button-as-link" href="manage_templates.php">Manage Templates</a>
                            <a class="button-as-link" href="create_document.php">Generate Document</a>
                        </div>
                    </div>

                    <div class="panel" id="manage-roles">
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

                    <div class="panel" id="manage-users">
                        <h3>Manage Users</h3>
                        <form class="inline-form" method="post" autocomplete="off">
                            <input type="hidden" name="action" value="add_user">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="first_name">First Name</label>
                                    <input id="first_name" name="first_name" type="text" placeholder="e.g., Priya" required>
                                </div>
                                <div class="form-group">
                                    <label for="last_name">Last Name</label>
                                    <input id="last_name" name="last_name" type="text" placeholder="e.g., Sharma" required>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="user_id">User ID</label>
                                    <input id="user_id" name="user_id" type="text" placeholder="e.g., psharma" required>
                                    <p class="muted">Automatically suggests first initial + surname. You may edit before saving.</p>
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
                                <input id="password" name="password" type="password" placeholder="Temporary password" required>
                            </div>
                            <button type="submit">Create User</button>
                            <a class="button-as-link" href="bulk_upload.php">Bulk Import</a>
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
            <?php endif; ?>
        </section>
    </main>
    <?php if ($roleId && $deptId && checkPermission('admin.' . $deptId)): ?>
    <script>
        const dashboardFirstName = document.getElementById('first_name');
        const dashboardLastName = document.getElementById('last_name');
        const dashboardUserId = document.getElementById('user_id');

        if (dashboardFirstName && dashboardLastName && dashboardUserId) {
            let dashboardUserIdTouched = false;

            dashboardUserId.addEventListener('input', () => {
                dashboardUserIdTouched = true;
            });

            const updateDashboardUserId = () => {
                if (dashboardUserIdTouched) {
                    return;
                }
                const first = dashboardFirstName.value.trim();
                const last = dashboardLastName.value.trim();
                const suggestion = (first.slice(0, 1) + last).toLowerCase().replace(/[^a-z0-9._-]/g, '');
                dashboardUserId.value = suggestion;
            };

            dashboardFirstName.addEventListener('input', updateDashboardUserId);
            dashboardLastName.addEventListener('input', updateDashboardUserId);
        }
    </script>
    <?php endif; ?>
</body>
</html>

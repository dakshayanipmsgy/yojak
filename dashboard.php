<?php
session_start();
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'superadmin') {
    header('Location: index.php');
    exit;
}

$creationSuccess = null;
$creationError = null;

$departmentsDir = __DIR__ . '/storage/departments';
if (!is_dir($departmentsDir)) {
    mkdir($departmentsDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deptName = trim($_POST['department_name'] ?? '');
    $deptId = trim($_POST['department_id'] ?? '');
    $deptPassword = $_POST['admin_password'] ?? '';

    if ($deptName === '' || $deptId === '' || $deptPassword === '') {
        $creationError = 'All fields are required to create a department.';
    } else {
        $result = createDepartment($deptName, $deptId, $deptPassword);
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin Dashboard</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="dashboard-shell">
        <section class="dashboard-card">
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
        </section>
    </main>
</body>
</html>

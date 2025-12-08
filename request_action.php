<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['role_id'], $_SESSION['dept_id'])) {
    header('Location: index.php');
    exit;
}

$deptId = $_SESSION['dept_id'];
if (!checkPermission('admin.' . $deptId)) {
    header('Location: dashboard.php');
    exit;
}

$deptPath = __DIR__ . '/storage/departments/' . $deptId;
$users = ensure_status(getDepartmentUsers($deptId));
$rolesData = read_json($deptPath . '/roles/roles.json');
$roles = ensure_status(is_array($rolesData) ? $rolesData : []);

$users = array_values(array_filter($users, function (array $user): bool {
    return ($user['status'] ?? 'active') !== 'archived';
}));

$roles = array_values(array_filter($roles, function (array $role): bool {
    return ($role['status'] ?? 'active') !== 'archived';
}));

$statusMessage = null;
$statusError = null;

$requests = load_requests();
$deptRequests = array_filter($requests, function (array $request) use ($deptId): bool {
    return ($request['dept_id'] ?? '') === $deptId;
});

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetSelection = $_POST['target_selection'] ?? '';
    $action = $_POST['requested_action'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($targetSelection === '' || $action === '' || $reason === '') {
        $statusError = 'All fields are required.';
    } else {
        [$targetType, $targetId] = array_pad(explode('|', $targetSelection, 2), 2, '');
        if (!in_array($targetType, ['user', 'role'], true) || $targetId === '') {
            $statusError = 'Invalid target selected.';
        } elseif (!in_array($action, ['Suspend', 'Archive'], true)) {
            $statusError = 'Invalid action requested.';
        } else {
            $targetExists = false;
            if ($targetType === 'user') {
                foreach ($users as $user) {
                    if (($user['id'] ?? '') === $targetId) {
                        $targetExists = true;
                        break;
                    }
                }
            } else {
                foreach ($roles as $role) {
                    if (($role['id'] ?? '') === $targetId) {
                        $targetExists = true;
                        break;
                    }
                }
            }

            if (!$targetExists) {
                $statusError = 'Target not found in this department.';
            } else {
                $newRequest = [
                    'id' => generate_id(),
                    'dept_id' => $deptId,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'action' => $action,
                    'reason' => $reason,
                    'status' => 'pending',
                    'created_at' => date('c'),
                    'requested_by' => $_SESSION['user_id'],
                ];

                $requests[] = $newRequest;
                if (save_requests($requests)) {
                    $statusMessage = 'Request submitted successfully.';
                    $deptRequests[] = $newRequest;
                } else {
                    $statusError = 'Failed to record request. Please try again.';
                }
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
    <title>Request Action</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>
    <main class="dashboard-shell">
        <section class="dashboard-card">
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Request Governance Action</h1>
                    <p class="muted">Submit suspension or archival requests to Superadmin.</p>
                </div>
                <div class="actions">
                    <a class="btn-secondary button-as-link" href="dashboard.php">Back</a>
                </div>
            </div>

            <?php if ($statusError): ?>
                <div class="status error"><?php echo htmlspecialchars($statusError); ?></div>
            <?php endif; ?>
            <?php if ($statusMessage): ?>
                <div class="status success"><?php echo htmlspecialchars($statusMessage); ?></div>
            <?php endif; ?>

            <div class="panel">
                <h3>New Request</h3>
                <form class="inline-form" method="post" autocomplete="off">
                    <div class="form-group">
                        <label for="target_selection">Target</label>
                        <select id="target_selection" name="target_selection" required>
                            <option value="" disabled selected>Select user or role</option>
                            <?php if (!empty($users)): ?>
                                <optgroup label="Users">
                                    <?php foreach ($users as $user): ?>
                                        <option value="user|<?php echo htmlspecialchars($user['id']); ?>">
                                            <?php echo htmlspecialchars(($user['name'] ?? $user['id']) . ' (' . ($user['id'] ?? '') . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                            <?php if (!empty($roles)): ?>
                                <optgroup label="Roles">
                                    <?php foreach ($roles as $role): ?>
                                        <option value="role|<?php echo htmlspecialchars($role['id']); ?>">
                                            <?php echo htmlspecialchars(($role['name'] ?? $role['id']) . ' (' . ($role['id'] ?? '') . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="requested_action">Action</label>
                        <select id="requested_action" name="requested_action" required>
                            <option value="" disabled selected>Select action</option>
                            <option value="Suspend">Suspend</option>
                            <option value="Archive">Archive</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="reason">Reason</label>
                        <textarea id="reason" name="reason" rows="3" placeholder="Provide justification" required></textarea>
                    </div>
                    <button type="submit">Submit Request</button>
                </form>
            </div>

            <div class="panel">
                <h3>Request History</h3>
                <?php if (empty($deptRequests)): ?>
                    <p class="muted">No requests submitted yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Target</th>
                                <th>Action</th>
                                <th>Status</th>
                                <th>Reason</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($deptRequests as $request): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(($request['target_type'] ?? '') . ': ' . ($request['target_id'] ?? '')); ?></td>
                                    <td><span class="badge"><?php echo htmlspecialchars($request['action'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($request['status'] ?? 'pending'); ?></td>
                                    <td><?php echo htmlspecialchars($request['reason'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($request['created_at'] ?? 'now'))); ?></td>
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

<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['dept_id'], $_SESSION['role_id'])) {
    header('Location: index.php');
    exit;
}

$deptId = $_SESSION['dept_id'];
$roleId = $_SESSION['role_id'];
$adminId = $_SESSION['user_id'] ?? '';

if (!checkPermission('admin.' . $deptId)) {
    header('Location: dashboard.php');
    exit;
}

$deptUsers = getDepartmentUsers($deptId);
$userMap = [];
foreach ($deptUsers as $user) {
    $userMap[$user['id'] ?? ''] = $user['name'] ?? ($user['id'] ?? '');
}

function loadDepartmentDocuments(string $deptId): array
{
    $documents = [];
    $files = glob(__DIR__ . '/storage/departments/' . $deptId . '/documents/*.json') ?: [];

    foreach ($files as $file) {
        $document = read_json($file);
        if (!is_array($document)) {
            continue;
        }

        if (!isset($document['id'])) {
            $document['id'] = pathinfo($file, PATHINFO_FILENAME);
        }

        $lastUpdated = $document['created_at'] ?? '';
        if (isset($document['history']) && is_array($document['history']) && !empty($document['history'])) {
            $lastHistory = $document['history'][count($document['history']) - 1];
            $lastUpdated = $lastHistory['time'] ?? $lastUpdated;
        }

        $document['_last_updated'] = $lastUpdated;
        $documents[] = $document;
    }

    return $documents;
}

$documents = loadDepartmentDocuments($deptId);
$statusMessage = null;
$statusError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'pull_file') {
        $docId = trim($_POST['doc_id'] ?? '');
        if ($docId === '') {
            $statusError = 'Document ID is required.';
        } else {
            $documentPath = __DIR__ . '/storage/departments/' . $deptId . '/documents/' . $docId . '.json';
            $document = read_json($documentPath);

            if (!is_array($document)) {
                $statusError = 'Document not found.';
            } else {
                $document['current_owner'] = $adminId;

                if (!isset($document['history']) || !is_array($document['history'])) {
                    $document['history'] = [];
                }

                $document['history'][] = [
                    'action' => 'admin_override',
                    'note' => 'File pulled by Administrator',
                    'by' => $adminId,
                    'time' => date('c'),
                ];

                if (write_json($documentPath, $document)) {
                    $statusMessage = 'File pulled successfully.';
                    $documents = loadDepartmentDocuments($deptId);
                } else {
                    $statusError = 'Unable to update document file.';
                }
            }
        }
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="master_register_' . $deptId . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Document ID', 'Title', 'Current Owner', 'Status']);

    foreach ($documents as $doc) {
        fputcsv($output, [
            $doc['id'] ?? '',
            $doc['title'] ?? '',
            $userMap[$doc['current_owner'] ?? ''] ?? ($doc['current_owner'] ?? ''),
            $doc['status'] ?? '',
        ]);
    }

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Register</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>
    <main class="dashboard-shell">
        <section class="dashboard-card">
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Master Register</h1>
                    <p class="muted">Full visibility of all documents in the department.</p>
                </div>
                <div class="actions" style="gap: 8px;">
                    <a class="btn-secondary button-as-link" href="?export=csv">Export Report</a>
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
                <?php if (empty($documents)): ?>
                    <p class="muted">No documents found for this department.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Document ID</th>
                                <th>Title</th>
                                <th>Created By</th>
                                <th>Current Location</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($documents as $doc): ?>
                                <?php
                                    $lastUpdated = $doc['_last_updated'] ?? '';
                                    $isBottleneck = ($doc['status'] ?? '') === 'pending' && $lastUpdated && (time() - strtotime($lastUpdated) > 60 * 60 * 24 * 7);
                                ?>
                                <tr class="<?php echo $isBottleneck ? 'row-warning' : ''; ?>">
                                    <td><?php echo htmlspecialchars($doc['id'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($doc['title'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($userMap[$doc['created_by'] ?? ''] ?? ($doc['created_by'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars($userMap[$doc['current_owner'] ?? ''] ?? ($doc['current_owner'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars($doc['status'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($lastUpdated ? date('M d, Y H:i', strtotime($lastUpdated)) : ''); ?></td>
                                    <td>
                                        <form method="post" style="display: inline;" onsubmit="return confirm('Pull this file to your inbox?');">
                                            <input type="hidden" name="action" value="pull_file">
                                            <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($doc['id'] ?? ''); ?>">
                                            <button type="submit" class="btn-secondary">Pull File</button>
                                        </form>
                                    </td>
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

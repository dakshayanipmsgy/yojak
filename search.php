<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['dept_id'], $_SESSION['role_id'])) {
    header('Location: index.php');
    exit;
}

$deptId = $_SESSION['dept_id'];
$userId = $_SESSION['user_id'] ?? '';
$isAdmin = checkPermission('admin.' . $deptId);

$searchTerm = trim($_GET['q'] ?? '');
$results = [];
$deptUsers = getDepartmentUsers($deptId);
$userMap = [];
foreach ($deptUsers as $user) {
    $userMap[$user['id'] ?? ''] = $user['name'] ?? ($user['id'] ?? '');
}

if ($searchTerm !== '') {
    $documentsPath = __DIR__ . '/storage/departments/' . $deptId . '/documents/*.json';
    $files = glob($documentsPath) ?: [];

    foreach ($files as $file) {
        $document = read_json($file);
        if (!is_array($document)) {
            continue;
        }

        $docId = $document['id'] ?? pathinfo($file, PATHINFO_FILENAME);
        $title = $document['title'] ?? '';

        if (stripos($docId, $searchTerm) === false && stripos($title, $searchTerm) === false) {
            continue;
        }

        if (!$isAdmin) {
            $touched = false;

            if (($document['current_owner'] ?? '') === $userId || ($document['created_by'] ?? '') === $userId) {
                $touched = true;
            }

            if (!$touched && isset($document['history']) && is_array($document['history'])) {
                foreach ($document['history'] as $entry) {
                    foreach (['from', 'to', 'by'] as $field) {
                        if (($entry[$field] ?? '') === $userId) {
                            $touched = true;
                            break 2;
                        }
                    }
                }
            }

            if (!$touched) {
                continue;
            }
        }

        $results[] = [
            'id' => $docId,
            'title' => $title,
            'current_owner' => $document['current_owner'] ?? '',
            'status' => $document['status'] ?? '',
            'created_at' => $document['created_at'] ?? '',
            'updated_at' => isset($document['history']) && is_array($document['history']) && !empty($document['history'])
                ? ($document['history'][count($document['history']) - 1]['time'] ?? '')
                : ($document['created_at'] ?? ''),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Documents</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>
    <main class="dashboard-shell">
        <section class="dashboard-card">
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Search</h1>
                    <p class="muted">Search documents by ID or Title within your department.</p>
                </div>
                <div class="actions">
                    <a href="dashboard.php" class="btn-secondary button-as-link">Back</a>
                </div>
            </div>

            <div class="panel">
                <form class="inline-form" method="get" autocomplete="off">
                    <div class="form-group" style="width: 100%;">
                        <label for="search">Search Term</label>
                        <input id="search" name="q" type="text" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Enter ID or Title" required>
                    </div>
                    <button type="submit">Search</button>
                </form>
            </div>

            <?php if ($searchTerm === ''): ?>
                <p class="muted">Enter a search term to find documents.</p>
            <?php elseif (empty($results)): ?>
                <div class="status error">No documents matched your search or you do not have permission to view them.</div>
            <?php else: ?>
                <div class="panel">
                    <h3>Results</h3>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Title</th>
                                <th>Current Owner</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $doc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($doc['id']); ?></td>
                                    <td><?php echo htmlspecialchars($doc['title']); ?></td>
                                    <td><?php echo htmlspecialchars($userMap[$doc['current_owner']] ?? $doc['current_owner']); ?></td>
                                    <td><?php echo htmlspecialchars($doc['status']); ?></td>
                                    <td><?php echo htmlspecialchars($doc['updated_at'] ? date('M d, Y H:i', strtotime($doc['updated_at'])) : ''); ?></td>
                                    <td><a class="button-as-link" href="view_document.php?id=<?php echo urlencode($doc['id']); ?>">Open</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>

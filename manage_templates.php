<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['role_id'])) {
    header('Location: index.php');
    exit;
}

$roleId = $_SESSION['role_id'];
$deptId = $_SESSION['dept_id'] ?? null;
$isSuperadmin = $roleId === 'superadmin';

if (!$isSuperadmin) {
    if ($deptId === null || !checkPermission('admin.' . $deptId)) {
        header('Location: dashboard.php');
        exit;
    }
}

$departmentsDir = __DIR__ . '/storage/departments';
$availableDepartments = [];
if (is_dir($departmentsDir)) {
    foreach (scandir($departmentsDir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $deptPathCandidate = $departmentsDir . '/' . $entry;
        if (is_dir($deptPathCandidate)) {
            $availableDepartments[] = $entry;
        }
    }
}

$selectedScope = $_POST['template_scope'] ?? $_GET['template_scope'] ?? ($isSuperadmin ? 'universal' : 'department');
$targetDept = $isSuperadmin ? trim($_POST['target_dept'] ?? $_GET['target_dept'] ?? ($deptId ?? '')) : $deptId;
$targetDept = preg_replace('/[^a-z0-9_\-]/', '', $targetDept ?? '');

if ($selectedScope === 'department' && $targetDept === '' && !empty($availableDepartments)) {
    $targetDept = $availableDepartments[0];
}

$templatesDir = $selectedScope === 'universal'
    ? __DIR__ . '/storage/system/templates'
    : __DIR__ . '/storage/departments/' . $targetDept . '/templates';
$templatesIndexPath = $templatesDir . '/templates.json';
$templates = [];
if ($selectedScope === 'universal' || $targetDept !== '') {
    $templates = read_json($templatesIndexPath);
    if (!is_array($templates)) {
        $templates = [];
    }
}

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['template_name'] ?? '');
    $content = $_POST['template_content'] ?? '';

    if ($selectedScope === 'department' && $targetDept === '') {
        $errorMessage = 'Please choose a department for department-specific templates.';
    } elseif ($selectedScope === 'department' && !is_dir(__DIR__ . '/storage/departments/' . $targetDept)) {
        $errorMessage = 'Selected department does not exist.';
    } elseif ($name === '' || $content === '') {
        $errorMessage = 'Template name and content are required.';
    } else {
        if (!is_dir($templatesDir)) {
            mkdir($templatesDir, 0755, true);
        }

        $slug = slugify_label($name);
        $filename = $slug . '-' . substr(generate_id(), 0, 8) . '.html';
        $filepath = $templatesDir . '/' . $filename;

        if (file_put_contents($filepath, $content, LOCK_EX) === false) {
            $errorMessage = 'Failed to save template file.';
        } else {
            $templates[] = [
                'id' => generate_id(),
                'title' => $name,
                'filename' => $filename,
                'created_at' => date('c'),
            ];

            if (write_json($templatesIndexPath, $templates)) {
                $successMessage = 'Template saved successfully.';
            } else {
                $errorMessage = 'Failed to update template index.';
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
    <title>Manage Templates</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>
    <main class="dashboard-shell">
        <section class="dashboard-card">
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Template Manager</h1>
                    <?php if ($selectedScope === 'universal'): ?>
                        <p class="muted">Scope: Universal Templates</p>
                    <?php elseif ($targetDept !== ''): ?>
                        <p class="muted">Department: <?php echo htmlspecialchars($targetDept); ?></p>
                    <?php else: ?>
                        <p class="muted">Select a department to manage templates.</p>
                    <?php endif; ?>
                </div>
                <div class="actions">
                    <a href="dashboard.php" class="btn-secondary button-as-link">Back</a>
                </div>
            </div>

            <div class="panel">
                <h3>Create New Template</h3>
                <p class="muted">Use {{variable}} syntax for placeholders. Available options include {{department_name}}, {{contractor_name}}, {{contractor_address}}, {{contractor_pan}}, {{contractor_gst}}, {{contractor_mobile}}, and {{current_date}}.</p>

                <?php if ($errorMessage): ?>
                    <div class="status error"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>
                <?php if ($successMessage): ?>
                    <div class="status success"><?php echo htmlspecialchars($successMessage); ?></div>
                <?php endif; ?>

                <?php if ($isSuperadmin): ?>
                    <form class="inline-form" method="get" autocomplete="off" style="margin-bottom:12px;">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Template Scope</label>
                                <div class="radio-group" style="display:flex; gap:12px; align-items:center;">
                                    <label><input type="radio" name="template_scope" value="universal" <?php echo $selectedScope === 'universal' ? 'checked' : ''; ?>> Universal</label>
                                    <label><input type="radio" name="template_scope" value="department" <?php echo $selectedScope === 'department' ? 'checked' : ''; ?>> Department Specific</label>
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="target_dept">Target Department (for Department scope)</label>
                                <select id="target_dept" name="target_dept" <?php echo $selectedScope === 'department' ? '' : 'disabled'; ?>>
                                    <option value="">Select department</option>
                                    <?php foreach ($availableDepartments as $deptOption): ?>
                                        <option value="<?php echo htmlspecialchars($deptOption); ?>" <?php echo $targetDept === $deptOption ? 'selected' : ''; ?>><?php echo htmlspecialchars($deptOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="muted">Choose where to store the template.</p>
                            </div>
                        </div>
                        <button type="submit">Update View</button>
                    </form>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="template_scope" value="<?php echo htmlspecialchars($selectedScope); ?>">
                    <input type="hidden" name="target_dept" value="<?php echo htmlspecialchars($targetDept); ?>">
                    <div class="form-group">
                        <label for="template_name">Template Name</label>
                        <input id="template_name" name="template_name" type="text" placeholder="e.g., Work Order" required>
                    </div>
                    <div class="form-group">
                        <label for="template_content">Template Content (HTML)</label>
                        <textarea id="template_content" name="template_content" rows="10" placeholder="Enter HTML with placeholders" required></textarea>
                    </div>
                    <button type="submit">Save Template</button>
                </form>
            </div>

            <div class="panel">
                <h3>Saved Templates</h3>
                <?php if (empty($templates)): ?>
                    <p class="muted">No templates created yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Filename</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($templates as $template): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($template['title'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($template['filename'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime($template['created_at'] ?? 'now'))); ?></td>
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

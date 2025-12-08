<?php
session_start();
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['role_id'], $_SESSION['user_id'], $_SESSION['dept_id'])) {
    header('Location: index.php');
    exit;
}

$deptId = $_SESSION['dept_id'];
if (!checkPermission('admin.' . $deptId)) {
    header('Location: dashboard.php');
    exit;
}

$templatesDir = __DIR__ . '/storage/departments/' . $deptId . '/templates';
$templatesIndexPath = $templatesDir . '/templates.json';
$templates = read_json($templatesIndexPath);
if (!is_array($templates)) {
    $templates = [];
}

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['template_name'] ?? '');
    $content = $_POST['template_content'] ?? '';

    if ($name === '' || $content === '') {
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
    <main class="dashboard-shell">
        <section class="dashboard-card">
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Template Manager</h1>
                    <p class="muted">Department: <?php echo htmlspecialchars($deptId); ?></p>
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

                <form method="post" autocomplete="off">
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

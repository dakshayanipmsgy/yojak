<?php
session_start();
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['role_id'], $_SESSION['user_id'], $_SESSION['dept_id'])) {
    header('Location: index.php');
    exit;
}

$deptId = $_SESSION['dept_id'];
$deptPath = __DIR__ . '/storage/departments/' . $deptId;
$meta = read_json($deptPath . '/department.json') ?? ['name' => $deptId];

$contractorsPath = $deptPath . '/data/contractors.json';
$contractors = read_json($contractorsPath);
if (!is_array($contractors)) {
    $contractors = [];
}

$templatesDir = $deptPath . '/templates';
$templatesIndexPath = $templatesDir . '/templates.json';
$templates = read_json($templatesIndexPath);
if (!is_array($templates)) {
    $templates = [];
}

$selectedContractorId = $_POST['contractor_id'] ?? '';
$selectedTemplateId = $_POST['template_id'] ?? '';
$generatedHtml = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $templateMeta = null;
    foreach ($templates as $template) {
        if (($template['id'] ?? '') === $selectedTemplateId) {
            $templateMeta = $template;
            break;
        }
    }

    $contractorData = null;
    foreach ($contractors as $contractor) {
        if (($contractor['id'] ?? '') === $selectedContractorId) {
            $contractorData = $contractor;
            break;
        }
    }

    if (!$templateMeta) {
        $errorMessage = 'Template not found.';
    } elseif (!$contractorData) {
        $errorMessage = 'Contractor not found.';
    } else {
        $templateFile = $templatesDir . '/' . ($templateMeta['filename'] ?? '');
        if (!file_exists($templateFile)) {
            $errorMessage = 'Template file missing on disk.';
        } else {
            $templateContent = file_get_contents($templateFile);
            if ($templateContent === false) {
                $errorMessage = 'Unable to read template content.';
            } else {
                $replacements = [
                    '{{department_name}}' => htmlspecialchars($meta['name'] ?? $deptId),
                    '{{contractor_name}}' => htmlspecialchars($contractorData['name'] ?? ''),
                    '{{contractor_address}}' => htmlspecialchars($contractorData['address'] ?? ''),
                    '{{contractor_pan}}' => htmlspecialchars($contractorData['pan'] ?? ''),
                    '{{contractor_gst}}' => htmlspecialchars($contractorData['gst'] ?? ''),
                    '{{contractor_mobile}}' => htmlspecialchars($contractorData['mobile'] ?? ''),
                    '{{current_date}}' => date('d-m-Y'),
                ];

                $generatedHtml = str_replace(array_keys($replacements), array_values($replacements), $templateContent);
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
    <title>Create Document</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="dashboard-shell">
        <section class="dashboard-card">
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Generate Document</h1>
                    <p class="muted">Department: <?php echo htmlspecialchars($deptId); ?></p>
                </div>
                <div class="actions">
                    <a href="dashboard.php" class="btn-secondary button-as-link">Back</a>
                </div>
            </div>

            <div class="panel">
                <h3>Select Template &amp; Contractor</h3>
                <?php if ($errorMessage): ?>
                    <div class="status error"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>
                <form method="post" class="inline-form" autocomplete="off">
                    <div class="form-group">
                        <label for="template_id">Template</label>
                        <select id="template_id" name="template_id" required>
                            <option value="" disabled <?php echo $selectedTemplateId === '' ? 'selected' : ''; ?>>Select template</option>
                            <?php foreach ($templates as $template): ?>
                                <option value="<?php echo htmlspecialchars($template['id']); ?>" <?php echo $selectedTemplateId === ($template['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($template['title'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="contractor_id">Contractor</label>
                        <select id="contractor_id" name="contractor_id" required>
                            <option value="" disabled <?php echo $selectedContractorId === '' ? 'selected' : ''; ?>>Select contractor</option>
                            <?php foreach ($contractors as $contractor): ?>
                                <option value="<?php echo htmlspecialchars($contractor['id']); ?>" <?php echo $selectedContractorId === ($contractor['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($contractor['name'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit">Generate</button>
                </form>
            </div>

            <?php if ($generatedHtml): ?>
                <div class="panel">
                    <div class="actions no-print" style="justify-content: flex-end; gap: 8px;">
                        <button type="button" onclick="window.print()">Print</button>
                        <button type="button" class="btn-secondary">Save as Draft</button>
                    </div>
                    <div class="page">
                        <?php echo $generatedHtml; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>

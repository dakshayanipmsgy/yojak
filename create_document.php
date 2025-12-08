<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['role_id'], $_SESSION['dept_id'])) {
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
$departmentTemplates = read_json($templatesIndexPath);
if (!is_array($departmentTemplates)) {
    $departmentTemplates = [];
}

$systemTemplatesDir = __DIR__ . '/storage/system/templates';
$systemTemplatesIndexPath = $systemTemplatesDir . '/templates.json';
$universalTemplates = read_json($systemTemplatesIndexPath);
if (!is_array($universalTemplates)) {
    $universalTemplates = [];
}

$allTemplates = [];
foreach ($universalTemplates as $template) {
    if (!is_array($template)) {
        continue;
    }
    $template['scope'] = 'universal';
    $template['path'] = $systemTemplatesDir . '/' . ($template['filename'] ?? '');
    $allTemplates[] = $template;
}

foreach ($departmentTemplates as $template) {
    if (!is_array($template)) {
        continue;
    }
    $template['scope'] = 'department';
    $template['path'] = $templatesDir . '/' . ($template['filename'] ?? '');
    $template['dept_id'] = $deptId;
    $allTemplates[] = $template;
}

$selectedContractorId = $_POST['contractor_id'] ?? '';
$selectedTemplateKey = $_POST['template_id'] ?? '';
$selectedTemplateParts = array_pad(explode('|', $selectedTemplateKey, 2), 2, '');
$selectedTemplateScope = $selectedTemplateParts[0];
$selectedTemplateId = $selectedTemplateParts[1];
$generatedHtml = null;
$renderedHtml = null;
$errorMessage = null;
$successMessage = null;
$documentTitle = '';
$deptUsers = array_values(array_filter(getDepartmentUsers($deptId), function (array $user): bool {
    return ($user['status'] ?? 'active') === 'active';
}));
$selectionDisabled = empty($contractors) || empty($allTemplates);
$includeHeader = isset($_POST['include_header']);

$headerHtml = '<div class="official-header">'
    . '<div class="header-left">'
    . '<div class="logo-block">LOGO</div>'
    . '</div>'
    . '<div class="header-center">'
    . '<div class="gov-name">Government of India</div>'
    . '<div class="dept-name">' . htmlspecialchars($meta['name'] ?? $deptId) . '</div>'
    . '</div>'
    . '<div class="header-right">'
    . '<div class="address-line">Official Correspondence</div>'
    . '<div class="address-line">' . htmlspecialchars($deptId) . '</div>'
    . '</div>'
    . '</div><hr class="header-divider">';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['dak_prefill'])) {
    $prefill = $_SESSION['dak_prefill'];
    $documentTitle = $prefill['title'] ?? 'Dak Draft';
    $prefillBody = $prefill['body'] ?? '';
    $generatedHtml = '<div class="dak-prefill"><pre>' . htmlspecialchars($prefillBody) . '</pre></div>';
    unset($_SESSION['dak_prefill']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'generate';

    if ($action === 'save' || $action === 'send') {
        $documentTitle = trim($_POST['title'] ?? '');
        $generatedHtml = $_POST['content'] ?? '';
        $templateTitle = $_POST['template_title'] ?? '';

        if ($documentTitle === '') {
            $documentTitle = $templateTitle ?: 'Untitled Document';
        }

        if ($generatedHtml === '') {
            $errorMessage = 'Document content is missing. Please regenerate the document.';
        } else {
            $docId = generate_document_id($deptPath);
            $contentToStore = $includeHeader ? $headerHtml . $generatedHtml : $generatedHtml;
            $documentData = [
                'id' => $docId,
                'title' => $documentTitle,
                'content' => $contentToStore,
                'created_by' => $_SESSION['user_id'],
                'created_at' => date('c'),
                'current_owner' => $_SESSION['user_id'],
                'status' => 'draft',
                'history' => [],
                'note_sheet' => [],
            ];

            $documentPath = $deptPath . '/documents/' . $docId . '.json';
            if (!write_json($documentPath, $documentData)) {
                $errorMessage = 'Failed to save document file. Please try again.';
            } else {
                if ($action === 'send') {
                    $targetUserId = $_POST['target_user_id'] ?? '';
                    if ($targetUserId === '') {
                        $errorMessage = 'Please select a user to send the document to.';
                    } else {
                        $moveResult = moveDocument($deptId, $docId, $targetUserId, $_SESSION['user_id'], 'pending', null);
                        if ($moveResult['success']) {
                            $successMessage = 'Document sent successfully (ID: ' . htmlspecialchars($docId) . ').';
                        } else {
                            $errorMessage = $moveResult['message'];
                        }
                    }
                } else {
                    $successMessage = 'Document saved as draft (ID: ' . htmlspecialchars($docId) . ').';
                }
            }
        }
    } else {
        $templateMeta = null;
        foreach ($allTemplates as $template) {
            if (($template['id'] ?? '') === $selectedTemplateId && ($template['scope'] ?? '') === $selectedTemplateScope) {
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
            $templateFile = $templateMeta['path'] ?? '';
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
                    $documentTitle = $templateMeta['title'] ?? 'Generated Document';
                }
            }
        }
    }
}
if ($generatedHtml) {
    $renderedHtml = $includeHeader ? $headerHtml . $generatedHtml : $generatedHtml;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Document</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="print.css">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>
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
                <?php if ($successMessage): ?>
                    <div class="status success"><?php echo htmlspecialchars($successMessage); ?></div>
                <?php endif; ?>
                <?php if (empty($allTemplates) || empty($contractors)): ?>
                    <div class="status error">Please ensure at least one template (global or departmental) and one contractor exist for this department.</div>
                <?php endif; ?>
                <form method="post" class="inline-form" autocomplete="off">
                    <input type="hidden" name="action" value="generate">
                    <div class="form-group">
                        <label for="template_id">Template</label>
                        <select id="template_id" name="template_id" required <?php echo $selectionDisabled ? 'disabled' : ''; ?>>
                            <option value="" disabled <?php echo $selectedTemplateId === '' ? 'selected' : ''; ?>>Select template</option>
                            <?php foreach ($allTemplates as $template): ?>
                                <?php
                                    $optionValue = ($template['scope'] ?? '') . '|' . ($template['id'] ?? '');
                                    $label = $template['title'] ?? '';
                                    if (($template['scope'] ?? '') === 'universal') {
                                        $label = '[Global] ' . $label;
                                    }
                                ?>
                                <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo $selectedTemplateKey === $optionValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="contractor_id">Contractor</label>
                        <select id="contractor_id" name="contractor_id" required <?php echo $selectionDisabled ? 'disabled' : ''; ?>>
                            <option value="" disabled <?php echo $selectedContractorId === '' ? 'selected' : ''; ?>>Select contractor</option>
                            <?php foreach ($contractors as $contractor): ?>
                                <option value="<?php echo htmlspecialchars($contractor['id']); ?>" <?php echo $selectedContractorId === ($contractor['id'] ?? '') ? 'selected' : ''; ?>><?php echo htmlspecialchars($contractor['name'] ?? ''); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" <?php echo $selectionDisabled ? 'disabled' : ''; ?>>Generate</button>
                </form>
            </div>

            <?php if ($generatedHtml): ?>
                <div class="panel">
                    <form method="post" class="inline-form" autocomplete="off">
                        <input type="hidden" name="template_id" value="<?php echo htmlspecialchars($selectedTemplateId); ?>">
                        <input type="hidden" name="contractor_id" value="<?php echo htmlspecialchars($selectedContractorId); ?>">
                        <input type="hidden" name="template_title" value="<?php echo htmlspecialchars($documentTitle); ?>">
                        <textarea name="content" style="display: none;"><?php echo htmlspecialchars($generatedHtml); ?></textarea>
                        <div class="form-group" style="width: 100%;">
                            <label for="title">Document Title</label>
                            <input id="title" name="title" type="text" value="<?php echo htmlspecialchars($documentTitle); ?>" required>
                        </div>
                        <div class="form-group" style="width: 100%;">
                            <label><input type="checkbox" name="include_header" <?php echo $includeHeader ? 'checked' : ''; ?>> Include Official Header?</label>
                            <small class="muted">Adds the standard government header to the top of the print view.</small>
                        </div>
                        <div class="form-group" style="width: 100%;">
                            <label for="target_user_id">Forward To</label>
                            <select id="target_user_id" name="target_user_id">
                                <option value="">Select user</option>
                                <?php foreach ($deptUsers as $user): ?>
                                    <?php if (($user['id'] ?? '') === ($_SESSION['user_id'] ?? '')) { continue; } ?>
                                    <option value="<?php echo htmlspecialchars($user['id']); ?>"><?php echo htmlspecialchars(($user['name'] ?? $user['id']) . ' (' . ($user['id'] ?? '') . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="muted">Select a user when forwarding the document.</small>
                        </div>
                        <div class="actions no-print" style="justify-content: flex-end; gap: 8px; flex-wrap: wrap;">
                            <button type="button" onclick="window.print()">Print</button>
                            <button type="submit" name="action" value="save" class="btn-secondary">Save as Draft</button>
                            <button type="submit" name="action" value="send">Send</button>
                        </div>
                    </form>
                    <div class="page page-a4">
                        <?php echo $renderedHtml ?? $generatedHtml; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>

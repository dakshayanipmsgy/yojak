<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['dept_id'], $_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$deptId = $_SESSION['dept_id'];
$docId = $_POST['doc_id'] ?? '';
$action = $_POST['action'] ?? '';

if ($docId === '' || $action === '') {
    header('Location: view_document.php?id=' . urlencode($docId) . '&status=error&message=' . urlencode('Invalid request.'));
    exit;
}

$documentPath = __DIR__ . '/storage/departments/' . $deptId . '/documents/' . $docId . '.json';
$document = read_json($documentPath);

if (!is_array($document)) {
    header('Location: view_document.php?id=' . urlencode($docId) . '&status=error&message=' . urlencode('Document not found.'));
    exit;
}

$isCurrentOwner = ($document['current_owner'] ?? '') === ($_SESSION['user_id'] ?? '');
if (!$isCurrentOwner) {
    header('Location: view_document.php?id=' . urlencode($docId) . '&status=error&message=' . urlencode('Only the current owner can manage attachments.'));
    exit;
}

$allowedExtensions = ['pdf', 'jpg', 'png', 'jpeg', 'docx', 'xlsx'];
$blockedExtensions = ['php', 'exe', 'sh'];

if ($action === 'upload_attachment') {
    if (!isset($_FILES['attachments'])) {
        header('Location: view_document.php?id=' . urlencode($docId) . '&status=error&message=' . urlencode('No files were provided.'));
        exit;
    }

    $uploadDir = __DIR__ . '/storage/departments/' . $deptId . '/uploads/' . $docId . '/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            header('Location: view_document.php?id=' . urlencode($docId) . '&status=error&message=' . urlencode('Unable to prepare upload folder.'));
            exit;
        }
    }

    $attachments = isset($document['attachments']) && is_array($document['attachments']) ? $document['attachments'] : [];
    $successfulUploads = 0;

    foreach ($_FILES['attachments']['name'] as $index => $originalName) {
        $error = $_FILES['attachments']['error'][$index];
        if ($error !== UPLOAD_ERR_OK) {
            continue;
        }

        $tmpPath = $_FILES['attachments']['tmp_name'][$index];
        $cleanName = basename($originalName);
        $extension = strtolower(pathinfo($cleanName, PATHINFO_EXTENSION));

        if (in_array($extension, $blockedExtensions, true)) {
            header('Location: view_document.php?id=' . urlencode($docId) . '&status=error&message=' . urlencode('File type not allowed.'));
            exit;
        }

        if (!in_array($extension, $allowedExtensions, true)) {
            header('Location: view_document.php?id=' . urlencode($docId) . '&status=error&message=' . urlencode('File extension not permitted.'));
            exit;
        }

        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $cleanName);
        $timestamp = (int) (microtime(true) * 1000);
        $newName = $timestamp . '_' . $safeName;
        $destination = $uploadDir . $newName;

        if (!move_uploaded_file($tmpPath, $destination)) {
            continue;
        }

        $relativePath = 'storage/departments/' . $deptId . '/uploads/' . $docId . '/' . $newName;
        $attachments[] = [
            'filename' => $newName,
            'path' => $relativePath,
            'uploaded_by' => $_SESSION['user_id'],
        ];
        $successfulUploads++;
    }

    $document['attachments'] = $attachments;

    if ($successfulUploads > 0 && write_json($documentPath, $document)) {
        $message = $successfulUploads . ' file(s) uploaded successfully.';
        header('Location: view_document.php?id=' . urlencode($docId) . '&status=success&message=' . urlencode($message));
    } else {
        header('Location: view_document.php?id=' . urlencode($docId) . '&status=error&message=' . urlencode('No files were uploaded.'));
    }
    exit;
}

if ($action === 'delete_attachment') {
    $attachmentPath = $_POST['attachment_path'] ?? '';
    $expectedPrefix = 'storage/departments/' . $deptId . '/uploads/' . $docId . '/';
    if (strpos($attachmentPath, $expectedPrefix) !== 0) {
        header('Location: view_document.php?id=' . urlencode($docId) . '&status=error&message=' . urlencode('Invalid attachment path.'));
        exit;
    }

    $attachments = isset($document['attachments']) && is_array($document['attachments']) ? $document['attachments'] : [];
    $updatedAttachments = [];
    $deleted = false;

    foreach ($attachments as $attachment) {
        if (($attachment['path'] ?? '') === $attachmentPath) {
            $fullPath = __DIR__ . '/' . $attachmentPath;
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
            $deleted = true;
            continue;
        }
        $updatedAttachments[] = $attachment;
    }

    $document['attachments'] = $updatedAttachments;

    if ($deleted && write_json($documentPath, $document)) {
        header('Location: view_document.php?id=' . urlencode($docId) . '&status=success&message=' . urlencode('Attachment deleted.'));
    } else {
        header('Location: view_document.php?id=' . urlencode($docId) . '&status=error&message=' . urlencode('Unable to delete attachment.'));
    }
    exit;
}

header('Location: view_document.php?id=' . urlencode($docId) . '&status=error&message=' . urlencode('Unknown action.'));
exit;

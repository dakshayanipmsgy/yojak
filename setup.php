<?php
// Basic system readiness check for file-based storage.

$storagePath = __DIR__ . '/storage';
$configPath = $storagePath . '/system/global_config.json';

$storageExists = is_dir($storagePath);
$storageWritable = false;
$configExists = file_exists($configPath);
$testFile = $storagePath . '/.__write_test_' . uniqid('', true);

if ($storageExists) {
    $writeResult = @file_put_contents($testFile, 'ok');
    if ($writeResult !== false) {
        $storageWritable = true;
        @unlink($testFile);
    }
}

$statusReady = $storageExists && $storageWritable && $configExists;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Setup Check</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f6; padding: 40px; color: #222; }
        .card { background: #fff; padding: 24px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); max-width: 600px; margin: 0 auto; }
        h1 { margin-top: 0; }
        .status { padding: 12px; border-radius: 6px; margin-bottom: 12px; }
        .ok { background: #e6f4ea; border: 1px solid #a3d7b5; color: #23643c; }
        .error { background: #fce8e6; border: 1px solid #f5a6a1; color: #b3261e; }
        ul { padding-left: 20px; }
    </style>
</head>
<body>
<div class="card">
    <h1>System Health Check</h1>
    <ul>
        <li>storage/ exists: <?php echo $storageExists ? 'Yes' : 'No'; ?></li>
        <li>storage/ writable: <?php echo $storageWritable ? 'Yes' : 'No'; ?></li>
        <li>global_config.json present: <?php echo $configExists ? 'Yes' : 'No'; ?></li>
    </ul>

    <?php if ($statusReady): ?>
        <div class="status ok">System Status: Ready. Storage is Writable.</div>
    <?php else: ?>
        <div class="status error">Error: PHP cannot write to the storage folder. Please set Linux permissions to 775 or 777.</div>
    <?php endif; ?>
</div>
</body>
</html>

<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['dept_id'], $_SESSION['role_id'])) {
    header('Location: index.php');
    exit;
}

$deptId = $_SESSION['dept_id'];
$roleId = $_SESSION['role_id'];

if (!checkPermission('admin.' . $deptId)) {
    header('Location: dashboard.php');
    exit;
}

$deptPath = __DIR__ . '/storage/departments/' . $deptId;
$contractorsPath = $deptPath . '/data/contractors.json';

$successMessages = [];
$errorMessages = [];
$activeTab = $_GET['tab'] ?? 'users';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uploadType = $_POST['upload_type'] ?? 'users';
    $activeTab = $uploadType;

    if (!isset($_FILES['upload_file']) || !is_uploaded_file($_FILES['upload_file']['tmp_name'])) {
        $errorMessages[] = 'Please upload a valid CSV file.';
    } else {
        $file = fopen($_FILES['upload_file']['tmp_name'], 'r');
        if ($file === false) {
            $errorMessages[] = 'Unable to open uploaded file.';
        } else {
            if ($uploadType === 'users') {
                $rowNumber = 0;
                while (($row = fgetcsv($file)) !== false) {
                    $rowNumber++;
                    if (count($row) < 4) {
                        $errorMessages[] = "Row {$rowNumber}: Not enough columns. Expected First Name, Last Name, Role, Password, Custom_ID (optional).";
                        continue;
                    }

                    [$firstName, $lastName, $role, $password] = array_map('trim', array_slice($row, 0, 4));
                    $customId = trim($row[4] ?? '');

                    if ($rowNumber === 1 && strcasecmp($firstName, 'First Name') === 0) {
                        // Skip header row
                        continue;
                    }

                    $result = createUser($deptId, $firstName, $lastName, $password, $role, $customId);
                    if ($result['success']) {
                        $successMessages[] = "Row {$rowNumber}: User created with ID " . ($result['user_id'] ?? '');
                    } else {
                        $errorMessages[] = "Row {$rowNumber}: " . $result['message'];
                    }
                }
                fclose($file);
            } elseif ($uploadType === 'contractors') {
                $contractors = read_json($contractorsPath);
                if (!is_array($contractors)) {
                    $contractors = [];
                }

                $rowNumber = 0;
                while (($row = fgetcsv($file)) !== false) {
                    $rowNumber++;
                    if (count($row) < 5) {
                        $errorMessages[] = "Row {$rowNumber}: Not enough columns. Expected Name, Address, Pan, GST, Mobile.";
                        continue;
                    }

                    if ($rowNumber === 1 && strcasecmp($row[0], 'Name') === 0) {
                        continue;
                    }

                    [$name, $address, $pan, $gst, $mobile] = array_map('trim', array_slice($row, 0, 5));
                    $contractors[] = [
                        'id' => generate_id(),
                        'name' => $name,
                        'address' => $address,
                        'pan' => $pan,
                        'gst' => $gst,
                        'mobile' => $mobile,
                    ];
                    $successMessages[] = "Row {$rowNumber}: Contractor imported.";
                }
                fclose($file);

                if (!write_json($contractorsPath, $contractors)) {
                    $errorMessages[] = 'Unable to save contractor records.';
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
    <title>Bulk Import</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include __DIR__ . '/navbar.php'; ?>
    <main class="dashboard-shell">
        <section class="dashboard-card">
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Bulk Import</h1>
                    <p class="muted">Upload CSV files to quickly add users or contractors.</p>
                </div>
                <div class="actions" style="gap: 8px;">
                    <a class="btn-secondary button-as-link" href="create_user.php">Create Single User</a>
                    <a class="btn-secondary button-as-link" href="dashboard.php#manage-users">Back</a>
                </div>
            </div>

            <?php foreach ($errorMessages as $err): ?>
                <div class="status error"><?php echo htmlspecialchars($err); ?></div>
            <?php endforeach; ?>
            <?php foreach ($successMessages as $msg): ?>
                <div class="status success"><?php echo htmlspecialchars($msg); ?></div>
            <?php endforeach; ?>

            <div class="panel">
                <div class="tab-bar">
                    <a class="tab-link <?php echo $activeTab === 'users' ? 'active' : ''; ?>" href="?tab=users">Import Users</a>
                    <a class="tab-link <?php echo $activeTab === 'contractors' ? 'active' : ''; ?>" href="?tab=contractors">Import Contractors</a>
                </div>

                <?php if ($activeTab === 'contractors'): ?>
                    <form method="post" enctype="multipart/form-data" autocomplete="off">
                        <input type="hidden" name="upload_type" value="contractors">
                        <div class="form-group">
                            <label for="contractor_csv">Contractor CSV</label>
                            <input id="contractor_csv" type="file" name="upload_file" accept=".csv" required>
                            <p class="muted">Columns: Name, Address, Pan, GST, Mobile.</p>
                        </div>
                        <button type="submit">Upload Contractors</button>
                    </form>
                <?php else: ?>
                    <form method="post" enctype="multipart/form-data" autocomplete="off">
                        <input type="hidden" name="upload_type" value="users">
                        <div class="form-group">
                            <label for="user_csv">User CSV</label>
                            <input id="user_csv" type="file" name="upload_file" accept=".csv" required>
                            <p class="muted">Columns: First Name, Last Name, Role, Password, Custom_ID (optional).</p>
                        </div>
                        <button type="submit">Upload Users</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </main>
</body>
</html>

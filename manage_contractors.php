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

$contractorsPath = __DIR__ . '/storage/departments/' . $deptId . '/data/contractors.json';
$contractors = read_json($contractorsPath);
if (!is_array($contractors)) {
    $contractors = [];
}

$editingId = $_GET['edit'] ?? null;
$editingContractor = null;
$successMessage = null;
$errorMessage = null;

foreach ($contractors as $contractor) {
    if (($contractor['id'] ?? null) === $editingId) {
        $editingContractor = $contractor;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name = trim($_POST['name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $pan = trim($_POST['pan'] ?? '');
        $gst = trim($_POST['gst'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');

        if ($name === '' || $address === '') {
            $errorMessage = 'Name and Address are required.';
        } else {
            if ($action === 'create') {
                $contractors[] = [
                    'id' => generate_id(),
                    'name' => $name,
                    'address' => $address,
                    'pan' => $pan,
                    'gst' => $gst,
                    'mobile' => $mobile,
                ];
                $successMessage = 'Contractor added successfully.';
            } else {
                $id = $_POST['id'] ?? '';
                $updated = false;
                foreach ($contractors as &$contractor) {
                    if (($contractor['id'] ?? '') === $id) {
                        $contractor['name'] = $name;
                        $contractor['address'] = $address;
                        $contractor['pan'] = $pan;
                        $contractor['gst'] = $gst;
                        $contractor['mobile'] = $mobile;
                        $updated = true;
                        break;
                    }
                }
                unset($contractor);

                if ($updated) {
                    $successMessage = 'Contractor updated successfully.';
                    $editingContractor = null;
                } else {
                    $errorMessage = 'Contractor not found.';
                }
            }

            if (!$errorMessage && !write_json($contractorsPath, $contractors)) {
                $errorMessage = 'Unable to save contractor records.';
            }
        }
    } elseif ($action === 'delete') {
        $id = $_POST['id'] ?? '';
        $beforeCount = count($contractors);
        $contractors = array_values(array_filter($contractors, fn($c) => ($c['id'] ?? '') !== $id));
        if ($beforeCount === count($contractors)) {
            $errorMessage = 'Contractor not found.';
        } else {
            if (write_json($contractorsPath, $contractors)) {
                $successMessage = 'Contractor removed.';
            } else {
                $errorMessage = 'Unable to save changes.';
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
    <title>Manage Contractors</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="dashboard-shell">
        <section class="dashboard-card">
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Contractor Address Book</h1>
                    <p class="muted">Department: <?php echo htmlspecialchars($deptId); ?></p>
                </div>
                <div class="actions">
                    <a href="dashboard.php" class="btn-secondary button-as-link">Back</a>
                </div>
            </div>

            <?php if ($errorMessage): ?>
                <div class="status error"><?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>
            <?php if ($successMessage): ?>
                <div class="status success"><?php echo htmlspecialchars($successMessage); ?></div>
            <?php endif; ?>

            <div class="panel">
                <h3><?php echo $editingContractor ? 'Edit Contractor' : 'Add Contractor'; ?></h3>
                <form method="post" class="inline-form" autocomplete="off">
                    <input type="hidden" name="action" value="<?php echo $editingContractor ? 'update' : 'create'; ?>">
                    <?php if ($editingContractor): ?>
                        <input type="hidden" name="id" value="<?php echo htmlspecialchars($editingContractor['id']); ?>">
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="name">Name</label>
                        <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($editingContractor['name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="address">Address</label>
                        <input id="address" name="address" type="text" value="<?php echo htmlspecialchars($editingContractor['address'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="pan">PAN Number</label>
                        <input id="pan" name="pan" type="text" value="<?php echo htmlspecialchars($editingContractor['pan'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="gst">GST Number</label>
                        <input id="gst" name="gst" type="text" value="<?php echo htmlspecialchars($editingContractor['gst'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="mobile">Mobile</label>
                        <input id="mobile" name="mobile" type="text" value="<?php echo htmlspecialchars($editingContractor['mobile'] ?? ''); ?>">
                    </div>
                    <button type="submit"><?php echo $editingContractor ? 'Update Contractor' : 'Add Contractor'; ?></button>
                </form>
            </div>

            <div class="panel">
                <h3>Saved Contractors</h3>
                <?php if (empty($contractors)): ?>
                    <p class="muted">No contractor records yet.</p>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Address</th>
                                <th>PAN</th>
                                <th>GST</th>
                                <th>Mobile</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contractors as $contractor): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($contractor['name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($contractor['address'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($contractor['pan'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($contractor['gst'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($contractor['mobile'] ?? ''); ?></td>
                                    <td>
                                        <a class="button-as-link" href="?edit=<?php echo urlencode($contractor['id']); ?>">Edit</a>
                                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete this contractor?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo htmlspecialchars($contractor['id']); ?>">
                                            <button type="submit" class="btn-secondary">Delete</button>
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

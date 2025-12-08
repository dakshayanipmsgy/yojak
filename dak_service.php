<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['role_id'], $_SESSION['dept_id'])) {
    header('Location: index.php');
    exit;
}

$deptId = $_SESSION['dept_id'];
$roleId = $_SESSION['role_id'];
$hasDakAccess = checkPermission('admin.' . $deptId) || strpos($roleId ?? '', 'clerk') !== false;

if (!$hasDakAccess) {
    header('Location: dashboard.php');
    exit;
}

$deptPath = __DIR__ . '/storage/departments/' . $deptId;
$dataDir = $deptPath . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

$dakPath = $dataDir . '/dak_register.json';
$dakEntries = read_json($dakPath);
if (!is_array($dakEntries)) {
    $dakEntries = [];
}

$deptUsers = array_values(array_filter(ensure_status(getDepartmentUsers($deptId)), function (array $user): bool {
    return ($user['status'] ?? 'active') === 'active';
}));

$statusError = null;
$statusMessage = null;

function next_dak_reference(array $entries, string $direction): string
{
    $year = date('Y');
    $prefix = $direction === 'outgoing' ? 'DAK/OUT/' : 'DAK/IN/';
    $maxCounter = 0;

    foreach ($entries as $entry) {
        if (($entry['direction'] ?? '') !== $direction) {
            continue;
        }
        if (!isset($entry['reference_no']) || !is_string($entry['reference_no'])) {
            continue;
        }
        if (strpos($entry['reference_no'], $prefix . $year) !== 0) {
            continue;
        }
        $parts = explode('/', $entry['reference_no']);
        $counterPart = $parts[count($parts) - 1] ?? '0';
        $counter = (int) $counterPart;
        if ($counter > $maxCounter) {
            $maxCounter = $counter;
        }
    }

    $next = $maxCounter + 1;
    return $prefix . $year . '/' . str_pad((string) $next, 3, '0', STR_PAD_LEFT);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $direction = $_POST['direction'] ?? 'incoming';
    $sender = trim($_POST['sender'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $receivedDate = $_POST['received_date'] ?? '';
    $physicalMode = trim($_POST['physical_mode'] ?? '');
    $assignedTo = $_POST['assigned_to'] ?? '';
    $convert = isset($_POST['convert_to_digital']);

    if ($sender === '' || $subject === '' || $receivedDate === '' || $physicalMode === '' || $assignedTo === '') {
        $statusError = 'All fields are required to register Dak.';
    } else {
        $referenceNo = next_dak_reference($dakEntries, $direction);
        $entry = [
            'reference_no' => $referenceNo,
            'direction' => $direction,
            'sender' => $sender,
            'subject' => $subject,
            'received_date' => $receivedDate,
            'physical_mode' => $physicalMode,
            'assigned_to' => $assignedTo,
            'created_at' => date('c'),
            'created_by' => $_SESSION['user_id'],
        ];

        $dakEntries[] = $entry;
        if (write_json($dakPath, $dakEntries)) {
            $statusMessage = 'Dak entry saved with reference ' . htmlspecialchars($referenceNo) . '.';
            if ($convert) {
                $_SESSION['dak_prefill'] = [
                    'title' => $subject,
                    'body' => "Dak Reference: $referenceNo\nSender: $sender\nMode: $physicalMode\nReceived: $receivedDate",
                ];
                header('Location: create_document.php');
                exit;
            }
        } else {
            $statusError = 'Unable to save Dak entry.';
        }
    }
}

$incomingEntries = array_filter($dakEntries, function (array $entry): bool {
    return ($entry['direction'] ?? '') === 'incoming';
});
$outgoingEntries = array_filter($dakEntries, function (array $entry): bool {
    return ($entry['direction'] ?? '') === 'outgoing';
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dak Register</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<main class="dashboard-shell">
    <section class="dashboard-card">
        <div class="dashboard-header">
            <div>
                <h1 class="dashboard-title">Dak Register</h1>
                <p class="muted">Track incoming and outgoing physical Dak.</p>
            </div>
            <div class="actions">
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
            <h3>New Dak Entry</h3>
            <form class="inline-form" method="post" autocomplete="off">
                <div class="form-group">
                    <label for="direction">Type</label>
                    <select id="direction" name="direction" required>
                        <option value="incoming">Incoming</option>
                        <option value="outgoing">Outgoing</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="sender">Sender / Ministry</label>
                    <input id="sender" name="sender" type="text" required placeholder="Sender name">
                </div>
                <div class="form-group">
                    <label for="subject">Subject / File Name</label>
                    <input id="subject" name="subject" type="text" required placeholder="Subject">
                </div>
                <div class="form-group">
                    <label for="received_date">Received Date</label>
                    <input id="received_date" name="received_date" type="date" required>
                </div>
                <div class="form-group">
                    <label for="physical_mode">Physical Mode</label>
                    <select id="physical_mode" name="physical_mode" required>
                        <option value="" disabled selected>Select mode</option>
                        <option value="Speed Post">Speed Post</option>
                        <option value="Courier">Courier</option>
                        <option value="Peon Book">Peon Book</option>
                        <option value="Hand">Hand</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="assigned_to">Assigned To</label>
                    <select id="assigned_to" name="assigned_to" required>
                        <option value="" disabled selected>Select user</option>
                        <?php foreach ($deptUsers as $user): ?>
                            <option value="<?php echo htmlspecialchars($user['id']); ?>"><?php echo htmlspecialchars($user['name'] ?? $user['id']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label><input type="checkbox" name="convert_to_digital"> Convert to Digital File</label>
                    <p class="muted">Copies this entry into a new digital document draft.</p>
                </div>
                <button type="submit">Save Entry</button>
            </form>
        </div>

        <div class="panel">
            <h3>Incoming Dak</h3>
            <?php if (empty($incomingEntries)): ?>
                <p class="muted">No incoming Dak recorded.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Sender</th>
                            <th>Subject</th>
                            <th>Received</th>
                            <th>Mode</th>
                            <th>Assigned</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($incomingEntries as $entry): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($entry['reference_no'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($entry['sender'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($entry['subject'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($entry['received_date'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($entry['physical_mode'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($entry['assigned_to'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h3>Outgoing Dak</h3>
            <?php if (empty($outgoingEntries)): ?>
                <p class="muted">No outgoing Dak recorded.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Sender</th>
                            <th>Subject</th>
                            <th>Sent Date</th>
                            <th>Mode</th>
                            <th>Assigned</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($outgoingEntries as $entry): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($entry['reference_no'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($entry['sender'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($entry['subject'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($entry['received_date'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($entry['physical_mode'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($entry['assigned_to'] ?? ''); ?></td>
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

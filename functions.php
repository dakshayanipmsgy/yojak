<?php
/**
 * Utility functions for file-based storage operations.
 */

if (!function_exists('read_json')) {
    /**
     * Read JSON data from a file path.
     *
     * @param string $path
     * @return array|null
     */
    function read_json(string $path): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }
}

if (!function_exists('write_json')) {
    /**
     * Write JSON data to a file path using an exclusive lock.
     *
     * @param string $path
     * @param array $data
     * @return bool
     */
    function write_json(string $path, array $data): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($path, $json, LOCK_EX) !== false;
    }
}

if (!function_exists('generate_id')) {
    /**
     * Generate a unique identifier for future entities.
     *
     * @return string
     */
    function generate_id(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (Exception $e) {
            return uniqid('', true);
        }
    }
}

if (!function_exists('generate_user_identifier')) {
    /**
     * Generate a user identifier suggestion from the given names.
     */
    function generate_user_identifier(string $firstName, string $lastName): string
    {
        $firstInitial = strtolower(substr(trim($firstName), 0, 1));
        $lastPortion = strtolower(preg_replace('/[^a-z0-9]/', '', trim($lastName)));

        $candidate = $firstInitial . $lastPortion;
        if ($candidate === '') {
            return 'user_' . strtolower(bin2hex(random_bytes(3)));
        }

        return $candidate;
    }
}

if (!function_exists('getDepartmentUsers')) {
    /**
     * Retrieve all users for a given department.
     *
     * @param string $deptId
     * @return array<int, array>
     */
    function getDepartmentUsers(string $deptId): array
    {
        $usersPath = __DIR__ . '/storage/departments/' . $deptId . '/users/users.json';
        $users = read_json($usersPath);
        if (!is_array($users)) {
            return [];
        }

        return array_map(function (array $user): array {
            if (!array_key_exists('status', $user)) {
                $user['status'] = 'active';
            }
            return $user;
        }, $users);
    }
}

if (!function_exists('ensure_status')) {
    /**
     * Guarantee that each associative array contains a status key.
     *
     * @param array<int, array> $items
     * @param string $default
     * @return array<int, array>
     */
    function ensure_status(array $items, string $default = 'active'): array
    {
        return array_map(function (array $item) use ($default) {
            if (!array_key_exists('status', $item)) {
                $item['status'] = $default;
            }
            return $item;
        }, $items);
    }
}

if (!function_exists('generate_document_id')) {
    /**
     * Generate a unique document identifier for the department store.
     */
    function generate_document_id(string $deptPath): string
    {
        $documentsDir = rtrim($deptPath, '/');
        $documentsDir .= '/documents';

        if (!is_dir($documentsDir)) {
            mkdir($documentsDir, 0755, true);
        }

        $year = date('Y');
        $maxCounter = 0;

        foreach (scandir($documentsDir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!preg_match('/^DOC_' . $year . '_(\d{4})\.json$/', $entry, $matches)) {
                continue;
            }

            $counter = (int) $matches[1];
            if ($counter > $maxCounter) {
                $maxCounter = $counter;
            }
        }

        $nextCounter = $maxCounter + 1;
        $candidate = 'DOC_' . $year . '_' . str_pad((string) $nextCounter, 4, '0', STR_PAD_LEFT);

        while (file_exists($documentsDir . '/' . $candidate . '.json')) {
            $nextCounter++;
            $candidate = 'DOC_' . $year . '_' . str_pad((string) $nextCounter, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
    }
}

if (!function_exists('append_master_log')) {
    /**
     * Append a line to the immutable master log for the department.
     */
    function append_master_log(string $deptPath, string $line): void
    {
        $logDir = rtrim($deptPath, '/') . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logPath = $logDir . '/master_log.txt';
        $timestamp = date('c');
        $entry = '[' . $timestamp . '] ' . $line . PHP_EOL;
        file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('load_requests')) {
    /**
     * Load governance requests from central storage.
     *
     * @return array<int, array>
     */
    function load_requests(): array
    {
        $path = __DIR__ . '/storage/system/requests.json';
        $requests = read_json($path);
        if (!is_array($requests)) {
            return [];
        }

        return array_map(function (array $request): array {
            if (!array_key_exists('status', $request)) {
                $request['status'] = 'pending';
            }
            return $request;
        }, $requests);
    }
}

if (!function_exists('save_requests')) {
    /**
     * Persist governance requests.
     */
    function save_requests(array $requests): bool
    {
        $path = __DIR__ . '/storage/system/requests.json';
        return write_json($path, $requests);
    }
}

if (!function_exists('update_entity_status')) {
    /**
     * Apply a status update to a department entity.
     *
     * @param string $deptId
     * @param string $targetType user|role|department
     * @param string $targetId
     * @param string $newStatus
     * @return array{success: bool, message: string}
     */
    function update_entity_status(string $deptId, string $targetType, string $targetId, string $newStatus): array
    {
        $deptPath = __DIR__ . '/storage/departments/' . $deptId;

        if ($targetType === 'department') {
            $metaPath = $deptPath . '/department.json';
            $meta = read_json($metaPath) ?? ['id' => $deptId];
            $meta['status'] = $newStatus;
            return ['success' => write_json($metaPath, $meta), 'message' => 'Department status updated.'];
        }

        $filePath = $targetType === 'user'
            ? $deptPath . '/users/users.json'
            : $deptPath . '/roles/roles.json';

        $items = read_json($filePath);
        if (!is_array($items)) {
            return ['success' => false, 'message' => 'Unable to load target store.'];
        }

        $updated = false;
        foreach ($items as &$item) {
            if (($item['id'] ?? '') === $targetId) {
                $item['status'] = $newStatus;
                $updated = true;
                break;
            }
        }
        unset($item);

        if (!$updated) {
            return ['success' => false, 'message' => 'Target not found.'];
        }

        if (!write_json($filePath, $items)) {
            return ['success' => false, 'message' => 'Failed to persist status change.'];
        }

        return ['success' => true, 'message' => 'Status updated successfully.'];
    }
}

if (!function_exists('moveDocument')) {
    /**
     * Move a document to another user within the same department and log the action.
     *
     * @param string $deptId
     * @param string $docId
     * @param string $targetUserId
     * @param string $initiatorId
     * @param string|null $newStatus
     * @param string|null $dueDate     Date in YYYY-MM-DD format to mark when the document is required.
     * @return array{success: bool, message: string}
     */
    function moveDocument(string $deptId, string $docId, string $targetUserId, string $initiatorId, ?string $newStatus = 'pending', ?string $dueDate = null): array
    {
        $deptPath = __DIR__ . '/storage/departments/' . $deptId;
        $documentPath = $deptPath . '/documents/' . $docId . '.json';

        $users = getDepartmentUsers($deptId);
        $userIds = array_column($users, 'id');
        if (!in_array($targetUserId, $userIds, true)) {
            return ['success' => false, 'message' => 'Target user does not exist in this department.'];
        }

        $document = read_json($documentPath);
        if (!is_array($document)) {
            return ['success' => false, 'message' => 'Document not found.'];
        }

        $previousOwner = $document['current_owner'] ?? null;
        $document['current_owner'] = $targetUserId;
        if ($newStatus !== null) {
            $document['status'] = $newStatus;
        }

        if ($dueDate !== null) {
            $document['due_date'] = $dueDate;
        } elseif (!array_key_exists('due_date', $document)) {
            $document['due_date'] = null;
        }

        $historyEntry = [
            'action' => 'moved',
            'from' => $previousOwner,
            'to' => $targetUserId,
            'time' => date('c'),
            'by' => $initiatorId,
        ];

        if (array_key_exists('due_date', $document)) {
            $historyEntry['due_date'] = $document['due_date'];
        }

        if (!isset($document['history']) || !is_array($document['history'])) {
            $document['history'] = [];
        }
        $document['history'][] = $historyEntry;

        if (!write_json($documentPath, $document)) {
            return ['success' => false, 'message' => 'Failed to update document.'];
        }

        append_master_log($deptPath, $docId . ' moved from ' . ($previousOwner ?? 'unknown') . ' to ' . $targetUserId);

        return ['success' => true, 'message' => 'Document moved successfully.'];
    }
}

if (!function_exists('slugify_label')) {
    /**
     * Convert a human-readable label to a filesystem-safe slug.
     */
    function slugify_label(string $label): string
    {
        $slug = strtolower(trim($label));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        return $slug === '' ? 'item' : $slug;
    }
}

if (!function_exists('checkPermission')) {
    /**
     * Verify a user has the required role (or is superadmin).
     */
    function checkPermission(string $required_role): bool
    {
        if (!isset($_SESSION['role_id'])) {
            return false;
        }

        if ($_SESSION['role_id'] === 'superadmin') {
            return true;
        }

        return $_SESSION['role_id'] === $required_role;
    }
}

if (!function_exists('createDepartment')) {
    /**
     * Create a new department with the required folder and bootstrap files.
     *
     * @param string $name
     * @param string $id
     * @param string $password
     * @param string|null $adminUserId
     * @return array{success: bool, message: string}
     */
    function createDepartment(string $name, string $id, string $password, ?string $adminUserId = null): array
    {
        $id = strtolower(trim($id));
        $id = preg_replace('/[^a-z0-9_\-]/', '', $id ?? '');

        if ($id === '') {
            return ['success' => false, 'message' => 'Department ID cannot be empty.'];
        }

        $basePath = __DIR__ . '/storage/departments/' . $id;
        if (is_dir($basePath)) {
            return ['success' => false, 'message' => 'Department ID already exists.'];
        }

        $structure = [
            $basePath,
            "$basePath/users",
            "$basePath/roles",
            "$basePath/documents",
            "$basePath/data",
            "$basePath/templates",
            "$basePath/logs",
        ];

        foreach ($structure as $dir) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                return ['success' => false, 'message' => 'Failed to create department directories.'];
            }
        }

        $roleId = 'admin.' . $id;
        $userId = $adminUserId !== null && $adminUserId !== ''
            ? strtolower(preg_replace('/[^a-z0-9._-]/', '', trim($adminUserId)))
            : 'user.admin.' . $id;

        if ($userId === '') {
            return ['success' => false, 'message' => 'Admin user ID cannot be empty.'];
        }

        $rolesPath = "$basePath/roles/roles.json";
        $usersPath = "$basePath/users/users.json";
        $metaPath = "$basePath/department.json";

        $roleData = [
            [
                'id' => $roleId,
                'name' => 'Department Administrator',
                'permissions' => ['ALL'],
                'status' => 'active',
            ],
        ];

        $userData = [
            [
                'id' => $userId,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'roles' => [$roleId],
                'status' => 'active',
            ],
        ];

        $metaData = [
            'id' => $id,
            'name' => $name,
            'created_at' => date('c'),
            'status' => 'active',
        ];

        $writes = [
            write_json($rolesPath, $roleData),
            write_json($usersPath, $userData),
            write_json($metaPath, $metaData),
        ];

        if (in_array(false, $writes, true)) {
            return ['success' => false, 'message' => 'Failed to write department bootstrap files.'];
        }

        return ['success' => true, 'message' => 'Department created successfully.'];
    }
}

if (!function_exists('createUser')) {
    /**
     * Create a user within a department enforcing unique IDs and admin constraints.
     *
     * @param string $deptId
     * @param string $firstName
     * @param string $lastName
     * @param string $password
     * @param string $roleId
     * @param string|null $customId
     * @return array{success: bool, message: string, user_id?: string}
     */
    function createUser(string $deptId, string $firstName, string $lastName, string $password, string $roleId, ?string $customId = null): array
    {
        $deptUsers = getDepartmentUsers($deptId);

        $userId = $customId !== null && $customId !== ''
            ? strtolower(preg_replace('/[^a-z0-9._-]/', '', trim($customId)))
            : generate_user_identifier($firstName, $lastName);

        if ($userId === '') {
            return ['success' => false, 'message' => 'User ID cannot be empty.'];
        }

        $existingIds = array_column($deptUsers, 'id');
        if (in_array($userId, $existingIds, true)) {
            return ['success' => false, 'message' => 'ID already taken.'];
        }

        $adminRoleId = 'admin.' . $deptId;
        if ($roleId === $adminRoleId) {
            foreach ($deptUsers as $user) {
                $userRoles = $user['roles'] ?? [];
                $status = $user['status'] ?? 'active';
                if (in_array($adminRoleId, $userRoles, true) && $status === 'active') {
                    return ['success' => false, 'message' => 'Critical: This department already has an active Administrator. Please archive or suspend the existing Admin before creating a new one.'];
                }
            }
        }

        $fullName = trim($firstName . ' ' . $lastName);
        $deptUsers[] = [
            'id' => $userId,
            'name' => $fullName !== '' ? $fullName : $userId,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'roles' => [$roleId],
            'status' => 'active',
        ];

        $usersPath = __DIR__ . '/storage/departments/' . $deptId . '/users/users.json';
        if (!write_json($usersPath, $deptUsers)) {
            return ['success' => false, 'message' => 'Unable to save new user.'];
        }

        return ['success' => true, 'message' => 'User created successfully.', 'user_id' => $userId];
    }
}

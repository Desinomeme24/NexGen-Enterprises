<?php
/* =========================================================================
   NEXGEN BUSINESS / BRANCH WORKSPACE HELPERS

   Compatibility rule:
   - businesses.id remains the tenant/workspace key used by every existing
     inventory, sales, analytics, and receivable query.
   - Related branches share a business_entity/business_code, but every branch
     keeps its own businesses.id and branch_code. Existing module isolation is
     therefore preserved without changing the operational table structure.
   ========================================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('nxWorkspaceSchemaReady')) {
    function nxWorkspaceSchemaReady(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        $result = $conn->query(
            "SELECT
                (SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name IN (
                       'business_entities',
                       'user_business_assignments',
                       'user_branch_assignments'
                   )) AS required_tables,
                (SELECT COUNT(*)
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'businesses'
                   AND column_name IN (
                       'business_entity_id',
                       'branch_code',
                       'branch_name',
                       'is_main_branch',
                       'branch_status'
                   )) AS business_columns,
                (SELECT COUNT(*)
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'registration_requests'
                   AND column_name IN (
                       'business_entity_id',
                       'branch_code',
                       'possible_duplicate',
                       'duplicate_business_id',
                       'duplicate_reason'
                   )) AS request_columns,
                (SELECT COUNT(*)
                 FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND (
                       (table_name = 'user_business_assignments'
                        AND column_name IN (
                            'user_id', 'business_entity_id', 'assignment_role',
                            'is_default', 'status'
                        ))
                       OR
                       (table_name = 'user_branch_assignments'
                        AND column_name IN (
                            'user_id', 'business_id', 'is_primary', 'status'
                        ))
                   )) AS assignment_columns"
        );

        $schema = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $ready = $schema
            && (int)$schema['required_tables'] === 3
            && (int)$schema['business_columns'] === 5
            && (int)$schema['request_columns'] === 5
            && (int)$schema['assignment_columns'] === 9;

        return $ready;
    }
}

if (!function_exists('nxWorkspaceRequestSchemaReady')) {
    function nxWorkspaceRequestSchemaReady(mysqli $conn): bool
    {
        static $ready = null;

        if ($ready !== null) {
            return $ready;
        }

        $result = $conn->query(
            "SELECT COUNT(*) AS matched_columns
             FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'workspace_requests'
               AND column_name IN (
                   'id', 'request_code', 'requested_by', 'request_type',
                   'business_entity_id', 'business_name', 'business_type',
                   'business_address', 'branch_name', 'separate_operations',
                   'request_status', 'admin_remarks', 'reviewed_by', 'reviewed_at',
                   'approved_business_entity_id', 'approved_business_id',
                   'business_code', 'branch_code', 'pending_guard',
                   'created_at', 'updated_at'
               )"
        );

        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $ready = $row && (int)$row['matched_columns'] === 21;

        return $ready;
    }
}

if (!function_exists('nxGetPendingWorkspaceRequest')) {
    function nxGetPendingWorkspaceRequest(mysqli $conn, int $userId): ?array
    {
        if ($userId <= 0 || !nxWorkspaceRequestSchemaReady($conn)) {
            return null;
        }

        $stmt = $conn->prepare(
            "SELECT id, request_code, request_type, business_name, branch_name,
                    request_status, created_at
             FROM workspace_requests
             WHERE requested_by = ? AND request_status = 'pending'
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $request;
    }
}

if (!function_exists('nxGenerateWorkspaceRequestCode')) {
    function nxGenerateWorkspaceRequestCode(mysqli $conn): string
    {
        if (!nxWorkspaceRequestSchemaReady($conn)) {
            throw new RuntimeException('The workspace approval migration has not been installed.');
        }

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = 'WRQ-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $conn->prepare(
                'SELECT 1 FROM workspace_requests WHERE request_code = ? LIMIT 1'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to validate the workspace request code.');
            }
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if (!$exists) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique workspace request code. Please try again.');
    }
}

if (!function_exists('nxNormalizeBusinessIdentity')) {
    function nxNormalizeBusinessIdentity(?string $value): string
    {
        $value = trim((string)$value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }
}

if (!function_exists('nxGenerateUniqueWorkspaceCode')) {
    function nxGenerateUniqueWorkspaceCode(mysqli $conn, string $kind): string
    {
        $config = [
            'business' => ['table' => 'business_entities', 'column' => 'business_code', 'prefix' => 'SME-'],
            'branch' => ['table' => 'businesses', 'column' => 'branch_code', 'prefix' => 'BR-'],
        ];

        if (!isset($config[$kind])) {
            throw new InvalidArgumentException('Unsupported workspace code type.');
        }

        $table = $config[$kind]['table'];
        $column = $config[$kind]['column'];
        $prefix = $config[$kind]['prefix'];

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = $prefix . strtoupper(bin2hex(random_bytes(4)));
            $stmt = $conn->prepare("SELECT 1 FROM {$table} WHERE {$column} = ? LIMIT 1");
            if (!$stmt) {
                throw new RuntimeException('Unable to validate a generated workspace code.');
            }
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if (!$exists) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique workspace code. Please try again.');
    }
}

if (!function_exists('nxLegacyBusinessId')) {
    function nxLegacyBusinessId(mysqli $conn, int $userId): int
    {
        $stmt = $conn->prepare('SELECT business_id FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['business_id'] ?? 0);
    }
}

if (!function_exists('nxBootstrapLegacyWorkspaceAssignment')) {
    function nxBootstrapLegacyWorkspaceAssignment(mysqli $conn, int $userId, string $role, int $legacyBusinessId): void
    {
        if (!nxWorkspaceSchemaReady($conn) || $legacyBusinessId <= 0) {
            return;
        }

        $workspaceStmt = $conn->prepare(
            "SELECT business_entity_id
             FROM businesses
             WHERE id = ? AND branch_status = 'active'
             LIMIT 1"
        );
        if (!$workspaceStmt) {
            return;
        }

        $workspaceStmt->bind_param('i', $legacyBusinessId);
        $workspaceStmt->execute();
        $workspace = $workspaceStmt->get_result()->fetch_assoc();
        $workspaceStmt->close();

        $entityId = (int)($workspace['business_entity_id'] ?? 0);
        if ($entityId <= 0) {
            return;
        }

        $assignmentRole = $role === 'owner' ? 'owner' : 'employee';
        $businessStmt = $conn->prepare(
            "INSERT INTO user_business_assignments
                (user_id, business_entity_id, assignment_role, is_default, status)
             VALUES (?, ?, ?, 1, 'active')
             ON DUPLICATE KEY UPDATE
                assignment_role = VALUES(assignment_role),
                status = 'active'"
        );
        if ($businessStmt) {
            $businessStmt->bind_param('iis', $userId, $entityId, $assignmentRole);
            $businessStmt->execute();
            $businessStmt->close();
        }

        $branchStmt = $conn->prepare(
            "INSERT INTO user_branch_assignments
                (user_id, business_id, is_primary, status)
             VALUES (?, ?, 1, 'active')
             ON DUPLICATE KEY UPDATE status = 'active'"
        );
        if ($branchStmt) {
            $branchStmt->bind_param('ii', $userId, $legacyBusinessId);
            $branchStmt->execute();
            $branchStmt->close();
        }
    }
}

if (!function_exists('nxGetAccessibleWorkspaces')) {
    function nxGetAccessibleWorkspaces(mysqli $conn, ?int $userId = null, ?string $role = null): array
    {
        $userId = $userId ?? (int)($_SESSION['user_id'] ?? 0);
        $role = $role ?? (string)($_SESSION['role'] ?? '');

        if ($userId <= 0 || $role === 'system_admin') {
            return [];
        }

        if (!nxWorkspaceSchemaReady($conn)) {
            $legacyBusinessId = (int)($_SESSION['business_id'] ?? 0);
            if ($legacyBusinessId <= 0) {
                $legacyBusinessId = nxLegacyBusinessId($conn, $userId);
            }

            if ($legacyBusinessId <= 0) {
                return [];
            }

            $stmt = $conn->prepare(
                'SELECT id AS business_id, business_name, business_type, business_address,
                        business_code, NULL AS business_entity_id, NULL AS branch_code,
                        business_name AS branch_name, 1 AS is_main_branch
                 FROM businesses WHERE id = ? LIMIT 1'
            );
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param('i', $legacyBusinessId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            return $row ? [$row] : [];
        }

        $legacyBusinessId = nxLegacyBusinessId($conn, $userId);
        nxBootstrapLegacyWorkspaceAssignment($conn, $userId, $role, $legacyBusinessId);

        $ownerAccess = $role === 'owner' ? 1 : 0;
        $stmt = $conn->prepare(
            "SELECT
                b.id AS business_id,
                b.business_entity_id,
                be.business_code,
                be.business_name,
                be.business_type,
                b.branch_code,
                b.branch_name,
                b.business_address,
                b.is_main_branch,
                uba.is_default,
                COALESCE(ubra.is_primary, 0) AS is_primary
             FROM user_business_assignments uba
             INNER JOIN business_entities be
                ON be.id = uba.business_entity_id AND be.status = 'active'
             INNER JOIN businesses b
                ON b.business_entity_id = be.id AND b.branch_status = 'active'
             LEFT JOIN user_branch_assignments ubra
                ON ubra.user_id = uba.user_id
               AND ubra.business_id = b.id
               AND ubra.status = 'active'
             WHERE uba.user_id = ?
               AND uba.status = 'active'
               AND (? = 1 OR ubra.user_id IS NOT NULL)
             ORDER BY uba.is_default DESC, ubra.is_primary DESC,
                      be.business_name ASC, b.is_main_branch DESC, b.branch_name ASC, b.id ASC"
        );
        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $userId, $ownerAccess);
        $stmt->execute();
        $result = $stmt->get_result();
        $workspaces = [];
        while ($row = $result->fetch_assoc()) {
            $workspaces[] = $row;
        }
        $stmt->close();

        return $workspaces;
    }
}

if (!function_exists('nxApplyWorkspaceToSession')) {
    function nxApplyWorkspaceToSession(array $workspace): void
    {
        $_SESSION['business_id'] = (int)$workspace['business_id'];
        $_SESSION['branch_id'] = (int)$workspace['business_id'];
        $_SESSION['business_entity_id'] = (int)($workspace['business_entity_id'] ?? 0);
        $_SESSION['business_code'] = (string)($workspace['business_code'] ?? '');
        $_SESSION['business_name'] = (string)($workspace['business_name'] ?? '');
        $_SESSION['business_type'] = (string)($workspace['business_type'] ?? '');
        $_SESSION['branch_code'] = (string)($workspace['branch_code'] ?? '');
        $_SESSION['branch_name'] = (string)($workspace['branch_name'] ?? 'Main Branch');
        $_SESSION['branch_address'] = (string)($workspace['business_address'] ?? '');
    }
}

if (!function_exists('nxInitializeUserWorkspace')) {
    function nxInitializeUserWorkspace(mysqli $conn, bool $forceDefault = false): array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = (string)($_SESSION['role'] ?? '');

        if ($userId <= 0 || $role === 'system_admin') {
            return [];
        }

        $workspaces = nxGetAccessibleWorkspaces($conn, $userId, $role);
        if (empty($workspaces)) {
            return [];
        }

        $requestedId = $forceDefault ? 0 : (int)($_SESSION['business_id'] ?? 0);
        $selected = null;

        if ($requestedId > 0) {
            foreach ($workspaces as $workspace) {
                if ((int)$workspace['business_id'] === $requestedId) {
                    $selected = $workspace;
                    break;
                }
            }
        }

        if (!$selected) {
            $selected = $workspaces[0];
        }

        nxApplyWorkspaceToSession($selected);

        return $selected;
    }
}

if (!function_exists('nxSetActiveWorkspace')) {
    function nxSetActiveWorkspace(mysqli $conn, int $businessId): array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = (string)($_SESSION['role'] ?? '');

        if ($userId <= 0 || !in_array($role, ['owner', 'employee'], true)) {
            throw new RuntimeException('Only SME owners and employees can switch workspaces.');
        }

        $workspaces = nxGetAccessibleWorkspaces($conn, $userId, $role);
        $selected = null;
        foreach ($workspaces as $workspace) {
            if ((int)$workspace['business_id'] === $businessId) {
                $selected = $workspace;
                break;
            }
        }

        if (!$selected) {
            throw new RuntimeException('You do not have access to the selected business branch.');
        }

        if (nxWorkspaceSchemaReady($conn)) {
            $entityId = (int)$selected['business_entity_id'];
            $conn->begin_transaction();
            try {
                $clearBusiness = $conn->prepare(
                    'UPDATE user_business_assignments SET is_default = 0 WHERE user_id = ?'
                );
                if (!$clearBusiness) {
                    throw new RuntimeException('Unable to update the default business.');
                }
                $clearBusiness->bind_param('i', $userId);
                $clearBusiness->execute();
                $clearBusiness->close();

                $defaultBusiness = $conn->prepare(
                    "UPDATE user_business_assignments
                     SET is_default = 1
                     WHERE user_id = ? AND business_entity_id = ? AND status = 'active'"
                );
                if (!$defaultBusiness) {
                    throw new RuntimeException('Unable to select the requested business.');
                }
                $defaultBusiness->bind_param('ii', $userId, $entityId);
                $defaultBusiness->execute();
                $defaultBusiness->close();

                $clearBranch = $conn->prepare(
                    'UPDATE user_branch_assignments SET is_primary = 0 WHERE user_id = ?'
                );
                if ($clearBranch) {
                    $clearBranch->bind_param('i', $userId);
                    $clearBranch->execute();
                    $clearBranch->close();
                }

                $primaryBranch = $conn->prepare(
                    "INSERT INTO user_branch_assignments
                        (user_id, business_id, is_primary, status)
                     VALUES (?, ?, 1, 'active')
                     ON DUPLICATE KEY UPDATE is_primary = 1, status = 'active'"
                );
                if (!$primaryBranch) {
                    throw new RuntimeException('Unable to select the requested branch.');
                }
                $primaryBranch->bind_param('ii', $userId, $businessId);
                $primaryBranch->execute();
                $primaryBranch->close();

                /* Keep the legacy pointer synchronized so older process files
                   that still read users.business_id continue using the active
                   branch until they are upgraded to tenant_helper.php. */
                $legacy = $conn->prepare('UPDATE users SET business_id = ? WHERE id = ?');
                if (!$legacy) {
                    throw new RuntimeException('Unable to synchronize the active workspace.');
                }
                $legacy->bind_param('ii', $businessId, $userId);
                $legacy->execute();
                $legacy->close();

                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
        }

        nxApplyWorkspaceToSession($selected);

        return $selected;
    }
}

if (!function_exists('nxRequireBusinessId')) {
    function nxRequireBusinessId(mysqli $conn): int
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: /NexGen/CODE/PHP/index.php');
            exit();
        }

        $role = (string)($_SESSION['role'] ?? '');
        if ($role === 'system_admin') {
            return 0;
        }

        $workspace = nxInitializeUserWorkspace($conn);
        $businessId = (int)($workspace['business_id'] ?? 0);

        if ($businessId <= 0) {
            $_SESSION['error'] = 'Your account is not connected to an active SME business branch. Please contact the administrator.';
            header('Location: /NexGen/CODE/PHP/dashboard.php');
            exit();
        }

        return $businessId;
    }
}

if (!function_exists('nxRequireBranchId')) {
    function nxRequireBranchId(mysqli $conn): int
    {
        return nxRequireBusinessId($conn);
    }
}

if (!function_exists('nxGetActiveWorkspace')) {
    function nxGetActiveWorkspace(mysqli $conn): array
    {
        $workspace = nxInitializeUserWorkspace($conn);

        return $workspace ?: [];
    }
}
?>

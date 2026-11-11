<?php
/* =========================================================================
   NEXGEN BUSINESS / BRANCH WORKSPACE HELPERS

   Compatibility rule:
   - businesses.id remains the tenant/workspace key used by every existing
     inventory, sales, and analytics query.
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

if (!function_exists('nxWorkspaceBusinessTypeSchemaReady')) {
    function nxWorkspaceBusinessTypeSchemaReady(mysqli $conn): bool
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
               AND column_name = 'business_type_other'"
        );

        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $ready = $row && (int)$row['matched_columns'] === 1;

        return $ready;
    }
}

if (!function_exists('nxWorkspacePermitSchemaReady')) {
    function nxWorkspacePermitSchemaReady(mysqli $conn): bool
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
               AND column_name = 'business_permit_path'"
        );

        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        $ready = $row && (int)$row['matched_columns'] === 1;

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
            'business' => ['table' => 'business_entities', 'column' => 'business_code', 'prefix' => 'SE-'],
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

        if ($userId <= 0 || nxIsAdminRole($role)) {
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
                'SELECT id AS business_id, business_name, business_type, business_type_other, business_address,
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
                be.business_type_other,
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
        $_SESSION['business_type_other'] = (string)($workspace['business_type_other'] ?? '');
        $_SESSION['branch_code'] = (string)($workspace['branch_code'] ?? '');
        $_SESSION['branch_name'] = (string)($workspace['branch_name'] ?? 'Main Branch');
        $_SESSION['branch_address'] = (string)($workspace['business_address'] ?? '');
    }
}

if (!function_exists('nxGetOwnedBusinessEntityIds')) {
    function nxGetOwnedBusinessEntityIds(mysqli $conn, int $ownerId): array
    {
        if ($ownerId <= 0) {
            return [];
        }

        if (nxWorkspaceSchemaReady($conn)) {
            $stmt = $conn->prepare(
                "SELECT DISTINCT uba.business_entity_id
                 FROM user_business_assignments uba
                 INNER JOIN users u ON u.id = uba.user_id AND u.role = 'owner'
                 WHERE uba.user_id = ?
                   AND uba.assignment_role = 'owner'
                   AND uba.status = 'active'
                 UNION
                 SELECT b.business_entity_id
                 FROM users u
                 INNER JOIN businesses b ON b.id = u.business_id
                 WHERE u.id = ?
                   AND u.role = 'owner'
                   AND NOT EXISTS (
                       SELECT 1
                       FROM user_business_assignments uba
                       WHERE uba.user_id = u.id
                   )"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to verify business ownership.');
            }

            $stmt->bind_param('ii', $ownerId, $ownerId);
        } else {
            return [];
        }

        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to verify business ownership.');
        }

        $result = $stmt->get_result();
        $entityIds = [];
        while ($row = $result->fetch_assoc()) {
            $entityId = (int)($row['business_entity_id'] ?? 0);
            if ($entityId > 0) {
                $entityIds[$entityId] = $entityId;
            }
        }
        $stmt->close();

        return array_values($entityIds);
    }
}

if (!function_exists('nxOwnerCanReviewEmployeeRequest')) {
    function nxOwnerCanReviewEmployeeRequest(mysqli $conn, int $ownerId, array $request): bool
    {
        if (
            $ownerId <= 0
            || (string)($request['requested_role'] ?? '') !== 'employee'
            || (int)($request['business_id'] ?? 0) <= 0
            || !nxWorkspaceSchemaReady($conn)
        ) {
            return false;
        }

        $businessCode = strtoupper(trim((string)($request['business_code'] ?? '')));
        $branchCode = strtoupper(trim((string)($request['branch_code'] ?? '')));
        if ($businessCode === '' || $branchCode === '') {
            return false;
        }

        $stmt = $conn->prepare(
            "SELECT b.business_entity_id, be.business_code, b.branch_code
             FROM businesses b
             INNER JOIN business_entities be ON be.id = b.business_entity_id
             WHERE b.id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to verify the employee request workspace.');
        }

        $businessId = (int)$request['business_id'];
        $stmt->bind_param('i', $businessId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to verify the employee request workspace.');
        }

        $workspace = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$workspace) {
            return false;
        }

        $entityId = (int)$workspace['business_entity_id'];
        $requestEntityId = (int)($request['business_entity_id'] ?? 0);
        if (
            ($requestEntityId > 0 && $requestEntityId !== $entityId)
            || !hash_equals(strtoupper((string)$workspace['business_code']), $businessCode)
            || !hash_equals(strtoupper((string)$workspace['branch_code']), $branchCode)
        ) {
            return false;
        }

        return in_array($entityId, nxGetOwnedBusinessEntityIds($conn, $ownerId), true);
    }
}

if (!function_exists('nxInitializeUserWorkspace')) {
    function nxInitializeUserWorkspace(mysqli $conn, bool $forceDefault = false): array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $role = (string)($_SESSION['role'] ?? '');

        if ($userId <= 0 || nxIsAdminRole($role)) {
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
            throw new RuntimeException('Only SE owners and employees can switch workspaces.');
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

        try {
            $permit = nxGetBusinessPermitAccess($conn, $businessId);
        } catch (Throwable $permitError) {
            error_log('NexGen workspace switch permit check failed: ' . $permitError->getMessage());
            throw new RuntimeException('The selected business permit could not be verified. Please try again later.');
        }

        if (empty($permit['found'])) {
            throw new RuntimeException('The selected business permit could not be verified. Please contact BPLO.');
        }

        if (!empty($permit['grace_expired'])) {
            throw new RuntimeException(
                'This business is unavailable because its permit is unpaid beyond the 20-day grace period. Please coordinate with BPLO to activate your account.'
            );
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

if (!function_exists('nxDefaultInventoryCategories')) {
    function nxDefaultInventoryCategories(string $businessType): array
    {
        $categories = [
            'Hardware / Construction Supplies' => [
                'Cement & Aggregates',
                'Steel & Metal',
                'Lumber & Plywood',
                'Plumbing',
                'Electrical',
                'Paints & Finishes',
                'Hand & Power Tools',
            ],
            'Mini Grocery / Sari-Sari Store' => [
                'Canned & Packaged Goods',
                'Rice, Grains & Cooking Essentials',
                'Snacks',
                'Beverages',
                'Condiments & Seasonings',
                'Toiletries & Personal Care',
                'Household & Cleaning',
            ],
            'Pharmacy / Drugstore' => [
                'Prescription Medicines',
                'Over-the-Counter Medicines',
                'Vitamins & Supplements',
                'First Aid & Wound Care',
                'Personal Care & Hygiene',
                'Baby & Mother Care',
                'Medical Devices',
            ],
            'School / Office Supplies' => [
                'Writing Instruments',
                'Paper Products',
                'Filing & Organizers',
                'Art & Craft Supplies',
                'Desk Accessories',
                'School Bags & Cases',
                'Printing & Computer Supplies',
            ],
            'Others' => [
                'Consumables',
                'Equipment & Tools',
                'Parts & Accessories',
                'Supplies & Materials',
                'Packaging',
                'Maintenance & Cleaning',
                'Miscellaneous',
            ],
        ];

        return $categories[$businessType] ?? $categories['Others'];
    }
}

if (!function_exists('nxSeedDefaultInventoryCategories')) {
    function nxSeedDefaultInventoryCategories(mysqli $conn, int $businessId, string $businessType): void
    {
        if ($businessId <= 0) {
            throw new InvalidArgumentException('A valid business is required to seed inventory categories.');
        }

        $existsStmt = $conn->prepare(
            'SELECT id FROM categories WHERE business_id = ? AND category_name = ? LIMIT 1'
        );
        $insertStmt = $conn->prepare(
            'INSERT INTO categories (business_id, category_name) VALUES (?, ?)'
        );
        if (!$existsStmt || !$insertStmt) {
            throw new RuntimeException('Unable to prepare default inventory categories.');
        }

        foreach (nxDefaultInventoryCategories($businessType) as $categoryName) {
            $existsStmt->bind_param('is', $businessId, $categoryName);
            $existsStmt->execute();
            if ($existsStmt->get_result()->num_rows > 0) {
                continue;
            }

            $insertStmt->bind_param('is', $businessId, $categoryName);
            $insertStmt->execute();
        }

        $existsStmt->close();
        $insertStmt->close();
    }
}

if (!function_exists('nxGetBusinessPermitAccess')) {
    function nxGetBusinessPermitAccess(mysqli $conn, int $businessId): array
    {
        if ($businessId <= 0) {
            return ['found' => false, 'status' => '', 'unpaid_since' => null, 'grace_expired' => false];
        }

        $stmt = $conn->prepare(
            "SELECT be.permit_payment_status, be.permit_unpaid_since
             FROM businesses b
             INNER JOIN business_entities be ON be.id = b.business_entity_id
             WHERE b.id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            throw new RuntimeException('Business permit access check could not be prepared: ' . $conn->error);
        }
        $stmt->bind_param('i', $businessId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Business permit access check failed: ' . $error);
        }
        $permit = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$permit) {
            return ['found' => false, 'status' => '', 'unpaid_since' => null, 'grace_expired' => false];
        }

        $status = strtolower(trim((string)($permit['permit_payment_status'] ?? '')));
        $unpaidSince = trim((string)($permit['permit_unpaid_since'] ?? ''));
        $unpaidTimestamp = $unpaidSince !== '' ? strtotime($unpaidSince) : false;
        $graceExpired = $status !== 'paid'
            && ($status !== 'unpaid' || $unpaidTimestamp === false || $unpaidTimestamp + (20 * 24 * 60 * 60) <= time());

        return [
            'found' => true,
            'status' => $status,
            'unpaid_since' => $unpaidSince !== '' ? $unpaidSince : null,
            'grace_expired' => $graceExpired,
        ];
    }
}

if (!function_exists('nxBusinessPermitQuarterGraceDeadline')) {
    function nxBusinessPermitQuarterGraceDeadline(int $permitYear, int $quarter): DateTimeImmutable
    {
        if ($permitYear < 2000 || $permitYear > 2100 || $quarter < 1 || $quarter > 4) {
            throw new InvalidArgumentException('A valid permit year and quarter are required.');
        }

        $timezone = new DateTimeZone('Asia/Manila');
        $startMonth = (($quarter - 1) * 3) + 1;
        $quarterStart = new DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $permitYear, $startMonth),
            $timezone
        );

        return $quarterStart->modify('+20 days');
    }
}

if (!function_exists('nxBusinessPermitQuarterGraceExpired')) {
    function nxBusinessPermitQuarterGraceExpired(
        int $permitYear,
        int $quarter,
        ?DateTimeImmutable $now = null
    ): bool {
        $timezone = new DateTimeZone('Asia/Manila');
        $now = $now ? $now->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);

        return $now >= nxBusinessPermitQuarterGraceDeadline($permitYear, $quarter);
    }
}

if (!function_exists('nxAutoMarkExpiredBusinessPermitQuarters')) {
    function nxAutoMarkExpiredBusinessPermitQuarters(mysqli $conn, int $permitYear, int $adminId): int
    {
        if ($permitYear < 2000 || $permitYear > 2100 || $adminId <= 0) {
            throw new InvalidArgumentException('A valid permit year and administrator are required.');
        }

        if (!$conn->begin_transaction()) {
            throw new RuntimeException('Unable to begin automatic permit status updates.');
        }

        try {
            $pendingStmt = $conn->prepare(
                "SELECT a.id AS assessment_id, a.business_entity_id, p.quarter_number
                 FROM business_permit_assessments a
                 INNER JOIN business_permit_quarterly_payments p ON p.assessment_id = a.id
                 WHERE a.permit_year = ? AND p.payment_status = 'pending'
                 ORDER BY a.business_entity_id, p.quarter_number"
            );
            if (!$pendingStmt) {
                throw new RuntimeException('Unable to load pending permit quarters: ' . $conn->error);
            }
            $pendingStmt->bind_param('i', $permitYear);
            if (!$pendingStmt->execute()) {
                throw new RuntimeException('Unable to load pending permit quarters: ' . $pendingStmt->error);
            }

            $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
            $expiredQuarters = [];
            $pendingResult = $pendingStmt->get_result();
            while ($quarterRow = $pendingResult->fetch_assoc()) {
                $quarterNumber = (int)$quarterRow['quarter_number'];
                if (nxBusinessPermitQuarterGraceExpired($permitYear, $quarterNumber, $now)) {
                    $entityId = (int)$quarterRow['business_entity_id'];
                    $assessmentId = (int)$quarterRow['assessment_id'];
                    $expiredQuarters[$entityId]['assessment_id'] = $assessmentId;
                    $expiredQuarters[$entityId]['quarters'][] = $quarterNumber;
                }
            }
            $pendingStmt->close();

            if ($expiredQuarters === []) {
                if (!$conn->commit()) {
                    throw new RuntimeException('Unable to finish automatic permit status updates.');
                }
                return 0;
            }

            $markStmt = $conn->prepare(
                "UPDATE business_permit_quarterly_payments
                 SET payment_status = 'unpaid', paid_at = NULL, paid_by = NULL
                 WHERE assessment_id = ? AND quarter_number = ? AND payment_status = 'pending'"
            );
            $unpaidSinceStmt = $conn->prepare(
                "SELECT quarter_number
                 FROM business_permit_quarterly_payments
                 WHERE assessment_id = ? AND payment_status = 'unpaid'
                 ORDER BY quarter_number
                 LIMIT 1"
            );
            $entityStmt = $conn->prepare(
                "UPDATE business_entities
                 SET permit_payment_status = 'unpaid', permit_unpaid_since = ?
                 WHERE id = ?"
            );
            if (!$markStmt || !$unpaidSinceStmt || !$entityStmt) {
                throw new RuntimeException('Unable to prepare automatic permit status updates: ' . $conn->error);
            }

            $updatedQuarters = 0;
            foreach ($expiredQuarters as $entityId => $entityData) {
                $changedQuarters = [];
                foreach (array_unique($entityData['quarters']) as $quarterNumber) {
                    $assessmentId = $entityData['assessment_id'];
                    $markStmt->bind_param('ii', $assessmentId, $quarterNumber);
                    if (!$markStmt->execute()) {
                        throw new RuntimeException('Unable to mark an expired permit quarter unpaid: ' . $markStmt->error);
                    }
                    if ($markStmt->affected_rows > 0) {
                        $updatedQuarters++;
                        $changedQuarters[] = $quarterNumber;
                    }
                }

                if ($changedQuarters === []) {
                    continue;
                }

                $assessmentId = $entityData['assessment_id'];
                $unpaidSinceStmt->bind_param('i', $assessmentId);
                if (!$unpaidSinceStmt->execute()) {
                    throw new RuntimeException('Unable to determine the unpaid permit start date: ' . $unpaidSinceStmt->error);
                }
                $firstUnpaid = $unpaidSinceStmt->get_result()->fetch_assoc();
                if (!$firstUnpaid) {
                    throw new RuntimeException('An automatically unpaid permit quarter could not be verified.');
                }

                $firstUnpaidMonth = (((int)$firstUnpaid['quarter_number'] - 1) * 3) + 1;
                $unpaidSince = sprintf('%04d-%02d-01 00:00:00', $permitYear, $firstUnpaidMonth);
                $entityStmt->bind_param('si', $unpaidSince, $entityId);
                if (!$entityStmt->execute()) {
                    throw new RuntimeException('Unable to synchronize the business permit status: ' . $entityStmt->error);
                }

                sort($changedQuarters);
                $quarterList = implode(', ', $changedQuarters);
                $description = "Automatically marked permit quarter(s) {$quarterList} for {$permitYear} as unpaid after the 20-day grace period.";
                if (!logAdminActivitySecure(
                    $conn,
                    $adminId,
                    'auto_mark_business_permit_unpaid',
                    'business_entity',
                    (int)$entityId,
                    $description
                )) {
                    throw new RuntimeException('Unable to record the automatic permit status change.');
                }
            }

            $markStmt->close();
            $unpaidSinceStmt->close();
            $entityStmt->close();
            if (!$conn->commit()) {
                throw new RuntimeException('Unable to commit automatic permit status updates.');
            }

            return $updatedQuarters;
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
    }
}

if (!function_exists('nxRequireBusinessId')) {
    function nxRequireBusinessId(mysqli $conn): int
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . nxAppUrl('index.php'));
            exit();
        }

        $role = (string)($_SESSION['role'] ?? '');
        if (nxIsAdminRole($role)) {
            return 0;
        }

        $workspace = nxInitializeUserWorkspace($conn);
        $businessId = (int)($workspace['business_id'] ?? 0);

        if ($businessId <= 0) {
            $_SESSION['error'] = 'Your account is not connected to an active SE business branch. Please contact the administrator.';
            header('Location: ' . nxAppUrl('dashboard.php'));
            exit();
        }

        try {
            $permit = nxGetBusinessPermitAccess($conn, $businessId);
        } catch (Throwable $error) {
            error_log('NexGen business permit access check failed: ' . $error->getMessage());
            http_response_code(503);
            exit('Business access could not be verified. Please try again later.');
        }

        if (empty($permit['found'])) {
            http_response_code(403);
            exit('The active business permit record could not be verified.');
        }

        if (!empty($permit['grace_expired'])) {
            $_SESSION['business_permit_access_blocked'] = true;
            $_SESSION['business_permit_notice'] = 'Business access is temporarily suspended because the permit has remained unpaid beyond the 20-day grace period. Contact the business administrator after payment.';
            header('Location: ' . nxAppUrl('dashboard.php'));
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

<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/resend_config.php';
require_once __DIR__ . '/tenant_helper.php';

function nxResubmitRequestWantsJson(): bool
{
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function nxResubmitRequestRespond(
    bool $success,
    string $message,
    int $requestId = 0,
    int $httpStatus = 200,
    string $redirect = '/NexGen/CODE/PHP/pending_requests.php'
): void {
    if (nxResubmitRequestWantsJson()) {
        http_response_code($httpStatus);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, private, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'request_id' => $requestId,
            'status' => $success ? 'resubmit' : null,
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $_SESSION['flash'] = [
        'type' => $success ? 'notice-success' : 'notice-error',
        'message' => $message,
    ];
    header('Location: ' . $redirect);
    exit;
}

function nxResubmitLengthBetween(string $value, int $minimum, int $maximum): bool
{
    $length = function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);

    return $length >= $minimum && $length <= $maximum;
}

function nxResubmitHasControlCharacters(string $value, bool $allowLineBreaks = false): bool
{
    $pattern = $allowLineBreaks
        ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u'
        : '/[\x00-\x1F\x7F]/u';

    return (bool)preg_match($pattern, $value);
}

function nxResubmitNormalizeEmail(string $email): string
{
    $parts = explode('@', trim($email), 2);
    if (count($parts) !== 2) {
        return trim($email);
    }

    return $parts[0] . '@' . strtolower($parts[1]);
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'system_admin') {
    nxResubmitRequestRespond(
        false,
        'Your administrator session is no longer valid. Please log in again.',
        0,
        401,
        '/NexGen/CODE/PHP/index.php?open=login'
    );
}

enforceSessionTimeout();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nxResubmitRequestRespond(false, 'Invalid request method.', 0, 405);
}

if (!validateCsrfToken('admin_request_action', $_POST['csrf_token'] ?? null)) {
    nxResubmitRequestRespond(false, 'Your session expired. Please reopen the request and try again.', 0, 403);
}

$requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$adminId = (int)$_SESSION['user_id'];
$remarks = trim((string)($_POST['remarks'] ?? ''));

$fullName = trim((string)($_POST['full_name'] ?? ''));
$email = nxResubmitNormalizeEmail((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$username = trim((string)($_POST['username'] ?? ''));
$employeeNo = trim((string)($_POST['employee_no'] ?? ''));
$businessName = trim((string)($_POST['business_name'] ?? ''));
$businessType = trim((string)($_POST['business_type'] ?? ''));
$businessAddress = trim((string)($_POST['business_address'] ?? ''));
$businessCode = strtoupper(trim((string)($_POST['business_code'] ?? '')));
$branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));

if ($requestId <= 0) {
    nxResubmitRequestRespond(false, 'Invalid registration request ID.', 0, 422);
}

if (
    !nxResubmitLengthBetween($remarks, 3, 2000)
    || nxResubmitHasControlCharacters($remarks, true)
) {
    nxResubmitRequestRespond(false, 'Provide correction instructions between 3 and 2,000 characters.', $requestId, 422);
}

if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
    nxResubmitRequestRespond(
        false,
        'Username must be 3 to 50 characters and may contain letters, numbers, dots, underscores, and hyphens.',
        $requestId,
        422
    );
}

if (!nxResubmitLengthBetween($fullName, 2, 100) || nxResubmitHasControlCharacters($fullName)) {
    nxResubmitRequestRespond(false, 'Full name must be 2 to 100 valid characters.', $requestId, 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !nxResubmitLengthBetween($email, 3, 100)) {
    nxResubmitRequestRespond(false, 'Enter a valid email address of at most 100 characters.', $requestId, 422);
}

if (!preg_match('/^[0-9+().\- ]{7,20}$/', $phone)) {
    nxResubmitRequestRespond(false, 'Enter a valid phone number using 7 to 20 digits or common phone symbols.', $requestId, 422);
}

if (!nxResubmitLengthBetween($address, 5, 500) || nxResubmitHasControlCharacters($address, true)) {
    nxResubmitRequestRespond(false, 'Personal address must be 5 to 500 valid characters.', $requestId, 422);
}

$replacementUpload = null;
if (isset($_FILES['valid_id']) && (int)($_FILES['valid_id']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    $replacementUpload = $_FILES['valid_id'];

    [$uploadIsValid, $uploadMessage] = nxValidateSecureUpload($replacementUpload, [
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'pdf'],
        'allowed_mime_types' => ['image/jpeg', 'image/png', 'application/pdf'],
        'mime_extension_map' => [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'pdf' => ['application/pdf'],
        ],
        'max_size' => 5 * 1024 * 1024,
        'require_image' => false,
        'allow_pdf' => true,
    ]);

    if (!$uploadIsValid) {
        nxResubmitRequestRespond(
            false,
            'Replacement attachment blocked: ' . $uploadMessage,
            $requestId,
            422
        );
    }
}

$newPhysicalPath = null;
$oldPhysicalPath = null;
$request = null;
$changedFields = [];

try {
    $adminStmt = $conn->prepare(
        "SELECT id
         FROM users
         WHERE id = ? AND role = 'system_admin' AND account_status = 'active'
         LIMIT 1"
    );
    if (!$adminStmt) {
        throw new RuntimeException('Unable to verify the administrator account.');
    }
    $adminStmt->bind_param('i', $adminId);
    $adminStmt->execute();
    $adminIsActive = (bool)$adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();

    if (!$adminIsActive) {
        nxResubmitRequestRespond(
            false,
            'Your administrator account is no longer active.',
            $requestId,
            403,
            '/NexGen/CODE/PHP/index.php?open=login'
        );
    }

    $conn->begin_transaction();

    $stmt = $conn->prepare(
        "SELECT *
         FROM registration_requests
         WHERE id = ?
         FOR UPDATE"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to read the registration request.');
    }
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$request) {
        throw new RuntimeException('Registration request not found.');
    }

    $currentStatus = (string)$request['request_status'];
    if (!in_array($currentStatus, ['pending', 'resubmit'], true)) {
        throw new RuntimeException('Only pending or resubmission requests can be corrected.');
    }

    $role = (string)$request['requested_role'];
    if (!in_array($role, ['owner', 'employee'], true)) {
        throw new RuntimeException('The request contains an invalid account role.');
    }

    $uniqueStmt = $conn->prepare(
        'SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1'
    );
    if (!$uniqueStmt) {
        throw new RuntimeException('Unable to check existing accounts.');
    }
    $uniqueStmt->bind_param('ss', $username, $email);
    $uniqueStmt->execute();
    $accountExists = $uniqueStmt->get_result()->num_rows > 0;
    $uniqueStmt->close();

    if ($accountExists) {
        throw new RuntimeException('The corrected username or email is already used by an existing account.');
    }

    $uniqueStmt = $conn->prepare(
        "SELECT id
         FROM registration_requests
         WHERE id <> ?
           AND request_status IN ('pending', 'resubmit')
           AND (username = ? OR email = ?)
         LIMIT 1"
    );
    if (!$uniqueStmt) {
        throw new RuntimeException('Unable to check active registration requests.');
    }
    $uniqueStmt->bind_param('iss', $requestId, $username, $email);
    $uniqueStmt->execute();
    $activeRequestExists = $uniqueStmt->get_result()->num_rows > 0;
    $uniqueStmt->close();

    if ($activeRequestExists) {
        throw new RuntimeException('Another pending request already uses the corrected username or email.');
    }

    $businessId = null;
    $businessEntityId = null;
    $businessCodeForDb = null;
    $branchCodeForDb = null;
    $employeeNoForDb = null;
    $possibleDuplicate = 0;
    $duplicateBusinessId = null;
    $duplicateReason = null;

    if ($role === 'owner') {
        $allowedBusinessTypes = [
            'Hardware / Construction Supplies',
            'Mini Grocery / Sari-Sari Store',
            'Pharmacy / Drugstore',
            'School / Office Supplies',
        ];
        $existingBusinessType = trim((string)$request['business_type']);

        if (
            !nxResubmitLengthBetween($businessName, 2, 150)
            || nxResubmitHasControlCharacters($businessName)
            || !nxResubmitLengthBetween($businessType, 2, 100)
            || nxResubmitHasControlCharacters($businessType)
            || (
                !in_array($businessType, $allowedBusinessTypes, true)
                && !hash_equals($existingBusinessType, $businessType)
            )
            || !nxResubmitLengthBetween($businessAddress, 5, 500)
            || nxResubmitHasControlCharacters($businessAddress, true)
        ) {
            throw new RuntimeException('Provide complete and valid SME business information.');
        }

        $duplicateStmt = $conn->prepare(
            "SELECT b.id, be.business_name, be.business_type, b.business_address
             FROM business_entities be
             INNER JOIN businesses b ON b.business_entity_id = be.id
             WHERE be.status = 'active' AND b.branch_status = 'active'"
        );
        if (!$duplicateStmt) {
            throw new RuntimeException('Unable to recheck the business identity.');
        }

        $duplicateStmt->execute();
        $duplicateResult = $duplicateStmt->get_result();
        while ($existing = $duplicateResult->fetch_assoc()) {
            $sameName = nxNormalizeBusinessIdentity((string)$existing['business_name'])
                === nxNormalizeBusinessIdentity($businessName);
            $sameType = nxNormalizeBusinessIdentity((string)$existing['business_type'])
                === nxNormalizeBusinessIdentity($businessType);
            $sameAddress = nxNormalizeBusinessIdentity((string)$existing['business_address'])
                === nxNormalizeBusinessIdentity($businessAddress);

            if ($sameName && $sameType && $sameAddress) {
                $possibleDuplicate = 1;
                $duplicateBusinessId = (int)$existing['id'];
                $duplicateReason = 'Exact business name, SME type, and branch address match. Review the existing business/branch before approval.';
                break;
            }

            if ($sameName && $sameType && $possibleDuplicate === 0) {
                $possibleDuplicate = 1;
                $duplicateBusinessId = (int)$existing['id'];
                $duplicateReason = 'Same business name and SME type found at a different address. Verify whether this should be a branch of the existing business.';
            }
        }
        $duplicateStmt->close();
    } else {
        if (
            !preg_match('/^[A-Z0-9-]{3,20}$/', $businessCode)
            || !preg_match('/^[A-Z0-9-]{3,20}$/', $branchCode)
            || !preg_match('/^[A-Za-z0-9._-]{1,50}$/', $employeeNo)
        ) {
            throw new RuntimeException('Provide valid SME employment information.');
        }

        $workspaceStmt = $conn->prepare(
            "SELECT b.id, b.business_entity_id, be.business_name, be.business_type,
                    b.business_address, be.business_code, b.branch_code
             FROM businesses b
             INNER JOIN business_entities be ON be.id = b.business_entity_id
             WHERE be.business_code = ?
               AND b.branch_code = ?
               AND be.status = 'active'
               AND b.branch_status = 'active'
             LIMIT 1"
        );
        if (!$workspaceStmt) {
            throw new RuntimeException('Unable to verify the corrected business and branch codes.');
        }
        $workspaceStmt->bind_param('ss', $businessCode, $branchCode);
        $workspaceStmt->execute();
        $workspace = $workspaceStmt->get_result()->fetch_assoc();
        $workspaceStmt->close();

        if (!$workspace) {
            throw new RuntimeException('The corrected business and branch codes do not match an active branch.');
        }

        $businessId = (int)$workspace['id'];
        $businessEntityId = (int)$workspace['business_entity_id'];
        $businessName = (string)$workspace['business_name'];
        $businessType = (string)$workspace['business_type'];
        $businessAddress = (string)$workspace['business_address'];
        $businessCodeForDb = (string)$workspace['business_code'];
        $branchCodeForDb = (string)$workspace['branch_code'];
        $employeeNoForDb = $employeeNo;
    }

    $validIdReference = (string)$request['valid_id_path'];
    if ($replacementUpload !== null) {
        $targetDirectory = nxPrivateValidIdDirectory();
        if (
            !is_dir($targetDirectory)
            && !mkdir($targetDirectory, 0700, true)
            && !is_dir($targetDirectory)
        ) {
            throw new RuntimeException('The private attachment directory could not be created.');
        }

        $extension = strtolower(pathinfo((string)$replacementUpload['name'], PATHINFO_EXTENSION));
        $newFilename = 'valid_id_' . bin2hex(random_bytes(12)) . '.' . $extension;
        $newPhysicalPath = $targetDirectory . DIRECTORY_SEPARATOR . $newFilename;
        $validIdReference = nxCreatePrivateValidIdReference($newFilename);

        if (!move_uploaded_file((string)$replacementUpload['tmp_name'], $newPhysicalPath)) {
            throw new RuntimeException('The replacement attachment could not be stored.');
        }
        @chmod($newPhysicalPath, 0600);
        $oldPhysicalPath = nxResolveValidIdPath((string)$request['valid_id_path']);
        $changedFields[] = 'valid ID attachment';
    }

    $trackedFields = [
        'full_name' => [$request['full_name'], $fullName],
        'email' => [$request['email'], $email],
        'phone' => [$request['phone'], $phone],
        'address' => [$request['address'], $address],
        'username' => [$request['username'], $username],
        'employee_no' => [$request['employee_no'], $employeeNoForDb],
        'business_name' => [$request['business_name'], $businessName],
        'business_type' => [$request['business_type'], $businessType],
        'business_address' => [$request['business_address'], $businessAddress],
        'business_code' => [$request['business_code'], $businessCodeForDb],
        'branch_code' => [$request['branch_code'], $branchCodeForDb],
    ];
    foreach ($trackedFields as $field => [$before, $after]) {
        if ((string)$before !== (string)$after) {
            $changedFields[] = $field;
        }
    }

    $reviewedAt = date('Y-m-d H:i:s');
    $stmt = $conn->prepare(
        "UPDATE registration_requests
         SET full_name = ?,
             email = ?,
             phone = ?,
             address = ?,
             username = ?,
             valid_id_path = ?,
             employee_no = ?,
             business_name = ?,
             business_type = ?,
             business_address = ?,
             business_code = ?,
             business_id = ?,
             business_entity_id = ?,
             branch_code = ?,
             possible_duplicate = ?,
             duplicate_business_id = ?,
             duplicate_reason = ?,
             request_status = 'resubmit',
             admin_remarks = ?,
             reviewed_by = ?,
             reviewed_at = ?
         WHERE id = ?
           AND request_status IN ('pending', 'resubmit')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the corrected request update.');
    }

    $stmt->bind_param(
        'sssssssssssiisiissisi',
        $fullName,
        $email,
        $phone,
        $address,
        $username,
        $validIdReference,
        $employeeNoForDb,
        $businessName,
        $businessType,
        $businessAddress,
        $businessCodeForDb,
        $businessId,
        $businessEntityId,
        $branchCodeForDb,
        $possibleDuplicate,
        $duplicateBusinessId,
        $duplicateReason,
        $remarks,
        $adminId,
        $reviewedAt,
        $requestId
    );

    if (!$stmt->execute() || $stmt->affected_rows !== 1) {
        throw new RuntimeException('The request changed before the corrections could be saved.');
    }
    $stmt->close();

    $changeSummary = empty($changedFields)
        ? 'instructions only'
        : implode(', ', array_unique($changedFields));
    $description = "Updated request #{$requestId} for resubmission; changed: {$changeSummary}";

    if (!logAdminActivitySecure(
        $conn,
        $adminId,
        'resubmit_request',
        'registration_request',
        $requestId,
        $description
    )) {
        throw new RuntimeException('Unable to record the administrator action.');
    }

    $conn->commit();
    $_SESSION['last_activity'] = time();

    if (
        $newPhysicalPath !== null
        && $oldPhysicalPath !== null
        && !hash_equals($newPhysicalPath, $oldPhysicalPath)
        && is_file($oldPhysicalPath)
    ) {
        if (!@unlink($oldPhysicalPath)) {
            error_log("Unable to remove replaced valid ID for request #{$requestId}.");
        }
    }
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
        error_log('NexGen resubmission rollback failed: ' . $rollbackError->getMessage());
    }

    if ($newPhysicalPath !== null && is_file($newPhysicalPath)) {
        @unlink($newPhysicalPath);
    }

    error_log(
        "NexGen resubmission update failed for request #{$requestId}, admin #{$adminId}: " .
        $e->getMessage()
    );

    nxResubmitRequestRespond(
        false,
        'Resubmission update failed: ' . $e->getMessage(),
        $requestId,
        422,
        '/NexGen/CODE/PHP/view_request.php?id=' . $requestId
    );
}

/* A mail failure must not reverse the committed request update. */
try {
    $emailBody =
        "Hello,\n\n" .
        "Your NexGen registration requires corrections before it can be approved.\n\n" .
        "Administrator instructions:\n{$remarks}\n\n" .
        "If any corrected information is inaccurate, please contact the administrator.\n\n" .
        "Thank you.\n— NexGen System";

    nxSendResendEmail(
        (string)$email,
        (string)($fullName ?: 'NexGen User'),
        'NexGen Registration Requires Resubmission',
        $emailBody
    );
} catch (Throwable $mailError) {
    error_log(
        "Resubmission email failed for request #{$requestId}: " .
        $mailError->getMessage()
    );
}

$message = ((string)$request['request_status'] === 'resubmit')
    ? 'Resubmission details updated successfully.'
    : 'Corrections saved and the request was marked for resubmission.';

nxResubmitRequestRespond(true, $message, $requestId);

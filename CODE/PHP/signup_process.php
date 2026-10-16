<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/document_upload_security.php';

$signupStartedAt = microtime(true);

function signupRedirect(string $message, string $type = 'error'): void
{
    $_SESSION[$type] = $message;
    if ($type === 'error') {
        $_SESSION['form_type'] = 'signup';
    }
    if ($type === 'success') {
        unset(
            $_SESSION['signup_privacy_ack_token'],
            $_SESSION['signup_privacy_acknowledged_token']
        );
    }

    header('Location: /NexGen/CODE/PHP/index.php?open=signup');
    exit();
}

function signupNeutralRedirect(): void
{
    global $signupStartedAt;

    // Keep duplicate and accepted submissions on a similar response schedule.
    $minimumSeconds = 0.75 + (random_int(0, 150) / 1000);
    $remainingSeconds = $minimumSeconds - (microtime(true) - $signupStartedAt);
    if ($remainingSeconds > 0) {
        usleep((int)round($remainingSeconds * 1000000));
    }

    signupRedirect(
        'Your registration information has been processed. If it is eligible for a new account, it will be submitted for administrator review. Use Sign In or Forgot Password if you may already have an account.',
        'success'
    );
}

function signupInternalError(string $logMessage): void
{
    error_log('NexGen signup error: ' . $logMessage);
    signupRedirect('We could not process the registration securely. Please try again later.');
}

function signupLengthBetween(string $value, int $minimum, int $maximum): bool
{
    $length = function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);

    return $length >= $minimum && $length <= $maximum;
}

function signupHasControlCharacters(string $value): bool
{
    return (bool)preg_match('/[\x00-\x1F\x7F]/u', $value);
}

function normalizeSignupEmail(string $email): string
{
    $parts = explode('@', trim($email), 2);
    if (count($parts) !== 2) {
        return trim($email);
    }

    return $parts[0] . '@' . strtolower($parts[1]);
}

function signupDeleteSensitiveDocument(string $reference, string $slot): bool
{
    try {
        return nxDeleteSensitiveDocument($reference, $slot);
    } catch (Throwable $error) {
        nxDocumentUploadLog('storage.cleanup_failed', ['slot' => $slot]);
        error_log('NexGen signup document cleanup failed: ' . $error->getMessage());
        return false;
    }
}

function generateRequestCode(mysqli $conn): string
{
    $prefix = 'REQ-' . date('Ymd') . '-';

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $requestCode = $prefix . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $conn->prepare('SELECT 1 FROM registration_requests WHERE request_code = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the request-code check.');
        }

        $stmt->bind_param('s', $requestCode);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$exists) {
            return $requestCode;
        }
    }

    throw new RuntimeException('Unable to allocate a unique registration request code.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    signupRedirect('Invalid request.');
}

if (!validateCsrfToken('signup_form', $_POST['csrf_token'] ?? '')) {
    signupRedirect('Invalid or expired signup form token. Please reopen the signup form and try again.');
}

$ipRateLimit = nxConsumeSecurityRateLimit(
    $conn,
    'signup_ip',
    (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'),
    8,
    900,
    1800
);
if (!$ipRateLimit['configured']) {
    signupRedirect('Registration security is not fully configured. Please ask the administrator to install the signup security migration.');
}
if (!$ipRateLimit['allowed']) {
    signupRedirect('Too many registration attempts were received. Please wait before trying again.');
}

$captchaSelection = $_POST['captcha_selection'] ?? [];
if (!validateImageCaptchaSelection('signup_form', (array)$captchaSelection)) {
    signupRedirect('Please complete the image captcha correctly.');
}

if (($_POST['privacy_consent'] ?? null) !== '1') {
    signupRedirect('You must agree to the Privacy Policy before submitting your account request.');
}

$privacyAckToken = isset($_POST['privacy_ack_token']) && is_string($_POST['privacy_ack_token'])
    ? $_POST['privacy_ack_token']
    : '';
$expectedPrivacyAckToken = isset($_SESSION['signup_privacy_ack_token']) && is_string($_SESSION['signup_privacy_ack_token'])
    ? $_SESSION['signup_privacy_ack_token']
    : '';
$acknowledgedPrivacyToken = isset($_SESSION['signup_privacy_acknowledged_token']) && is_string($_SESSION['signup_privacy_acknowledged_token'])
    ? $_SESSION['signup_privacy_acknowledged_token']
    : '';
if (
    !preg_match('/^[a-f0-9]{64}$/D', $privacyAckToken)
    || $expectedPrivacyAckToken === ''
    || $acknowledgedPrivacyToken === ''
    || !hash_equals($expectedPrivacyAckToken, $privacyAckToken)
    || !hash_equals($acknowledgedPrivacyToken, $privacyAckToken)
) {
    signupRedirect('Please read and acknowledge the Privacy Policy before submitting your account request.');
}

$username = trim((string)($_POST['signup_username'] ?? ''));
$fullName = trim((string)($_POST['fullname'] ?? ''));
$email = normalizeSignupEmail((string)($_POST['email'] ?? ''));
$phone = trim((string)($_POST['phone'] ?? ''));
$address = trim((string)($_POST['address'] ?? ''));
$requestedRole = trim((string)($_POST['requested_role'] ?? ''));
$password = (string)($_POST['signup_password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');

$businessName = trim((string)($_POST['business_name'] ?? ''));
$businessType = trim((string)($_POST['business_type'] ?? ''));
$businessAddress = trim((string)($_POST['business_address'] ?? ''));
$businessCode = strtoupper(trim((string)($_POST['business_code'] ?? '')));
$branchCode = strtoupper(trim((string)($_POST['branch_code'] ?? '')));
$employeeNo = trim((string)($_POST['employee_no'] ?? ''));
$businessId = null;
$businessEntityId = null;
$possibleDuplicate = 0;
$duplicateBusinessId = null;
$duplicateReason = null;

try {
    $workspaceSchemaReady = nxWorkspaceSchemaReady($conn);
} catch (Throwable $e) {
    signupInternalError($e->getMessage());
}
if (!$workspaceSchemaReady) {
    signupRedirect('Please ask the administrator to run the multi-business and branch migration before accepting new registrations.');
}

if (
    $username === '' || $fullName === '' || $email === '' || $phone === ''
    || $address === '' || $requestedRole === '' || $password === '' || $confirmPassword === ''
) {
    signupRedirect('Please fill in all required account fields.');
}

if (!in_array($requestedRole, ['owner', 'employee'], true)) {
    signupRedirect('Invalid requested role.');
}

if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
    signupRedirect('Username must be 3 to 50 characters and may contain letters, numbers, dots, underscores, and hyphens.');
}

if (!signupLengthBetween($fullName, 2, 100) || signupHasControlCharacters($fullName)) {
    signupRedirect('Full name must be 2 to 100 characters and cannot contain control characters.');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !signupLengthBetween($email, 3, 100)) {
    signupRedirect('Please enter a valid email address of at most 100 characters.');
}

if (!preg_match('/^[0-9]{11}$/', $phone)) {
    signupRedirect('Please enter an 11-digit Philippine phone number using digits only.');
}

if (!signupLengthBetween($address, 5, 500) || signupHasControlCharacters($address)) {
    signupRedirect('Personal address must be 5 to 500 characters.');
}

if (!isStrongPassword($password)) {
    signupRedirect('Password must be 12 to 64 characters and include uppercase, lowercase, number, and special character.');
}

if ($password !== $confirmPassword) {
    signupRedirect('Passwords do not match.');
}

$emailRateLimit = nxConsumeSecurityRateLimit(
    $conn,
    'signup_email',
    strtolower($email),
    4,
    3600,
    3600
);
if (!$emailRateLimit['configured']) {
    signupRedirect('Registration security is not fully configured. Please ask the administrator to install the signup security migration.');
}
if (!$emailRateLimit['allowed']) {
    signupRedirect('Too many registration attempts were received. Please wait before trying again.');
}

if ($requestedRole === 'owner') {
    $allowedBusinessTypes = [
        'Hardware / Construction Supplies',
        'Mini Grocery / Sari-Sari Store',
        'Pharmacy / Drugstore',
        'School / Office Supplies',
    ];

    if (
        !signupLengthBetween($businessName, 2, 150)
        || signupHasControlCharacters($businessName)
        || !in_array($businessType, $allowedBusinessTypes, true)
        || !signupLengthBetween($businessAddress, 5, 500)
        || signupHasControlCharacters($businessAddress)
    ) {
        signupRedirect('Please provide complete and valid SME business information.');
    }

    // A new owner's business is created only after administrator approval.
    $businessCode = '';
    $branchCode = '';
    $employeeNo = '';

    try {
        $duplicateStmt = $conn->prepare(
            "SELECT b.id, be.business_name, be.business_type, b.business_address
             FROM business_entities be
             INNER JOIN businesses b ON b.business_entity_id = be.id
             WHERE be.status = 'active' AND b.branch_status = 'active'"
        );
        if (!$duplicateStmt) {
            throw new RuntimeException('Unable to prepare the business identity check.');
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
    } catch (Throwable $e) {
        signupInternalError($e->getMessage());
    }
} else {
    if (
        !preg_match('/^[A-Z0-9-]{3,20}$/', $businessCode)
        || !preg_match('/^[A-Z0-9-]{3,20}$/', $branchCode)
        || !preg_match('/^[A-Za-z0-9._-]{1,50}$/', $employeeNo)
    ) {
        signupRedirect('Please provide valid SME employment information.');
    }

    $business = null;
    try {
        $businessStmt = $conn->prepare(
            "SELECT b.id, b.business_entity_id, be.business_name, be.business_type,
                    b.business_address, be.business_code, b.branch_code
             FROM businesses b
             INNER JOIN business_entities be ON be.id = b.business_entity_id
             WHERE be.business_code = ? AND b.branch_code = ?
               AND be.status = 'active' AND b.branch_status = 'active'
             LIMIT 1"
        );
        if (!$businessStmt) {
            throw new RuntimeException('Unable to prepare the SME branch check.');
        }

        $businessStmt->bind_param('ss', $businessCode, $branchCode);
        $businessStmt->execute();
        $business = $businessStmt->get_result()->fetch_assoc();
        $businessStmt->close();
    } catch (Throwable $e) {
        signupInternalError($e->getMessage());
    }

    if (!$business) {
        signupRedirect('The employment information could not be verified. Please confirm the codes with the SME owner.');
    }

    $businessId = (int)$business['id'];
    $businessEntityId = (int)$business['business_entity_id'];
    $businessName = (string)$business['business_name'];
    $businessType = (string)$business['business_type'];
    $businessAddress = (string)$business['business_address'];
}

try {
    nxDocumentUploadValidateBag($_FILES, ['business_permit', 'valid_id']);
    if (
        nxDocumentUploadIsMissing($_FILES['valid_id'])
        || (int)$_FILES['valid_id']['error'] !== UPLOAD_ERR_OK
    ) {
        throw new NxDocumentUploadRejected('A government ID photo is required.');
    }
    if (
        $requestedRole === 'owner'
        && (
            nxDocumentUploadIsMissing($_FILES['business_permit'])
            || (int)$_FILES['business_permit']['error'] !== UPLOAD_ERR_OK
        )
    ) {
        throw new NxDocumentUploadRejected('A business permit is required for SME Owners.');
    }
    if (
        $requestedRole !== 'owner'
        && !nxDocumentUploadIsMissing($_FILES['business_permit'])
    ) {
        throw new NxDocumentUploadRejected('Unexpected document slot.');
    }
} catch (NxDocumentUploadRejected $uploadError) {
    nxDocumentUploadLog('upload.rejected', ['slot' => 'signup']);
    signupRedirect('Please choose the required documents in an allowed format and try again.');
} catch (NxDocumentUploadUnavailable $uploadError) {
    nxDocumentUploadLog('upload.unavailable', ['slot' => 'signup']);
    signupRedirect('Secure document processing is temporarily unavailable. Please try again later.');
}

try {
    // Hash before duplicate checks so both paths perform equivalent expensive work.
    $hashedPassword = nxHashPassword($password);

    $checkUser = $conn->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
    if (!$checkUser) {
        throw new RuntimeException('Unable to prepare the existing-account check.');
    }
    $checkUser->bind_param('ss', $username, $email);
    $checkUser->execute();
    $accountExists = $checkUser->get_result()->num_rows > 0;
    $checkUser->close();

    if ($accountExists) {
        signupNeutralRedirect();
    }

    if ($requestedRole === 'employee') {
        $checkRequest = $conn->prepare(
            "SELECT id
             FROM registration_requests
             WHERE request_status IN ('pending', 'resubmit')
               AND (
                    username = ? OR email = ?
                    OR (requested_role = 'employee' AND business_id = ? AND employee_no = ?)
               )
             LIMIT 1"
        );
        if (!$checkRequest) {
            throw new RuntimeException('Unable to prepare the active-request check.');
        }
        $checkRequest->bind_param('ssis', $username, $email, $businessId, $employeeNo);
    } else {
        $checkRequest = $conn->prepare(
            "SELECT id
             FROM registration_requests
             WHERE request_status IN ('pending', 'resubmit')
               AND (username = ? OR email = ?)
             LIMIT 1"
        );
        if (!$checkRequest) {
            throw new RuntimeException('Unable to prepare the active-request check.');
        }
        $checkRequest->bind_param('ss', $username, $email);
    }

    $checkRequest->execute();
    $activeRequestExists = $checkRequest->get_result()->num_rows > 0;
    $checkRequest->close();

    if ($activeRequestExists) {
        signupNeutralRedirect();
    }
} catch (Throwable $e) {
    signupInternalError($e->getMessage());
}

$stagedValidId = null;
$stagedBusinessPermit = null;
$validIdPath = null;
$businessPermitPath = null;
$storedReferences = [];
try {
    $stagedValidId = nxStageSensitiveDocument($_FILES['valid_id'], 'valid_id', ['role' => 'signup']);
    if ($requestedRole === 'owner') {
        $stagedBusinessPermit = nxStageSensitiveDocument(
            $_FILES['business_permit'],
            'business_permit',
            ['role' => 'signup']
        );
    }

    $validIdPath = nxPersistSensitiveDocument($stagedValidId, 'valid_id');
    $storedReferences[] = [$validIdPath, 'valid_id'];
    $stagedValidId = null;

    if ($stagedBusinessPermit !== null) {
        $businessPermitPath = nxPersistSensitiveDocument($stagedBusinessPermit, 'business_permit');
        $storedReferences[] = [$businessPermitPath, 'business_permit'];
        $stagedBusinessPermit = null;
    }
} catch (NxDocumentUploadUnavailable $uploadError) {
    nxDocumentUploadLog('upload.unavailable', ['slot' => 'signup']);
    nxDiscardStagedSensitiveDocument($stagedValidId);
    nxDiscardStagedSensitiveDocument($stagedBusinessPermit);
    foreach ($storedReferences as [$reference, $slot]) {
        signupDeleteSensitiveDocument($reference, $slot);
    }
    signupRedirect('Secure document processing is temporarily unavailable. Please try again later.');
} catch (NxDocumentUploadRejected $uploadError) {
    nxDocumentUploadLog('upload.rejected', ['slot' => 'signup']);
    nxDiscardStagedSensitiveDocument($stagedValidId);
    nxDiscardStagedSensitiveDocument($stagedBusinessPermit);
    foreach ($storedReferences as [$reference, $slot]) {
        signupDeleteSensitiveDocument($reference, $slot);
    }
    signupRedirect('One or more documents could not be accepted. Check the file requirements and try again.');
} catch (Throwable $uploadError) {
    nxDocumentUploadLog('upload.processing_failed', ['slot' => 'signup']);
    nxDiscardStagedSensitiveDocument($stagedValidId);
    nxDiscardStagedSensitiveDocument($stagedBusinessPermit);
    foreach ($storedReferences as [$reference, $slot]) {
        signupDeleteSensitiveDocument($reference, $slot);
    }
    signupInternalError('Sensitive document processing failed.');
}

try {
    $requestCode = generateRequestCode($conn);
    $employeeNoForDb = $employeeNo !== '' ? $employeeNo : null;
    $businessCodeForDb = $businessCode !== '' ? $businessCode : null;
    $businessIdForDb = $requestedRole === 'employee' ? $businessId : null;
    $businessEntityIdForDb = $requestedRole === 'employee' ? $businessEntityId : null;
    $branchCodeForDb = $requestedRole === 'employee' ? $branchCode : null;

    $privacyConsentAt = date('Y-m-d H:i:s');
    $privacyNoticeVersion = '2026-10-v1';
    $insert = $conn->prepare(
        "INSERT INTO registration_requests (
            request_code, employee_no, full_name, email, phone, address,
            username, password_hash, valid_id_path, business_permit_path,
            privacy_consent_at, privacy_notice_version, requested_role,
            request_status, business_name, business_type, business_address,
            business_code, business_id, business_entity_id, branch_code,
            possible_duplicate, duplicate_business_id, duplicate_reason
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$insert) {
        throw new RuntimeException('Unable to prepare the registration request.');
    }

    $insert->bind_param(
        str_repeat('s', 17) . 'iisii' . 's',
        $requestCode,
        $employeeNoForDb,
        $fullName,
        $email,
        $phone,
        $address,
        $username,
        $hashedPassword,
        $validIdPath,
        $businessPermitPath,
        $privacyConsentAt,
        $privacyNoticeVersion,
        $requestedRole,
        $businessName,
        $businessType,
        $businessAddress,
        $businessCodeForDb,
        $businessIdForDb,
        $businessEntityIdForDb,
        $branchCodeForDb,
        $possibleDuplicate,
        $duplicateBusinessId,
        $duplicateReason
    );

    if (!$insert->execute()) {
        throw new RuntimeException('Unable to submit the registration request.');
    }
    $registrationRequestId = (int)$insert->insert_id;
    $insert->close();
    foreach ($storedReferences as [, $slot]) {
        nxDocumentUploadLog('upload.stored', [
            'role' => 'signup',
            'request_id' => $registrationRequestId,
            'slot' => $slot,
        ]);
    }

    signupNeutralRedirect();
} catch (Throwable $e) {
    foreach ($storedReferences as [$reference, $slot]) {
        if (!signupDeleteSensitiveDocument($reference, $slot)) {
            nxDocumentUploadLog('storage.cleanup_failed', ['slot' => $slot]);
        }
    }

    if ((int)$e->getCode() === 1062) {
        signupNeutralRedirect();
    }

    signupInternalError($e->getMessage());
}
?>

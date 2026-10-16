<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

$signupStartedAt = microtime(true);

function signupRedirect(string $message, string $type = 'error'): void
{
    $_SESSION[$type] = $message;
    if ($type === 'error') {
        $_SESSION['form_type'] = 'signup';
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

if (!isset($_POST['privacy_consent'])) {
    signupRedirect('You must agree to the Privacy Policy before submitting your account request.');
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

if (!preg_match('/^[0-9+().\- ]{7,20}$/', $phone)) {
    signupRedirect('Please enter a valid phone number using 7 to 20 digits or common phone symbols.');
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

if (!isset($_FILES['valid_id']) || $_FILES['valid_id']['error'] !== UPLOAD_ERR_OK) {
    signupRedirect('Please upload a valid ID or proof of employment.');
}

$validIdFile = $_FILES['valid_id'];
[$isValidUpload, $scanResult] = nxValidateSecureUpload($validIdFile, [
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

if (!$isValidUpload) {
    signupRedirect('Valid ID upload blocked: ' . $scanResult);
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

$targetDir = nxPrivateValidIdDirectory();
if (!is_dir($targetDir) && !mkdir($targetDir, 0700, true) && !is_dir($targetDir)) {
    signupInternalError('The private valid-ID upload directory could not be created.');
}

$extension = strtolower(pathinfo((string)$validIdFile['name'], PATHINFO_EXTENSION));
$newFileName = 'valid_id_' . bin2hex(random_bytes(12)) . '.' . $extension;
$targetFile = $targetDir . DIRECTORY_SEPARATOR . $newFileName;

try {
    $validIdPath = nxCreatePrivateValidIdReference($newFileName);
} catch (Throwable $e) {
    signupInternalError($e->getMessage());
}

if (!move_uploaded_file((string)$validIdFile['tmp_name'], $targetFile)) {
    signupInternalError('The uploaded valid ID could not be saved to private storage.');
}
@chmod($targetFile, 0600);

try {
    $requestCode = generateRequestCode($conn);
    $employeeNoForDb = $employeeNo !== '' ? $employeeNo : null;
    $businessCodeForDb = $businessCode !== '' ? $businessCode : null;
    $businessIdForDb = $requestedRole === 'employee' ? $businessId : null;
    $businessEntityIdForDb = $requestedRole === 'employee' ? $businessEntityId : null;
    $branchCodeForDb = $requestedRole === 'employee' ? $branchCode : null;

    $insert = $conn->prepare(
        "INSERT INTO registration_requests (
            request_code, employee_no, full_name, email, phone, address,
            username, password_hash, valid_id_path, requested_role,
            request_status, business_name, business_type, business_address,
            business_code, business_id, business_entity_id, branch_code,
            possible_duplicate, duplicate_business_id, duplicate_reason
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$insert) {
        throw new RuntimeException('Unable to prepare the registration request.');
    }

    $insert->bind_param(
        'ssssssssssssssiisiis',
        $requestCode,
        $employeeNoForDb,
        $fullName,
        $email,
        $phone,
        $address,
        $username,
        $hashedPassword,
        $validIdPath,
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
    $insert->close();

    signupNeutralRedirect();
} catch (Throwable $e) {
    if (is_file($targetFile)) {
        @unlink($targetFile);
    }

    if ((int)$e->getCode() === 1062) {
        signupNeutralRedirect();
    }

    signupInternalError($e->getMessage());
}
?>

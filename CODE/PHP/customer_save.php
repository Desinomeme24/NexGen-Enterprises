<?php
session_start();
require_once("config.php");
require_once __DIR__ . '/tenant_helper.php';

function isAjaxRequest() {
    return (
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    );
}

function sendJson($success, $message, $extra = []) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }

    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit();
}

function normalizeText(string $value): string {
    $value = trim($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
    return $value ?? '';
}

if (!isset($_SESSION['user_id'])) {
    if (isAjaxRequest()) {
        sendJson(false, 'Session expired. Please log in again.');
    }

    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['owner', 'employee'], true)) {
    if (isAjaxRequest()) {
        sendJson(false, 'Unauthorized access.');
    }

    $_SESSION['error'] = 'Unauthorized access.';
    header("Location: /NexGen/CODE/PHP/dashboard.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (isAjaxRequest()) {
        sendJson(false, 'Invalid request method.');
    }

    header("Location: /NexGen/CODE/PHP/sales_recording.php");
    exit();
}

if (!validateCsrfToken('sale_form', $_POST['csrf_token'] ?? '')) {
    if (isAjaxRequest()) {
        sendJson(false, 'Invalid or expired form token.');
    }

    $_SESSION['error'] = 'Invalid or expired form token.';
    header('Location: ' . nxAppUrl('sales_recording.php'));
    exit();
}

$businessId = nxRequireBusinessId($conn);

$customer_name = normalizeText($_POST['customer_name'] ?? '');
$phone = normalizeText($_POST['phone'] ?? '');
$email = normalizeText($_POST['email'] ?? '');
$address = normalizeText($_POST['address'] ?? '');
$payment_status_context = normalizeText($_POST['payment_status_context'] ?? 'Paid');

if ($customer_name === '') {
    if (isAjaxRequest()) {
        sendJson(false, 'Customer name is required.');
    }

    $_SESSION['error'] = 'Customer name is required.';
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
    exit();
}

if (in_array($payment_status_context, ['Unpaid', 'Partially Paid'], true)) {
    if ($phone === '' || $email === '' || $address === '') {
        if (isAjaxRequest()) {
            sendJson(false, 'Phone, email, and address are required for unpaid or partially paid customers.');
        }

        $_SESSION['error'] = 'Phone, email, and address are required for unpaid or partially paid customers.';
        header("Location: /NexGen/CODE/PHP/sales_recording.php");
        exit();
    }
}

function generateCustomerCode($conn, int $businessId) {
    $prefix = 'CUS-' . date('Ymd');

    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM customers
        WHERE business_id = ? AND customer_code LIKE CONCAT(?, '%')
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param("is", $businessId, $prefix);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : ['total' => 0];
    $stmt->close();

    $next = ((int)($row['total'] ?? 0)) + 1;
    return $prefix . '-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

$customer_code = generateCustomerCode($conn, $businessId);

if ($customer_code === false) {
    if (isAjaxRequest()) {
        sendJson(false, 'Failed to generate customer code.');
    }

    $_SESSION['error'] = 'Failed to generate customer code.';
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
    exit();
}

$stmt = $conn->prepare("
    INSERT INTO customers (
        business_id,
        customer_code,
        customer_name,
        phone,
        email,
        address,
        status
    ) VALUES (?, ?, ?, ?, ?, ?, 1)
");

if (!$stmt) {
    if (isAjaxRequest()) {
        sendJson(false, 'Failed to prepare save query.');
    }

    $_SESSION['error'] = 'Failed to prepare save query.';
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
    exit();
}

$stmt->bind_param("isssss", $businessId, $customer_code, $customer_name, $phone, $email, $address);

if ($stmt->execute()) {
    $customer_id = $conn->insert_id;
    $stmt->close();

    if (isAjaxRequest()) {
        sendJson(true, 'Customer added successfully.', [
            'customer' => [
                'id' => (int)$customer_id,
                'customer_code' => $customer_code,
                'customer_name' => $customer_name,
                'phone' => $phone,
                'email' => $email,
                'address' => $address
            ]
        ]);
    }

    $_SESSION['success'] = 'Customer added successfully.';
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
    exit();
} else {
    $stmt->close();

    if (isAjaxRequest()) {
        sendJson(false, 'Failed to add customer.');
    }

    $_SESSION['error'] = 'Failed to add customer.';
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
    exit();
}
?>
<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "config.php";
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/ar_helper.php';

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Session expired. Please log in again.']);
    exit();
}

if (!nxArEnabled()) {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have access to Accounts Receivable.']);
    exit();
}

$businessId = nxRequireBusinessId($conn);
$receivableId = (int)($_GET['id'] ?? 0);

if ($receivableId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid receivable ID.']);
    exit();
}

$stmt = $conn->prepare("\n    SELECT ar.id, ar.customer_id\n    FROM accounts_receivable ar\n    INNER JOIN customers c ON c.id = ar.customer_id AND c.business_id = ?\n    INNER JOIN sales s ON s.id = ar.sale_id AND s.business_id = ?\n    WHERE ar.id = ? AND ar.business_id = ?\n    LIMIT 1\n");
$stmt->bind_param('iiii', $businessId, $businessId, $receivableId, $businessId);
$stmt->execute();
$receivable = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$receivable) {
    http_response_code(404);
    echo json_encode(['error' => 'Receivable record not found.']);
    exit();
}

$payments = [];
$paymentStmt = $conn->prepare("\n    SELECT amount, payment_method, reference_no, paid_at\n    FROM receivable_payments\n    WHERE receivable_id = ? AND business_id = ?\n    ORDER BY paid_at DESC, id DESC\n");
if ($paymentStmt) {
    $paymentStmt->bind_param('ii', $receivableId, $businessId);
    $paymentStmt->execute();
    $paymentResult = $paymentStmt->get_result();
    while ($row = $paymentResult->fetch_assoc()) {
        $payments[] = [
            'date' => !empty($row['paid_at']) ? date('M d, Y h:i A', strtotime($row['paid_at'])) : 'N/A',
            'amount' => number_format((float)$row['amount'], 2),
            'method' => $row['payment_method'] ?: 'Manual Update',
            'reference' => $row['reference_no'] ?: '—'
        ];
    }
    $paymentStmt->close();
}

$otherTransactions = [];
$customerId = (int)$receivable['customer_id'];
$otherStmt = $conn->prepare("\n    SELECT ar.id, ar.total_amount, ar.amount_paid, ar.balance_due, ar.due_date, ar.created_at, s.sales_no,\n           CASE\n               WHEN ar.balance_due <= 0 THEN 'Paid'\n               WHEN ar.due_date IS NOT NULL AND ar.due_date < CURDATE() THEN 'Overdue'\n               WHEN ar.amount_paid > 0 THEN 'Partially Paid'\n               ELSE 'Unpaid'\n           END AS live_status\n    FROM accounts_receivable ar\n    INNER JOIN sales s ON s.id = ar.sale_id AND s.business_id = ?\n    WHERE ar.customer_id = ? AND ar.business_id = ? AND ar.id <> ?\n    ORDER BY ar.created_at DESC\n    LIMIT 10\n");
$otherStmt->bind_param('iiii', $businessId, $customerId, $businessId, $receivableId);
$otherStmt->execute();
$otherResult = $otherStmt->get_result();
while ($row = $otherResult->fetch_assoc()) {
    $otherTransactions[] = [
        'id' => (int)$row['id'],
        'sales_no' => $row['sales_no'],
        'date' => !empty($row['created_at']) ? date('M d, Y', strtotime($row['created_at'])) : 'N/A',
        'due_date' => !empty($row['due_date']) ? $row['due_date'] : 'N/A',
        'status' => $row['live_status'],
        'total' => number_format((float)$row['total_amount'], 2),
        'paid' => number_format((float)$row['amount_paid'], 2),
        'balance' => number_format((float)$row['balance_due'], 2)
    ];
}
$otherStmt->close();

echo json_encode([
    'payments' => $payments,
    'other_transactions' => $otherTransactions
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
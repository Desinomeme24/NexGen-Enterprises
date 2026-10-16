<?php
session_start();
require_once("config.php");
require_once("tenant_helper.php");

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
    exit();
}

if ((int)($_SESSION['can_inventory'] ?? 0) !== 1) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have access to Inventory Management.']);
    exit();
}

$businessId = nxRequireBusinessId($conn);
$productId = (int)($_GET['product_id'] ?? 0);

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid product.']);
    exit();
}

$productStmt = $conn->prepare("SELECT product_name FROM products WHERE id = ? AND business_id = ? LIMIT 1");
$productStmt->bind_param("ii", $productId, $businessId);
$productStmt->execute();
$product = $productStmt->get_result()->fetch_assoc();
$productStmt->close();

if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Product not found in your business.']);
    exit();
}

$batchStmt = $conn->prepare("
    SELECT id, batch_number, quantity, expiry_date, status
    FROM product_batches
    WHERE product_id = ? AND business_id = ?
    ORDER BY (expiry_date IS NULL), expiry_date ASC, id ASC
");
$batchStmt->bind_param("ii", $productId, $businessId);
$batchStmt->execute();
$result = $batchStmt->get_result();

$batches = [];
while ($row = $result->fetch_assoc()) {
    $isExpired = !empty($row['expiry_date']) && strtotime($row['expiry_date']) < strtotime(date('Y-m-d'));
    $batches[] = [
        'id' => (int)$row['id'],
        'batch_number' => $row['batch_number'],
        'quantity' => formatQty($row['quantity']),
        'expiry_date' => $row['expiry_date'],
        'status' => $row['status'],
        'is_expired' => $isExpired,
        'can_drop' => $isExpired && $row['status'] === 'active' && (float)$row['quantity'] > 0,
    ];
}
$batchStmt->close();

echo json_encode([
    'success' => true,
    'product_name' => $product['product_name'],
    'batches' => $batches,
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
exit();
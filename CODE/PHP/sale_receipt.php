<?php
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

if (!isset($_SESSION['user_id']) || (int)($_SESSION['can_sales'] ?? 0) !== 1) {
    http_response_code(403);
    exit('Forbidden');
}

$saleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$saleId || $saleId < 1) {
    http_response_code(404);
    exit('Receipt not found.');
}

$businessId = nxRequireBusinessId($conn);
$saleStmt = $conn->prepare('SELECT id FROM sales WHERE id = ? AND business_id = ? LIMIT 1');
$saleStmt->bind_param('ii', $saleId, $businessId);
$saleStmt->execute();
if ($saleStmt->get_result()->num_rows === 0) {
    http_response_code(404);
    exit('Receipt not found.');
}
$saleStmt->close();

$receiptPath = nxSaleReceiptPath($saleId);
if ($receiptPath === null) {
    http_response_code(404);
    exit('Receipt not found.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $receiptPath) : false;
if ($finfo) {
    finfo_close($finfo);
}
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    exit('Unsupported receipt type.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($receiptPath));
header('Content-Disposition: inline; filename="receipt_' . $saleId . '.' . pathinfo($receiptPath, PATHINFO_EXTENSION) . '"');
readfile($receiptPath);

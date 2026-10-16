<?php
session_start();
require_once("config.php");
require_once("tenant_helper.php");

if (!isset($_SESSION['user_id'])) { header("Location: /NexGen/CODE/PHP/index.php"); exit(); }
if ((int)($_SESSION['can_inventory'] ?? 0) !== 1) {
    $_SESSION['inventory_error'] = 'You do not have access to Inventory Management.';
    header("Location: /NexGen/CODE/PHP/dashboard.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: /NexGen/CODE/PHP/inventory_management.php"); exit(); }
if (!validateCsrfToken('inventory_batch_drop', $_POST['csrf_token'] ?? null)) {
    $_SESSION['inventory_error'] = 'Your session expired. Please try again.';
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

$businessId = (int)nxRequireBusinessId($conn);
$userId = (int)$_SESSION['user_id'];
$batchId = (int)($_POST['batch_id'] ?? 0);

if ($batchId <= 0) {
    $_SESSION['inventory_error'] = 'Invalid batch.';
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

$conn->begin_transaction();
try {
    // Only a currently-expired, still-active batch may be dropped - never a
    // valid batch, and never a batch that was already dropped before.
    $lockStmt = $conn->prepare("
        SELECT pb.id, pb.product_id, pb.batch_number, pb.quantity, pb.expiry_date, pb.status, p.product_name
        FROM product_batches pb
        INNER JOIN products p ON p.id = pb.product_id AND p.business_id = pb.business_id
        WHERE pb.id = ? AND pb.business_id = ?
        FOR UPDATE
    ");
    if (!$lockStmt) { throw new Exception('Failed to prepare batch lock query.'); }
    $lockStmt->bind_param("ii", $batchId, $businessId);
    $lockStmt->execute();
    $batch = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    if (!$batch) { throw new Exception('Batch not found in your business.'); }
    if ($batch['status'] !== 'active') { throw new Exception('This batch has already been dropped.'); }
    if (empty($batch['expiry_date']) || strtotime($batch['expiry_date']) >= strtotime(date('Y-m-d'))) {
        throw new Exception('Only expired batches can be dropped. This batch is still valid.');
    }

    $droppedQty = (float)$batch['quantity'];

    $dropStmt = $conn->prepare("UPDATE product_batches SET quantity = 0, status = 'dropped' WHERE id = ? AND business_id = ?");
    if (!$dropStmt) { throw new Exception('Failed to prepare drop update.'); }
    $dropStmt->bind_param("ii", $batchId, $businessId);
    if (!$dropStmt->execute()) { throw new Exception('Failed to drop batch.'); }
    $dropStmt->close();

    if ($droppedQty > 0) {
        $remarks = "Expired batch dropped: {$batch['batch_number']} (expired " . htmlspecialchars_decode($batch['expiry_date']) . "). Reason: Expired/Disposal.";
        $moveStmt = $conn->prepare("
            INSERT INTO stock_movements (business_id, product_id, movement_type, quantity, remarks, created_by)
            VALUES (?, ?, 'stock_out', ?, ?, ?)
        ");
        if (!$moveStmt) { throw new Exception('Failed to prepare stock movement query.'); }
        $moveStmt->bind_param("iidsi", $businessId, $batch['product_id'], $droppedQty, $remarks, $userId);
        if (!$moveStmt->execute()) { throw new Exception('Failed to log stock movement.'); }
        $moveStmt->close();
    }

    $conn->commit();
    $_SESSION['inventory_success'] = "Dropped expired batch {$batch['batch_number']} for {$batch['product_name']} (" . formatQty($droppedQty) . " units).";
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['inventory_error'] = $e->getMessage();
}

header("Location: /NexGen/CODE/PHP/inventory_management.php");
exit();
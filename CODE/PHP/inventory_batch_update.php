<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/inventory_date_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /NexGen/CODE/PHP/index.php');
    exit();
}
if ((int)($_SESSION['can_inventory'] ?? 0) !== 1) {
    $_SESSION['inventory_error'] = 'You do not have access to Inventory Management.';
    header('Location: /NexGen/CODE/PHP/dashboard.php');
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /NexGen/CODE/PHP/inventory_management.php');
    exit();
}
if (!validateCsrfToken('inventory_batch_update', $_POST['csrf_token'] ?? null)) {
    $_SESSION['inventory_error'] = 'Your session expired. Please try again.';
    header('Location: /NexGen/CODE/PHP/inventory_management.php');
    exit();
}

$businessId = (int)nxRequireBusinessId($conn);
$batchId = (int)($_POST['batch_id'] ?? 0);
$expectedVersion = (int)($_POST['version'] ?? 0);

if ($batchId <= 0 || $expectedVersion <= 0) {
    $_SESSION['inventory_error'] = 'Invalid batch.';
    header('Location: /NexGen/CODE/PHP/inventory_management.php');
    exit();
}

$businessTypeStmt = $conn->prepare("
    SELECT b.business_type, be.expiry_date_required
    FROM businesses b
    INNER JOIN business_entities be ON be.id = b.business_entity_id
    WHERE b.id = ?
    LIMIT 1
");
$businessTypeStmt->bind_param('i', $businessId);
$businessTypeStmt->execute();
$businessSettings = $businessTypeStmt->get_result()->fetch_assoc() ?: [];
$businessTypeStmt->close();

if (!nxIsBatchTrackedType($businessSettings['business_type'] ?? null)) {
    $_SESSION['inventory_error'] = 'Batch dates can only be edited for batch-tracked products.';
    header('Location: /NexGen/CODE/PHP/inventory_management.php');
    exit();
}

$requiresExpiryDate = nxRequiresExpiryDate(
    $businessSettings['business_type'] ?? null,
    (int)($businessSettings['expiry_date_required'] ?? 0) === 1
);

try {
    $manufacturingDate = nxValidateManufacturingDate($_POST['manufacturing_date'] ?? '');
    if ($manufacturingDate === null) {
        throw new InvalidArgumentException('Manufacturing date is required for every batch.');
    }
    $expiryDate = nxValidateExpiryDate($_POST['expiry_date'] ?? '', $requiresExpiryDate);
    if ($expiryDate !== null && $manufacturingDate > $expiryDate) {
        throw new InvalidArgumentException('Manufacturing date cannot be after the expiry date.');
    }
} catch (InvalidArgumentException $exception) {
    $_SESSION['inventory_error'] = $exception->getMessage();
    header('Location: /NexGen/CODE/PHP/inventory_management.php');
    exit();
}

$conn->begin_transaction();
try {
    $batchStmt = $conn->prepare("
        SELECT pb.product_id, pb.batch_number, pb.status, p.product_name, p.version
        FROM product_batches pb
        INNER JOIN products p ON p.id = pb.product_id AND p.business_id = pb.business_id
        WHERE pb.id = ? AND pb.business_id = ?
        FOR UPDATE
    ");
    if (!$batchStmt) {
        throw new Exception('Failed to prepare batch lock query.');
    }
    $batchStmt->bind_param('ii', $batchId, $businessId);
    $batchStmt->execute();
    $batch = $batchStmt->get_result()->fetch_assoc();
    $batchStmt->close();

    if (!$batch) {
        throw new Exception('Batch not found in your business.');
    }
    if ((int)$batch['version'] !== $expectedVersion) {
        throw new RuntimeException('INVENTORY_CONFLICT');
    }
    if ($batch['status'] !== 'active') {
        throw new Exception('Dates can only be edited for active batches.');
    }

    $updateBatchStmt = $conn->prepare("
        UPDATE product_batches
        SET manufacturing_date = ?, expiry_date = ?
        WHERE id = ? AND product_id = ? AND business_id = ? AND status = 'active'
    ");
    if (!$updateBatchStmt) {
        throw new Exception('Failed to prepare batch date update.');
    }
    $updateBatchStmt->bind_param(
        'ssiii',
        $manufacturingDate,
        $expiryDate,
        $batchId,
        $batch['product_id'],
        $businessId
    );
    if (!$updateBatchStmt->execute()) {
        throw new Exception('Failed to update batch dates.');
    }
    $updateBatchStmt->close();

    $versionStmt = $conn->prepare("
        UPDATE products
        SET version = version + 1
        WHERE id = ? AND business_id = ? AND version = ?
    ");
    if (!$versionStmt) {
        throw new Exception('Failed to prepare product version update.');
    }
    $versionStmt->bind_param('iii', $batch['product_id'], $businessId, $expectedVersion);
    if (!$versionStmt->execute() || $versionStmt->affected_rows !== 1) {
        throw new RuntimeException('INVENTORY_CONFLICT');
    }
    $versionStmt->close();

    $conn->commit();
    $_SESSION['inventory_success'] = "Dates updated for batch {$batch['batch_number']} of {$batch['product_name']}.";
} catch (Throwable $exception) {
    $conn->rollback();
    if ($exception->getMessage() === 'INVENTORY_CONFLICT') {
        $_SESSION['inventory_conflict'] = true;
    } else {
        $_SESSION['inventory_error'] = $exception->getMessage();
    }
}

header('Location: /NexGen/CODE/PHP/inventory_management.php');
exit();

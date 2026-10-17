<?php
session_start();
require_once("config.php");
require_once("tenant_helper.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}
if ((int)($_SESSION['can_inventory'] ?? 0) !== 1) {
    $_SESSION['inventory_error'] = "You do not have access to Inventory Management.";
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}
if (!validateCsrfToken('inventory_delete_restore', $_POST['csrf_token'] ?? null)) {
    $_SESSION['inventory_error'] = 'Your session expired. Please try again.';
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

$businessId = nxRequireBusinessId($conn);
$id = (int)($_POST['id'] ?? 0);
$expectedVersion = (int)($_POST['version'] ?? 0);
if ($id <= 0 || $expectedVersion <= 0) {
    $_SESSION['inventory_error'] = "Invalid product.";
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

$conn->begin_transaction();
try {
    $productStmt = $conn->prepare(
        'SELECT is_active, version FROM products WHERE id = ? AND business_id = ? FOR UPDATE'
    );
    $productStmt->bind_param('ii', $id, $businessId);
    $productStmt->execute();
    $product = $productStmt->get_result()->fetch_assoc();
    $productStmt->close();

    if (!$product) {
        throw new RuntimeException('Product not found in your business.');
    }
    if ((int)$product['version'] !== $expectedVersion) {
        throw new RuntimeException('INVENTORY_CONFLICT');
    }
    if ((int)$product['is_active'] === 1) {
        throw new RuntimeException('Product is already active.');
    }

    $updateStmt = $conn->prepare(
        'UPDATE products SET is_active = 1, version = version + 1
         WHERE id = ? AND business_id = ? AND version = ?'
    );
    $updateStmt->bind_param('iii', $id, $businessId, $expectedVersion);
    $updateStmt->execute();
    if ($updateStmt->affected_rows !== 1) {
        throw new RuntimeException('INVENTORY_CONFLICT');
    }
    $updateStmt->close();
    $conn->commit();
    $_SESSION['inventory_success'] = "Product restored successfully.";
} catch (Throwable $e) {
    $conn->rollback();
    if ($e->getMessage() === 'INVENTORY_CONFLICT') {
        $_SESSION['inventory_conflict'] = true;
    } else {
        $_SESSION['inventory_error'] = $e->getMessage();
    }
}

header("Location: /NexGen/CODE/PHP/inventory_management.php");
exit();
?>

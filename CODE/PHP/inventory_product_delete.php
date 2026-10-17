<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . nxAppUrl('index.php'));
    exit();
}
if ((int)($_SESSION['can_inventory'] ?? 0) !== 1) {
    $_SESSION['inventory_error'] = 'You do not have access to Inventory Management.';
    header('Location: ' . nxAppUrl('inventory_management.php'));
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . nxAppUrl('inventory_management.php'));
    exit();
}
if (!validateCsrfToken('inventory_delete_restore', $_POST['csrf_token'] ?? null)) {
    $_SESSION['inventory_error'] = 'Your session expired. Please try again.';
    header('Location: ' . nxAppUrl('inventory_management.php'));
    exit();
}

$businessId = nxRequireBusinessId($conn);
$productId = (int)($_POST['id'] ?? 0);
$expectedVersion = (int)($_POST['version'] ?? 0);
if ($productId <= 0 || $expectedVersion <= 0) {
    $_SESSION['inventory_error'] = 'Invalid product.';
    header('Location: ' . nxAppUrl('inventory_management.php'));
    exit();
}

$conn->begin_transaction();
try {
    $productStmt = $conn->prepare(
        'SELECT product_name, is_active, stock_quantity, version
         FROM products WHERE id = ? AND business_id = ? FOR UPDATE'
    );
    $productStmt->bind_param('ii', $productId, $businessId);
    $productStmt->execute();
    $product = $productStmt->get_result()->fetch_assoc();
    $productStmt->close();

    if (!$product) {
        throw new RuntimeException('Product not found in your business.');
    }
    if ((int)$product['version'] !== $expectedVersion) {
        throw new RuntimeException('INVENTORY_CONFLICT');
    }
    if ((int)$product['is_active'] !== 0) {
        throw new RuntimeException('Only archived products can be permanently deleted.');
    }

    $batchStmt = $conn->prepare(
        "SELECT COALESCE(SUM(quantity), 0) AS quantity
         FROM product_batches
         WHERE product_id = ? AND business_id = ? AND status = 'active'"
    );
    $batchStmt->bind_param('ii', $productId, $businessId);
    $batchStmt->execute();
    $activeBatchQuantity = (float)($batchStmt->get_result()->fetch_assoc()['quantity'] ?? 0);
    $batchStmt->close();
    if ((float)$product['stock_quantity'] > 0 || $activeBatchQuantity > 0) {
        throw new RuntimeException('PRODUCT_HAS_STOCK');
    }

    $historyStmt = $conn->prepare(
        'SELECT
            (SELECT COUNT(*) FROM sale_items WHERE product_id = ?) AS sale_count,
            (SELECT COUNT(*) FROM stock_movements WHERE product_id = ? AND business_id = ?) AS movement_count'
    );
    $historyStmt->bind_param('iii', $productId, $productId, $businessId);
    $historyStmt->execute();
    $history = $historyStmt->get_result()->fetch_assoc();
    $historyStmt->close();
    if ((int)($history['sale_count'] ?? 0) > 0 || (int)($history['movement_count'] ?? 0) > 0) {
        throw new RuntimeException(
            'This product has sales or inventory history and cannot be permanently deleted. Archive it to preserve reports.'
        );
    }

    $referencesStmt = $conn->prepare(
        "SELECT TABLE_NAME, COLUMN_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
           AND REFERENCED_TABLE_NAME = 'products'
           AND REFERENCED_COLUMN_NAME = 'id'
           AND TABLE_NAME NOT IN ('product_batches', 'sale_items', 'stock_movements')"
    );
    $referencesStmt->execute();
    $references = $referencesStmt->get_result();
    while ($reference = $references->fetch_assoc()) {
        $tableName = str_replace('`', '``', (string)$reference['TABLE_NAME']);
        $columnName = str_replace('`', '``', (string)$reference['COLUMN_NAME']);
        $referenceStmt = $conn->prepare(
            "SELECT 1 FROM `{$tableName}` WHERE `{$columnName}` = ? LIMIT 1"
        );
        $referenceStmt->bind_param('i', $productId);
        $referenceStmt->execute();
        $hasReference = $referenceStmt->get_result()->num_rows > 0;
        $referenceStmt->close();
        if ($hasReference) {
            throw new RuntimeException(
                'This product has related records and cannot be permanently deleted. Archive it to preserve history.'
            );
        }
    }
    $referencesStmt->close();

    $batchDeleteStmt = $conn->prepare(
        'DELETE FROM product_batches WHERE product_id = ? AND business_id = ?'
    );
    $batchDeleteStmt->bind_param('ii', $productId, $businessId);
    $batchDeleteStmt->execute();
    $batchDeleteStmt->close();

    $deleteStmt = $conn->prepare(
        'DELETE FROM products WHERE id = ? AND business_id = ? AND version = ? AND is_active = 0'
    );
    $deleteStmt->bind_param('iii', $productId, $businessId, $expectedVersion);
    $deleteStmt->execute();
    if ($deleteStmt->affected_rows !== 1) {
        throw new RuntimeException('INVENTORY_CONFLICT');
    }
    $deleteStmt->close();

    $conn->commit();
    $_SESSION['inventory_success'] = 'Product permanently deleted.';
} catch (Throwable $e) {
    $conn->rollback();
    if ($e->getMessage() === 'INVENTORY_CONFLICT') {
        $_SESSION['inventory_conflict'] = true;
    } elseif ($e->getMessage() === 'PRODUCT_HAS_STOCK') {
        $_SESSION['inventory_warning'] = 'This product still has existing stock and cannot be deleted.';
    } else {
        $_SESSION['inventory_error'] = $e->getMessage();
    }
}

header('Location: ' . nxAppUrl('inventory_management.php'));
exit();
?>

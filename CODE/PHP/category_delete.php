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
if (!validateCsrfToken('inventory_category', $_POST['csrf_token'] ?? null)) {
    $_SESSION['inventory_error'] = 'Your session expired. Please try again.';
    header('Location: ' . nxAppUrl('inventory_management.php'));
    exit();
}

$businessId = nxRequireBusinessId($conn);
$categoryId = (int)($_POST['category_id'] ?? 0);
if ($categoryId <= 0) {
    $_SESSION['inventory_error'] = 'Invalid category.';
    header('Location: ' . nxAppUrl('inventory_management.php'));
    exit();
}

$conn->begin_transaction();
try {
    $categoryStmt = $conn->prepare(
        'SELECT id FROM categories WHERE id = ? AND business_id = ? FOR UPDATE'
    );
    $categoryStmt->bind_param('ii', $categoryId, $businessId);
    $categoryStmt->execute();
    $category = $categoryStmt->get_result()->fetch_assoc();
    $categoryStmt->close();
    if (!$category) {
        throw new RuntimeException('Category not found in your business.');
    }

    $productsStmt = $conn->prepare(
        'SELECT id FROM products WHERE category_id = ? AND business_id = ? LIMIT 1 FOR UPDATE'
    );
    $productsStmt->bind_param('ii', $categoryId, $businessId);
    $productsStmt->execute();
    $hasProducts = $productsStmt->get_result()->num_rows > 0;
    $productsStmt->close();
    if ($hasProducts) {
        throw new RuntimeException('CATEGORY_HAS_PRODUCTS');
    }

    $deleteStmt = $conn->prepare(
        'DELETE FROM categories WHERE id = ? AND business_id = ?'
    );
    $deleteStmt->bind_param('ii', $categoryId, $businessId);
    $deleteStmt->execute();
    if ($deleteStmt->affected_rows !== 1) {
        throw new RuntimeException('Category not found in your business.');
    }
    $deleteStmt->close();
    $conn->commit();
    $_SESSION['inventory_success'] = 'Category deleted successfully.';
} catch (Throwable $e) {
    $conn->rollback();
    if ($e->getMessage() === 'CATEGORY_HAS_PRODUCTS') {
        $_SESSION['inventory_warning'] = 'This category still has existing products and cannot be deleted. Move or delete the products first.';
    } else {
        $_SESSION['inventory_error'] = $e->getMessage();
    }
}

header('Location: ' . nxAppUrl('inventory_management.php'));
exit();
?>

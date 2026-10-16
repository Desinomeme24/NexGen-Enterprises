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
$id = intval($_POST['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['inventory_error'] = "Invalid product.";
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

$productStmt = $conn->prepare("
    SELECT id, product_name, is_active
    FROM products
    WHERE id = ? AND business_id = ?
    LIMIT 1
");
$productStmt->bind_param("ii", $id, $businessId);
$productStmt->execute();
$productResult = $productStmt->get_result();
$product = $productResult ? $productResult->fetch_assoc() : null;
$productStmt->close();

if (!$product) {
    $_SESSION['inventory_error'] = "Product not found.";
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

if ((int)$product['is_active'] === 0) {
    $_SESSION['inventory_success'] = "Product is already archived.";
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

$stmt = $conn->prepare("
    UPDATE products
    SET is_active = 0
    WHERE id = ? AND business_id = ?
");
$stmt->bind_param("ii", $id, $businessId);

if ($stmt->execute()) {
    $_SESSION['inventory_success'] = "Product archived successfully.";
} else {
    $_SESSION['inventory_error'] = "Failed to archive product.";
}

$stmt->close();

header("Location: /NexGen/CODE/PHP/inventory_management.php");
exit();
?>
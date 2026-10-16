<?php
session_start();
require_once("config.php");
require_once("tenant_helper.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}

$businessId = nxRequireBusinessId($conn);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=inventory_report_' . date('Y-m-d') . '.csv');

$output = fopen('php://output', 'w');

fputcsv($output, [
    'Product Code',
    'Product Name',
    'Category',
    'Brand',
    'Unit',
    'Cost Price',
    'Selling Price',
    'Stock Quantity',
    'Reorder Level',
    'Expiry Date',
    'Status',
    'Created At'
]);

$stmt = $conn->prepare("
    SELECT p.*, c.category_name
    FROM products p
    INNER JOIN categories c ON p.category_id = c.id AND c.business_id = p.business_id
    WHERE p.business_id = ?
    ORDER BY p.product_name ASC
");
$stmt->bind_param("i", $businessId);
$stmt->execute();
$query = $stmt->get_result();

while ($row = $query->fetch_assoc()) {
    fputcsv($output, [
        $row['product_code'],
        $row['product_name'],
        $row['category_name'],
        $row['brand'],
        $row['unit'],
        $row['cost_price'],
        $row['selling_price'],
        $row['stock_quantity'],
        $row['reorder_level'],
        $row['expiry_date'],
        $row['is_active'] ? 'Active' : 'Inactive',
        $row['created_at']
    ]);
}

fclose($output);
$stmt->close();
exit();
<?php
session_start();
require_once("config.php");
require_once("tenant_helper.php");



if (!isset($_SESSION['user_id'])) {
    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}

if ((int)($_SESSION['can_inventory'] ?? 0) !== 1) {
    $_SESSION['inventory_error'] = 'You do not have access to Inventory Management.';
    header("Location: /NexGen/CODE/PHP/dashboard.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

if (!validateCsrfToken('inventory_stock', $_POST['csrf_token'] ?? null)) {
    $_SESSION['inventory_error'] = 'Your session expired. Please try again.';
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

$role = $_SESSION['role'] ?? 'employee';
$isOwner = ($role === 'owner');
$user_id = (int)($_SESSION['user_id'] ?? 0);
$businessId = nxRequireBusinessId($conn);

$businessTypeStmt = $conn->prepare("SELECT business_type FROM businesses WHERE id = ? LIMIT 1");
$businessTypeStmt->bind_param("i", $businessId);
$businessTypeStmt->execute();
$businessType = $businessTypeStmt->get_result()->fetch_assoc()['business_type'] ?? null;
$businessTypeStmt->close();
$isBatchTracked = nxIsBatchTrackedType($businessType);

$product_id = intval($_POST['product_id'] ?? 0);

if ($product_id <= 0) {
    $_SESSION['inventory_error'] = "Invalid product.";
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

// "Place Order" is its own lightweight flow: it ONLY changes on_order_level
// and never touches stock_quantity or the stock_movements log, since no
// physical stock has moved yet. Owner-only, since placing a PO is a
// purchasing decision.
$placeOrder = $isOwner && isset($_POST['place_order']) && $_POST['place_order'] === '1';

if ($placeOrder) {
    $orderQty = max(0, (float)($_POST['on_order_add'] ?? 0));
    $orderRemarks = trim($_POST['remarks'] ?? '');

    if ($orderQty <= 0) {
        $_SESSION['inventory_error'] = "Order quantity must be greater than 0.";
        header("Location: /NexGen/CODE/PHP/inventory_management.php");
        exit();
    }

    try {
        $conn->begin_transaction();

        $productStmt = $conn->prepare("
            SELECT id, product_name, on_order_level
            FROM products
            WHERE id = ? AND business_id = ?
            FOR UPDATE
        ");

        if (!$productStmt) {
            throw new Exception("Failed to prepare product lock query.");
        }

        $productStmt->bind_param("ii", $product_id, $businessId);
        $productStmt->execute();
        $productResult = $productStmt->get_result();
        $product = $productResult->fetch_assoc();
        $productStmt->close();

        if (!$product) {
            throw new Exception("Invalid product.");
        }

        $productName = $product['product_name'] ?? 'Product';
        $newOnOrder = (float)($product['on_order_level'] ?? 0) + $orderQty;

        $updateStmt = $conn->prepare("
            UPDATE products
            SET on_order_level = ?
            WHERE id = ? AND business_id = ?
        ");

        if (!$updateStmt) {
            throw new Exception("Failed to prepare on-order update query.");
        }

        $updateStmt->bind_param("dii", $newOnOrder, $product_id, $businessId);

        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update on-order level.");
        }

        $updateStmt->close();

        $orderHistoryStmt = $conn->prepare("
            INSERT INTO stock_movements
                (business_id, product_id, movement_type, quantity, remarks, created_by, created_at)
            VALUES (?, ?, 'order_placed', ?, ?, ?, NOW())
        ");

        if (!$orderHistoryStmt) {
            throw new Exception("Failed to prepare purchase order history query.");
        }

        $orderHistoryStmt->bind_param(
            "iidsi",
            $businessId,
            $product_id,
            $orderQty,
            $orderRemarks,
            $user_id
        );

        if (!$orderHistoryStmt->execute()) {
            throw new Exception("Failed to save purchase order history.");
        }

        $orderHistoryStmt->close();
        $conn->commit();

        $_SESSION['inventory_success'] = "Order placed: " . formatQty($orderQty) . " unit(s) added to on-order for {$productName}.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['inventory_error'] = $e->getMessage();
    }

    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

// "Receive Shipment" is a dedicated flow: it always logs a stock-in and
// always matches the received quantity against on-order stock, regardless
// of who is logging it (employees routinely receive shipments).
$receiveShipment = isset($_POST['receive_shipment']) && $_POST['receive_shipment'] === '1';

$movement_type = $receiveShipment ? 'stock_in' : trim($_POST['movement_type'] ?? '');
$quantity = (float)($_POST['quantity'] ?? 0);
$remarks = trim($_POST['remarks'] ?? '');

// Placing a NEW purchase order is handled above (place_order branch), so by
// the time we get here, on_order_level can only move via deduction below.
$on_order_add = 0;

// Deducting from on-order (i.e. a shipment arrived) is routine receiving
// work, not a purchasing decision, so any logged-in user can do it.
$deduct_from_on_order = $receiveShipment || isset($_POST['deduct_from_on_order']);

if (!in_array($movement_type, ['stock_in', 'stock_out'], true)) {
    $_SESSION['inventory_error'] = "Invalid movement type.";
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

if ($quantity <= 0) {
    $_SESSION['inventory_error'] = "Quantity must be greater than 0.";
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

try {
    $conn->begin_transaction();

    
    $productStmt = $conn->prepare("
        SELECT id, product_name, stock_quantity, on_order_level, is_active
        FROM products
        WHERE id = ? AND business_id = ?
        FOR UPDATE
    ");

    if (!$productStmt) {
        throw new Exception("Failed to prepare product lock query.");
    }

    $productStmt->bind_param("ii", $product_id, $businessId);
    $productStmt->execute();
    $productResult = $productStmt->get_result();
    $product = $productResult->fetch_assoc();
    $productStmt->close();

    if (!$product) {
        throw new Exception("Invalid product.");
    }

    $currentStock = (float)($product['stock_quantity'] ?? 0);
    $currentOnOrder = (float)($product['on_order_level'] ?? 0);
    $productName = $product['product_name'] ?? 'Product';

    $newOnOrder = $currentOnOrder;

    if ($isBatchTracked) {
        // Stock lives in product_batches for this business type; the
        // product_batches triggers keep products.stock_quantity in sync
        // automatically, so stock itself is never written here directly.
        if ($movement_type === 'stock_in') {
            $batchNumber = nxGenerateBatchNumber();
            $batchExpiryDate = trim($_POST['batch_expiry_date'] ?? '');
            $batchExpiryParam = $batchExpiryDate === '' ? null : $batchExpiryDate;

            $newOnOrder += $on_order_add;

            if ($deduct_from_on_order) {
                $deductQty = min($quantity, $newOnOrder);
                $newOnOrder -= $deductQty;

                if ($receiveShipment && $quantity > $currentOnOrder) {
                    $overage = $quantity - $currentOnOrder;
                    $remarks = trim($remarks . " [Note: received " . formatQty($overage) . " more than was on order.]");
                }
            }

            $batchInsertStmt = $conn->prepare("
                INSERT INTO product_batches (business_id, product_id, batch_number, expiry_date, quantity)
                VALUES (?, ?, ?, ?, ?)
            ");

            if (!$batchInsertStmt) {
                throw new Exception("Failed to prepare batch insert query.");
            }

            $batchInsertStmt->bind_param("iissd", $businessId, $product_id, $batchNumber, $batchExpiryParam, $quantity);

            if (!$batchInsertStmt->execute()) {
                throw new Exception("Failed to save new batch.");
            }

            $batchInsertStmt->close();
        } else {
            // Stock Out here means a manual removal/write-off (damage, spoilage,
            // correction) - not a sale - so the expired-batch block never applies,
            // even for Pharmacy. Without this, an expired batch could never be
            // cleared out at all, since selling it is also blocked.
            nxDeductFefo($conn, $businessId, $product_id, $quantity, false);
        }

        if ($newOnOrder != $currentOnOrder) {
            $onOrderStmt = $conn->prepare("UPDATE products SET on_order_level = ? WHERE id = ? AND business_id = ?");
            if (!$onOrderStmt) {
                throw new Exception("Failed to prepare on-order update query.");
            }
            $onOrderStmt->bind_param("dii", $newOnOrder, $product_id, $businessId);
            if (!$onOrderStmt->execute()) {
                throw new Exception("Failed to update on-order level.");
            }
            $onOrderStmt->close();
        }
    } else {
        $newStock = $currentStock;

        if ($movement_type === 'stock_in') {
            $newStock += $quantity;
            $newOnOrder += $on_order_add;

            if ($deduct_from_on_order) {
                $deductQty = min($quantity, $newOnOrder);
                $newOnOrder -= $deductQty;

                // Supplier sent more than what was actually on order for this
                // product — still receive it into stock, but flag it in the
                // remarks instead of silently losing the discrepancy.
                if ($receiveShipment && $quantity > $currentOnOrder) {
                    $overage = $quantity - $currentOnOrder;
                    $remarks = trim($remarks . " [Note: received " . formatQty($overage) . " more than was on order.]");
                }
            }
        } else {
            if ($quantity > $currentStock) {
                throw new Exception("Not enough stock available for stock out.");
            }

            $newStock -= $quantity;
            $newOnOrder += $on_order_add;
        }

        $updateStmt = $conn->prepare("
            UPDATE products
            SET stock_quantity = ?, on_order_level = ?
            WHERE id = ? AND business_id = ?
        ");

        if (!$updateStmt) {
            throw new Exception("Failed to prepare stock update query.");
        }

        $updateStmt->bind_param("ddii", $newStock, $newOnOrder, $product_id, $businessId);

        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update product stock.");
        }

        $updateStmt->close();
    }

    $movementStmt = $conn->prepare("
        INSERT INTO stock_movements (business_id, product_id, movement_type, quantity, remarks, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    if (!$movementStmt) {
        throw new Exception("Failed to prepare stock movement history query.");
    }

    $movementStmt->bind_param("iisdsi", $businessId, $product_id, $movement_type, $quantity, $remarks, $user_id);

    if (!$movementStmt->execute()) {
        throw new Exception("Failed to save stock movement history.");
    }

    $movementStmt->close();

    $conn->commit();

    if ($receiveShipment) {
        $_SESSION['inventory_success'] = "Shipment received: " . formatQty($quantity) . " unit(s) added to stock for {$productName}.";
    } else {
        $_SESSION['inventory_success'] = "Stock movement saved successfully for {$productName}.";
    }

} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['inventory_error'] = $e->getMessage();
}

header("Location: /NexGen/CODE/PHP/inventory_management.php");
exit();
?>
<?php
session_start();
require_once("config.php");
require_once("tenant_helper.php");
require_once __DIR__ . '/inventory_date_helper.php';
require_once __DIR__ . '/inventory_quantity_helper.php';



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

$businessTypeStmt = $conn->prepare("
    SELECT b.business_type, be.expiry_date_required
    FROM businesses b
    INNER JOIN business_entities be ON be.id = b.business_entity_id
    WHERE b.id = ?
    LIMIT 1
");
$businessTypeStmt->bind_param("i", $businessId);
$businessTypeStmt->execute();
$businessSettings = $businessTypeStmt->get_result()->fetch_assoc() ?: [];
$businessType = $businessSettings['business_type'] ?? null;
$businessTypeStmt->close();
$isBatchTracked = nxIsBatchTrackedType($businessType);
$requiresExpiryDate = nxRequiresExpiryDate($businessType, (int)($businessSettings['expiry_date_required'] ?? 0) === 1);

$product_id = intval($_POST['product_id'] ?? 0);
$expectedVersion = (int)($_POST['version'] ?? 0);

if ($product_id <= 0 || $expectedVersion <= 0) {
    $_SESSION['inventory_error'] = "Invalid product.";
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

// "Place Order" changes on_order_level and records an order_placed history
// entry without changing physical stock. It remains owner-only.
$placeOrder = $isOwner && isset($_POST['place_order']) && $_POST['place_order'] === '1';

if ($placeOrder) {
    try {
        $orderQty = nxValidateWholeQuantity($_POST['on_order_add'] ?? null, 'Order quantity', false);
    } catch (InvalidArgumentException $exception) {
        $_SESSION['inventory_error'] = $exception->getMessage();
        header("Location: /NexGen/CODE/PHP/inventory_management.php");
        exit();
    }
    $orderRemarks = trim($_POST['remarks'] ?? '');

    try {
        $conn->begin_transaction();

        $productStmt = $conn->prepare("
            SELECT id, product_name, on_order_level, version
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
        if ((int)$product['version'] !== $expectedVersion) {
            throw new RuntimeException('INVENTORY_CONFLICT');
        }

        $productName = $product['product_name'] ?? 'Product';
        $newOnOrder = (float)($product['on_order_level'] ?? 0) + $orderQty;

        $updateStmt = $conn->prepare("
            UPDATE products
            SET on_order_level = ?, version = version + 1
            WHERE id = ? AND business_id = ? AND version = ?
        ");

        if (!$updateStmt) {
            throw new Exception("Failed to prepare on-order update query.");
        }

        $updateStmt->bind_param("diii", $newOnOrder, $product_id, $businessId, $expectedVersion);

        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update on-order level.");
        }
        if ($updateStmt->affected_rows !== 1) {
            throw new RuntimeException('INVENTORY_CONFLICT');
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
        if ($e->getMessage() === 'INVENTORY_CONFLICT') {
            $_SESSION['inventory_conflict'] = true;
        } else {
            $_SESSION['inventory_error'] = $e->getMessage();
        }
    }

    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

// "Receive Shipment" is a dedicated flow: it always logs a stock-in and
// always matches the received quantity against on-order stock, regardless
// of who is logging it (employees routinely receive shipments).
$receiveShipment = isset($_POST['receive_shipment']) && $_POST['receive_shipment'] === '1';

$movement_type = $receiveShipment ? 'stock_in' : trim($_POST['movement_type'] ?? '');
try {
    $quantity = nxValidateWholeQuantity($_POST['quantity'] ?? null, 'Quantity', false);
} catch (InvalidArgumentException $exception) {
    $_SESSION['inventory_error'] = $exception->getMessage();
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}
$remarks = trim($_POST['remarks'] ?? '');
$manufacturingDate = null;
$expiryDate = null;
if ($movement_type === 'stock_in') {
    try {
        $manufacturingDate = nxValidateManufacturingDate($_POST['manufacturing_date'] ?? '');
        if ($manufacturingDate === null) {
            throw new InvalidArgumentException('Manufacturing date is required for every stock-in.');
        }
        $expiryDateField = $isBatchTracked ? 'batch_expiry_date' : 'expiry_date';
        $expiryDate = nxValidateExpiryDate($_POST[$expiryDateField] ?? '', $requiresExpiryDate);
    } catch (InvalidArgumentException $exception) {
        $_SESSION['inventory_error'] = $exception->getMessage();
        header("Location: /NexGen/CODE/PHP/inventory_management.php");
        exit();
    }
    if ($manufacturingDate !== null && $expiryDate !== null && $manufacturingDate > $expiryDate) {
        $_SESSION['inventory_error'] = 'Manufacturing date cannot be after the expiry date.';
        header("Location: /NexGen/CODE/PHP/inventory_management.php");
        exit();
    }
}

if (!$isOwner && array_key_exists('on_order_add', $_POST)) {
    $_SESSION['inventory_error'] = 'Only the business owner can add on-order quantity.';
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}
try {
    $onOrderAddInput = $_POST['on_order_add'] ?? 0;
    if ($onOrderAddInput === '') {
        $onOrderAddInput = 0;
    }
    $on_order_add = $isOwner
        ? nxValidateWholeQuantity($onOrderAddInput, 'On-order quantity')
        : 0;
} catch (InvalidArgumentException $exception) {
    $_SESSION['inventory_error'] = $exception->getMessage();
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

// Deducting from on-order (i.e. a shipment arrived) is routine receiving
// work, not a purchasing decision, so any logged-in user can do it.
$deduct_from_on_order = $receiveShipment || isset($_POST['deduct_from_on_order']);

if (!in_array($movement_type, ['stock_in', 'stock_out'], true)) {
    $_SESSION['inventory_error'] = "Invalid movement type.";
    header("Location: /NexGen/CODE/PHP/inventory_management.php");
    exit();
}

try {
    $conn->begin_transaction();

    
    $productStmt = $conn->prepare("
        SELECT id, product_name, stock_quantity, on_order_level, expiry_date, is_active, version
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
    if ((int)$product['version'] !== $expectedVersion) {
        throw new RuntimeException('INVENTORY_CONFLICT');
    }

    $currentStock = (float)($product['stock_quantity'] ?? 0);
    $currentOnOrder = (float)($product['on_order_level'] ?? 0);
    $productName = $product['product_name'] ?? 'Product';

    $newOnOrder = $currentOnOrder + $on_order_add;

    if ($isBatchTracked) {
        // Stock lives in product_batches for this business type; the
        // product_batches triggers keep products.stock_quantity in sync
        // automatically, so stock itself is never written here directly.
        if ($movement_type === 'stock_in') {
            $batchNumber = nxGenerateBatchNumber();
            if ($deduct_from_on_order) {
                $deductQty = min($quantity, $newOnOrder);
                $newOnOrder -= $deductQty;

                if ($receiveShipment && $quantity > $currentOnOrder) {
                    $overage = $quantity - $currentOnOrder;
                    $remarks = trim($remarks . " [Note: received " . formatQty($overage) . " more than was on order.]");
                }
            }

            $batchInsertStmt = $conn->prepare("
                INSERT INTO product_batches (business_id, product_id, batch_number, manufacturing_date, expiry_date, quantity)
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            if (!$batchInsertStmt) {
                throw new Exception("Failed to prepare batch insert query.");
            }

            $batchInsertStmt->bind_param("iisssd", $businessId, $product_id, $batchNumber, $manufacturingDate, $expiryDate, $quantity);

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
        }

        if ($movement_type === 'stock_in') {
            $effectiveExpiryDate = $expiryDate ?? ($product['expiry_date'] ?? null);
            if ($manufacturingDate !== null && $effectiveExpiryDate !== null && $manufacturingDate > $effectiveExpiryDate) {
                throw new Exception('Manufacturing date cannot be after the expiry date.');
            }
            $updateStmt = $conn->prepare("
                UPDATE products
                SET stock_quantity = ?, on_order_level = ?, manufacturing_date = COALESCE(?, manufacturing_date),
                    expiry_date = COALESCE(?, expiry_date)
                WHERE id = ? AND business_id = ?
            ");
        } else {
            $updateStmt = $conn->prepare("
                UPDATE products
                SET stock_quantity = ?, on_order_level = ?
                WHERE id = ? AND business_id = ?
            ");
        }

        if (!$updateStmt) {
            throw new Exception("Failed to prepare stock update query.");
        }

        if ($movement_type === 'stock_in') {
            $updateStmt->bind_param("ddssii", $newStock, $newOnOrder, $manufacturingDate, $expiryDate, $product_id, $businessId);
        } else {
            $updateStmt->bind_param("ddii", $newStock, $newOnOrder, $product_id, $businessId);
        }

        if (!$updateStmt->execute()) {
            throw new Exception("Failed to update product stock.");
        }

        $updateStmt->close();
    }

    $versionStmt = $conn->prepare(
        'UPDATE products SET version = version + 1
         WHERE id = ? AND business_id = ? AND version = ?'
    );
    $versionStmt->bind_param('iii', $product_id, $businessId, $expectedVersion);
    $versionStmt->execute();
    if ($versionStmt->affected_rows !== 1) {
        throw new RuntimeException('INVENTORY_CONFLICT');
    }
    $versionStmt->close();

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

    if ($on_order_add > 0) {
        $orderHistoryStmt = $conn->prepare("
            INSERT INTO stock_movements (business_id, product_id, movement_type, quantity, remarks, created_by, created_at)
            VALUES (?, ?, 'order_placed', ?, ?, ?, NOW())
        ");
        if (!$orderHistoryStmt) {
            throw new Exception("Failed to prepare purchase order history query.");
        }
        $orderHistoryStmt->bind_param(
            "iidsi",
            $businessId,
            $product_id,
            $on_order_add,
            $remarks,
            $user_id
        );
        if (!$orderHistoryStmt->execute()) {
            throw new Exception("Failed to save purchase order history.");
        }
        $orderHistoryStmt->close();
    }

    $conn->commit();

    if ($receiveShipment) {
        $_SESSION['inventory_success'] = "Shipment received: " . formatQty($quantity) . " unit(s) added to stock for {$productName}.";
    } else {
        $_SESSION['inventory_success'] = "Stock movement saved successfully for {$productName}.";
    }
    if ($on_order_add > 0) {
        $_SESSION['inventory_success'] .= " " . formatQty($on_order_add) . " unit(s) added to on-order.";
    }

} catch (Exception $e) {
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
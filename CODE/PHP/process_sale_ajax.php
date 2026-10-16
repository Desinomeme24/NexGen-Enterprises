<?php
session_start();
header('Content-Type: application/json; charset=UTF-8');
require_once("config.php");
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/ar_helper.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Session expired. Please log in again.'
    ]);
    exit();
}

if ((int)($_SESSION['can_sales'] ?? 0) !== 1) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to save sales.'
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
    exit();
}

if (!validateCsrfToken('sale_form', $_POST['csrf_token'] ?? null)) {
    echo json_encode([
        'success' => false,
        'message' => 'Your session expired. Please refresh the page and try again.'
    ]);
    exit();
}

$user_id = (int) ($_SESSION['user_id'] ?? 0);
$arEnabled = nxArEnabled($conn, $user_id);
$activeWorkspace = nxInitializeUserWorkspace($conn);
$businessId = (int)($activeWorkspace['business_id'] ?? 0);
if ($businessId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Your account is not connected to an SME business.']);
    exit();
}

$businessTypeStmt = $conn->prepare("SELECT business_type FROM businesses WHERE id = ? LIMIT 1");
$businessTypeStmt->bind_param("i", $businessId);
$businessTypeStmt->execute();
$businessTypeRow = $businessTypeStmt->get_result()->fetch_assoc();
$businessTypeStmt->close();
$businessType = $businessTypeRow['business_type'] ?? null;
$isBatchTrackedBusiness = nxIsBatchTrackedType($businessType);
$blockExpiredBatches = nxBlocksExpiredBatches($businessType);

$sales_no = trim($_POST['sales_no'] ?? '');
$customer_id = (int) ($_POST['customer_id'] ?? 0);
$customerInput = [
    'customer_name' => trim((string)($_POST['customer_name'] ?? '')),
    'customer_phone' => trim((string)($_POST['customer_phone'] ?? '')),
    'customer_email' => trim((string)($_POST['customer_email'] ?? '')),
    'customer_address' => trim((string)($_POST['customer_address'] ?? '')),
];
$payment_status = trim($_POST['payment_status'] ?? '');
$payment_method = trim($_POST['payment_method'] ?? '');
$due_date = trim($_POST['due_date'] ?? '');
$amount_paid_input = (float) ($_POST['amount_paid'] ?? 0);
$receiptUpload = $_FILES['receipt_image'] ?? null;

$product_ids = $_POST['product_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$unit_prices = $_POST['unit_price'] ?? [];

$allowedPaymentStatus = ['Paid', 'Unpaid', 'Partially Paid'];
$allowedPaymentMethod = ['Cash', 'GCash', 'Gcash', 'Maya', 'Bank Transfer'];

if ($sales_no === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Sales number is required.'
    ]);
    exit();
}

if (!in_array($payment_status, $allowedPaymentStatus, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid payment status.'
    ]);
    exit();
}

if ($payment_status !== 'Paid' && !$arEnabled) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not have access to Accounts Receivable, so sales must be marked Paid.'
    ]);
    exit();
}

if (!$arEnabled) {
    $customer_id = 0;
    $customerInput = [];
    $due_date = '';
}

if (!in_array($payment_method, $allowedPaymentMethod, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid payment method.'
    ]);
    exit();
}

if (!is_array($product_ids) || !is_array($quantities) || !is_array($unit_prices) || count($product_ids) === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Please add at least one sale item.'
    ]);
    exit();
}

$receiptExtension = null;
if (is_array($receiptUpload) && (int)($receiptUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    [$receiptValid, $receiptMessage] = nxValidateSecureUpload($receiptUpload, [
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
        'mime_extension_map' => [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
        ],
        'max_size' => 5 * 1024 * 1024,
        'require_image' => true,
    ]);
    if (!$receiptValid) {
        echo json_encode(['success' => false, 'message' => $receiptMessage]);
        exit();
    }
    $receiptExtension = strtolower(pathinfo((string)$receiptUpload['name'], PATHINFO_EXTENSION));
}

if (count($product_ids) !== count($quantities) || count($product_ids) !== count($unit_prices)) {
    echo json_encode([
        'success' => false,
        'message' => 'Sale item data is incomplete or mismatched.'
    ]);
    exit();
}

if (
    in_array($payment_status, ['Unpaid', 'Partially Paid'], true)
    && $customer_id <= 0
    && $customerInput['customer_name'] === ''
) {
    echo json_encode([
        'success' => false,
        'message' => 'Customer is required for unpaid or partially paid sales.'
    ]);
    exit();
}

$maxRetries = 3;

for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {

$conn->begin_transaction();

try {
    $total_amount = 0;
    $items = [];
    $requiredByProduct = [];

    for ($i = 0; $i < count($product_ids); $i++) {
        $product_id = (int) ($product_ids[$i] ?? 0);
        $quantity = (float) ($quantities[$i] ?? 0);
        $unit_price = (float) ($unit_prices[$i] ?? 0);

        if ($product_id <= 0 || $quantity <= 0 || $unit_price < 0) {
            throw new Exception('Invalid product, quantity, or unit price detected.');
        }

        $items[] = [
            'product_id' => $product_id,
            'quantity' => $quantity,
            'unit_price' => $unit_price
        ];

        if (!isset($requiredByProduct[$product_id])) {
            $requiredByProduct[$product_id] = 0;
        }

        $requiredByProduct[$product_id] += $quantity;
    }

    ksort($requiredByProduct);

    $productCheckStmt = $conn->prepare("
        SELECT id, product_name, discount_percent, stock_quantity
        FROM products
        WHERE id = ? AND business_id = ? AND is_active = 1
        FOR UPDATE
    ");

    if (!$productCheckStmt) {
        throw new Exception('Failed to prepare product lock query.');
    }

    $productNames = [];
    $productDiscounts = [];

    foreach ($requiredByProduct as $product_id => $requiredQty) {
        $productCheckStmt->bind_param("ii", $product_id, $businessId);
        $productCheckStmt->execute();
        $productResult = $productCheckStmt->get_result();

        if ($productResult->num_rows === 0) {
            throw new Exception('One of the selected products does not exist or is inactive.');
        }

        $product = $productResult->fetch_assoc();
        $availableStock = (float) $product['stock_quantity'];
        $productNames[$product_id] = $product['product_name'];
        $productDiscounts[$product_id] = max(0, min(100, (float) $product['discount_percent']));

        if ($requiredQty > $availableStock) {
            throw new Exception('Insufficient stock for one of the selected products.');
        }

        if ($blockExpiredBatches) {
            $expiryCheckStmt = $conn->prepare("
                SELECT COUNT(*) AS batch_count, COALESCE(SUM(CASE WHEN expiry_date IS NULL OR expiry_date >= CURDATE() THEN quantity ELSE 0 END), 0) AS non_expired_qty
                FROM product_batches
                WHERE product_id = ? AND business_id = ? AND status = 'active'
            ");
            if (!$expiryCheckStmt) {
                throw new Exception('Failed to prepare batch expiry check.');
            }
            $expiryCheckStmt->bind_param("ii", $product_id, $businessId);
            $expiryCheckStmt->execute();
            $expiryCheckRow = $expiryCheckStmt->get_result()->fetch_assoc();
            $expiryCheckStmt->close();

            if ((int) $expiryCheckRow['batch_count'] > 0 && $requiredQty > (float) $expiryCheckRow['non_expired_qty']) {
                throw new Exception("{$product['product_name']} is expired and cannot be sold. Please remove it or check for a newer batch.");
            }
        }
    }

    $productCheckStmt->close();

    foreach ($items as $index => $item) {
        $discountPercent = $productDiscounts[$item['product_id']] ?? 0;
        $subtotal = $item['quantity'] * $item['unit_price'] * (1 - $discountPercent / 100);

        $items[$index]['product_name'] = $productNames[$item['product_id']] ?? 'Product';
        $items[$index]['discount_percent'] = $discountPercent;
        $items[$index]['subtotal'] = $subtotal;
        $total_amount += $subtotal;
    }

    if ($payment_status === 'Paid') {
        $amount_paid_input = $total_amount;
        $due_date = null;
        $order_status = 'Fulfilled';
    } elseif ($payment_status === 'Unpaid') {
        $amount_paid_input = 0;
        $order_status = 'Pending';

        if ($due_date === '') {
            throw new Exception('Due date is required for unpaid sales.');
        }
        $due_date = nxValidateReceivableDueDate($due_date);
    } else {
        $order_status = 'Pending';

        if ($amount_paid_input <= 0 || $amount_paid_input >= $total_amount) {
            throw new Exception('For partially paid sales, amount paid must be greater than 0 and less than the total amount.');
        }

        if ($due_date === '') {
            throw new Exception('Due date is required for partially paid sales.');
        }
        $due_date = nxValidateReceivableDueDate($due_date);
    }

    $requiresReceivable = in_array($payment_status, ['Unpaid', 'Partially Paid'], true);
    $customerParam = nxResolveSaleCustomer(
        $conn,
        $businessId,
        $customer_id,
        $customerInput,
        $requiresReceivable
    );

    $saleStmt = $conn->prepare("
        INSERT INTO sales (
            business_id,
            sales_no,
            salesperson_id,
            customer_id,
            total_amount,
            payment_status,
            payment_method,
            order_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$saleStmt) {
        throw new Exception('Failed to prepare sales insert query.');
    }

    $saleStmt->bind_param(
        "isiidsss",
        $businessId,
        $sales_no,
        $user_id,
        $customerParam,
        $total_amount,
        $payment_status,
        $payment_method,
        $order_status
    );

    if (!$saleStmt->execute()) {
        throw new Exception('Failed to save sale record.');
    }

    $sale_id = $conn->insert_id;
    $saleStmt->close();

    $receiptPath = null;
    if ($receiptExtension !== null) {
        $receiptDirectory = nxSaleReceiptDirectory();
        if (!is_dir($receiptDirectory) && !mkdir($receiptDirectory, 0750, true) && !is_dir($receiptDirectory)) {
            throw new Exception('Unable to prepare receipt storage.');
        }
        $receiptPath = $receiptDirectory . DIRECTORY_SEPARATOR . 'receipt_' . $sale_id . '.' . $receiptExtension;
        if (!move_uploaded_file($receiptUpload['tmp_name'], $receiptPath)) {
            throw new Exception('Unable to save the receipt image.');
        }
    }

    foreach ($items as $item) {
        $itemStmt = $conn->prepare("
            INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, discount_percent, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        if (!$itemStmt) {
            throw new Exception('Failed to prepare sale items insert query.');
        }

        $itemStmt->bind_param(
            "iidddd",
            $sale_id,
            $item['product_id'],
            $item['quantity'],
            $item['unit_price'],
            $item['discount_percent'],
            $item['subtotal']
        );

        if (!$itemStmt->execute()) {
            throw new Exception('Failed to save sale item.');
        }

        $itemStmt->close();

        if ($isBatchTrackedBusiness) {
            // FEFO consumption; this also keeps products.stock_quantity in
            // sync via the product_batches triggers, so no direct UPDATE here.
            nxDeductFefo($conn, $businessId, $item['product_id'], $item['quantity'], $blockExpiredBatches);
        } else {
            $stockStmt = $conn->prepare("
                UPDATE products
                SET stock_quantity = stock_quantity - ?
                WHERE id = ? AND business_id = ?
            ");

            if (!$stockStmt) {
                throw new Exception('Failed to prepare stock update query.');
            }

            $stockStmt->bind_param("dii", $item['quantity'], $item['product_id'], $businessId);

            if (!$stockStmt->execute()) {
                throw new Exception('Failed to deduct product stock.');
            }

            $stockStmt->close();
        }

        $remarks = "Sale recorded: " . $sales_no;

        $movementStmt = $conn->prepare("
            INSERT INTO stock_movements (business_id, product_id, movement_type, quantity, remarks, created_by)
            VALUES (?, ?, 'stock_out', ?, ?, ?)
        ");

        if (!$movementStmt) {
            throw new Exception('Failed to prepare stock movement query.');
        }

        $movementStmt->bind_param(
            "iidsi",
            $businessId,
            $item['product_id'],
            $item['quantity'],
            $remarks,
            $user_id
        );

        if (!$movementStmt->execute()) {
            throw new Exception('Failed to save stock movement history.');
        }

        $movementStmt->close();
    }

    if ($payment_status === 'Unpaid' || $payment_status === 'Partially Paid') {
        $amount_paid = max(0, min($amount_paid_input, $total_amount));
        $balance_due = max(0, $total_amount - $amount_paid);

        if ($amount_paid <= 0) {
            $receivableStatus = 'Unpaid';
        } elseif ($amount_paid < $total_amount) {
            $receivableStatus = 'Partially Paid';
        } else {
            $receivableStatus = 'Paid';
        }

        $arStmt = $conn->prepare("
            INSERT INTO accounts_receivable
            (business_id, sale_id, customer_id, total_amount, amount_paid, balance_due, due_date, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$arStmt) {
            throw new Exception('Failed to prepare accounts receivable insert query.');
        }

        $arStmt->bind_param(
            "iiidddssi",
            $businessId,
            $sale_id,
            $customerParam,
            $total_amount,
            $amount_paid,
            $balance_due,
            $due_date,
            $receivableStatus,
            $user_id
        );

        if (!$arStmt->execute()) {
            throw new Exception('Failed to save accounts receivable record.');
        }

        $arStmt->close();
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Sale recorded successfully.',
        'sale_id' => $sale_id
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit();

    } catch (Exception $e) {
        $conn->rollback();
        if (isset($receiptPath) && $receiptPath !== null && is_file($receiptPath)) {
            unlink($receiptPath);
        }

        // Retry on deadlock (MySQL error 1213), otherwise fail immediately
        if ($conn->errno === 1213 && $attempt < $maxRetries) {
            usleep(100000 * $attempt); // wait 100ms, 200ms before retrying
            continue;
        }

        error_log('process_sale_ajax.php error: ' . $e->getMessage());

        echo json_encode([
            'success' => false,
            'message' => $e->getMessage() ?: 'Unable to save the sale. Please check the form and try again.'
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        exit();
    }

} // end retry loop
?>

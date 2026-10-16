<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}

if ((int)($_SESSION['can_sales'] ?? 0) !== 1) {
    $_SESSION['error'] = 'You do not have access to Sales.';
    header("Location: /NexGen/CODE/PHP/dashboard.php");
    exit();
}

include 'config.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/ar_helper.php';
$businessId = nxRequireBusinessId($conn);



if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
    exit();
}

if (!validateCsrfToken('sale_form', $_POST['csrf_token'] ?? null)) {
    $_SESSION['error'] = 'Your session expired. Please try again.';
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$arEnabled = nxArEnabled($conn, $user_id);
$sales_no = trim($_POST['sales_no'] ?? '');
$customer_id = (int)($_POST['customer_id'] ?? 0);
$customerInput = [
    'customer_name' => trim((string)($_POST['customer_name'] ?? '')),
    'customer_phone' => trim((string)($_POST['customer_phone'] ?? '')),
    'customer_email' => trim((string)($_POST['customer_email'] ?? '')),
    'customer_address' => trim((string)($_POST['customer_address'] ?? '')),
];
$payment_status = trim($_POST['payment_status'] ?? '');
$payment_method = trim($_POST['payment_method'] ?? '');
$order_status = trim($_POST['order_status'] ?? '');
$due_date = trim($_POST['due_date'] ?? '');
$amount_paid_input = (float)($_POST['amount_paid'] ?? 0);
$receiptUpload = $_FILES['receipt_image'] ?? null;

$product_ids = $_POST['product_id'] ?? [];
$quantities = $_POST['quantity'] ?? [];
$unit_prices = $_POST['unit_price'] ?? [];

$allowedPaymentStatus = ['Paid', 'Unpaid', 'Partially Paid'];
$allowedPaymentMethod = ['Cash', 'GCash', 'Gcash', 'Maya', 'Bank Transfer'];
$allowedOrderStatus = ['Fulfilled', 'Pending'];

if ($sales_no === '' || empty($product_ids)) {
    $_SESSION['error'] = 'Invalid sale data.';
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
    exit();
}

if (!in_array($payment_status, $allowedPaymentStatus, true)) {
    $_SESSION['error'] = 'Invalid payment status.';
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
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
        $_SESSION['error'] = $receiptMessage;
        header("Location: /NexGen/CODE/PHP/sales_recording.php");
        exit();
    }
    $receiptExtension = strtolower(pathinfo((string)$receiptUpload['name'], PATHINFO_EXTENSION));
}

if ($payment_status !== 'Paid' && !$arEnabled) {
    $_SESSION['error'] = 'You do not have access to Accounts Receivable, so sales must be marked Paid.';
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
    exit();
}

if (!$arEnabled) {
    $customer_id = 0;
    $customerInput = [];
    $due_date = '';
}

if (!in_array($payment_method, $allowedPaymentMethod, true)) {
    $_SESSION['error'] = 'Invalid payment method.';
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
    exit();
}

if (
    ($payment_status === 'Unpaid' || $payment_status === 'Partially Paid')
    && $customer_id <= 0
    && $customerInput['customer_name'] === ''
) {
    $_SESSION['error'] = 'Customer is required for unpaid or partially paid sales.';
    header("Location: /NexGen/CODE/PHP/sales_recording.php");
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
        $product_id = (int)($product_ids[$i] ?? 0);
        $qty = (float)($quantities[$i] ?? 0);
        $price = (float)($unit_prices[$i] ?? 0);

        if ($product_id <= 0 || $qty <= 0 || $price < 0) {
            throw new Exception("Invalid product, quantity, or unit price.");
        }

        $subtotal = $qty * $price;
        $total_amount += $subtotal;

        $items[] = [
            'product_id' => $product_id,
            'quantity' => $qty,
            'unit_price' => $price,
            'subtotal' => $subtotal
        ];

        if (!isset($requiredByProduct[$product_id])) {
            $requiredByProduct[$product_id] = 0;
        }

        $requiredByProduct[$product_id] += $qty;
    }


    ksort($requiredByProduct);

    $checkStmt = $conn->prepare("
        SELECT id, product_name, stock_quantity
        FROM products
        WHERE id = ? AND business_id = ?
        FOR UPDATE
    ");

    if (!$checkStmt) {
        throw new Exception("Failed to prepare product lock query.");
    }

    foreach ($requiredByProduct as $product_id => $requiredQty) {
        $checkStmt->bind_param("ii", $product_id, $businessId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows === 0) {
            throw new Exception("Product not found.");
        }

        $product = $checkResult->fetch_assoc();

        if ((float)$product['stock_quantity'] < $requiredQty) {
            throw new Exception("Not enough stock for " . $product['product_name']);
        }
    }

    $checkStmt->close();

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
    } elseif ($payment_status === 'Partially Paid') {
        $order_status = 'Pending';

        if ($amount_paid_input <= 0 || $amount_paid_input >= $total_amount) {
            throw new Exception('For partially paid sales, amount paid must be greater than 0 and less than the total amount.');
        }

        if ($due_date === '') {
            throw new Exception('Due date is required for partially paid sales.');
        }
        $due_date = nxValidateReceivableDueDate($due_date);
    }

    if (!in_array($order_status, $allowedOrderStatus, true)) {
        throw new Exception('Invalid order status.');
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
            business_id, sales_no, salesperson_id, customer_id, total_amount, payment_status, payment_method, order_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$saleStmt) {
        throw new Exception("Failed to prepare sales insert query.");
    }

    $saleStmt->bind_param("isiidsss", $businessId, $sales_no, $user_id, $customerParam, $total_amount, $payment_status, $payment_method, $order_status);

    if (!$saleStmt->execute()) {
        throw new Exception("Failed to save sale record.");
    }

    $sale_id = $conn->insert_id;
    $saleStmt->close();

    $receiptPath = null;
    if ($receiptExtension !== null) {
        $receiptDirectory = nxSaleReceiptDirectory();
        if (!is_dir($receiptDirectory) && !mkdir($receiptDirectory, 0750, true) && !is_dir($receiptDirectory)) {
            throw new Exception("Unable to prepare receipt storage.");
        }
        $receiptPath = $receiptDirectory . DIRECTORY_SEPARATOR . 'receipt_' . $sale_id . '.' . $receiptExtension;
        if (!move_uploaded_file($receiptUpload['tmp_name'], $receiptPath)) {
            throw new Exception("Unable to save the receipt image.");
        }
    }

    foreach ($items as $item) {
        $itemStmt = $conn->prepare("
            INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal)
            VALUES (?, ?, ?, ?, ?)
        ");

        if (!$itemStmt) {
            throw new Exception("Failed to prepare sale item query.");
        }

        $itemStmt->bind_param("iiddd", $sale_id, $item['product_id'], $item['quantity'], $item['unit_price'], $item['subtotal']);

        if (!$itemStmt->execute()) {
            throw new Exception("Failed to save sale item.");
        }

        $itemStmt->close();

        $updateStock = $conn->prepare("
            UPDATE products
            SET stock_quantity = stock_quantity - ?
            WHERE id = ? AND business_id = ?
        ");

        if (!$updateStock) {
            throw new Exception("Failed to prepare stock update query.");
        }

        $updateStock->bind_param("dii", $item['quantity'], $item['product_id'], $businessId);

        if (!$updateStock->execute()) {
            throw new Exception("Failed to deduct stock.");
        }

        $updateStock->close();

        $remarks = "Sale recorded: " . $sales_no;

        $stockStmt = $conn->prepare("
            INSERT INTO stock_movements (business_id, product_id, movement_type, quantity, remarks, created_by)
            VALUES (?, ?, 'stock_out', ?, ?, ?)
        ");

        if (!$stockStmt) {
            throw new Exception("Failed to prepare stock movement query.");
        }

        $stockStmt->bind_param("iidsi", $businessId, $item['product_id'], $item['quantity'], $remarks, $user_id);

        if (!$stockStmt->execute()) {
            throw new Exception("Failed to save stock movement.");
        }

        $stockStmt->close();
    }

    if ($payment_status === 'Unpaid' || $payment_status === 'Partially Paid') {
        $amount_paid = max(0, min($amount_paid_input, $total_amount));
        $balance_due = max(0, $total_amount - $amount_paid);

        $receivableStatus = 'Unpaid';

        if ($amount_paid > 0 && $balance_due > 0) {
            $receivableStatus = 'Partially Paid';
        } elseif ($balance_due <= 0) {
            $receivableStatus = 'Paid';
        }

        $stmtAr = $conn->prepare("
            INSERT INTO accounts_receivable (
                business_id, sale_id, customer_id, total_amount, amount_paid, balance_due, due_date, status, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        if (!$stmtAr) {
            throw new Exception("Failed to prepare accounts receivable query.");
        }

        $stmtAr->bind_param(
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

        if (!$stmtAr->execute()) {
            throw new Exception("Failed to save accounts receivable record.");
        }

        $stmtAr->close();
    }

    $conn->commit();

    $_SESSION['success'] = 'Sale recorded successfully.';
    header("Location: sale_view.php?id=" . $sale_id);
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

        $_SESSION['error'] = "Error saving sale: " . $e->getMessage();
        header("Location: /NexGen/CODE/PHP/sales_recording.php");
        exit();
    }

} // end retry loop
?>

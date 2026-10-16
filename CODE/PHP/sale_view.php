<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}

include 'config.php';
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/ar_helper.php';
$businessId = nxRequireBusinessId($conn);
$arEnabled = nxArEnabled();

if ((int)($_SESSION['can_sales'] ?? 0) !== 1) {
    $_SESSION['error'] = 'You do not have access to Sales.';
    header("Location: /NexGen/CODE/PHP/dashboard.php");
    exit();
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid sale ID.");
}

$sale_id = (int) $_GET['id'];

// Preserve Sales Recording filter/navigation state.
$salesReturnParams = [
    'filter'     => isset($_GET['filter']) ? trim($_GET['filter']) : '',
    'search'     => isset($_GET['search']) ? trim($_GET['search']) : '',
    'period'     => isset($_GET['period']) ? trim($_GET['period']) : '',
    'ref_date'   => isset($_GET['ref_date']) ? trim($_GET['ref_date']) : '',
    'date_from'  => isset($_GET['date_from']) ? trim($_GET['date_from']) : '',
    'date_to'    => isset($_GET['date_to']) ? trim($_GET['date_to']) : '',
    'cashier_id' => isset($_GET['cashier_id']) ? (int)$_GET['cashier_id'] : 0,
];

function buildSalesRecordingUrl(array $params): string
{
    $params = array_filter($params, static function ($value) {
        return $value !== '' && $value !== null && $value !== 0;
    });

    return 'sales_recording.php' .
        (!empty($params) ? '?' . http_build_query($params) : '');
}

function buildSaleViewUrl(int $saleId, array $params = []): string
{
    $query = array_merge(['id' => $saleId], $params);

    $query = array_filter($query, static function ($value, $key) {
        if ($key === 'id') {
            return true;
        }

        return $value !== '' && $value !== null && $value !== 0;
    }, ARRAY_FILTER_USE_BOTH);

    return 'sale_view.php?' . http_build_query($query);
}

function badgeClassPayment($status) {
    return match($status) {
        'Paid' => 'badge paid',
        'Unpaid' => 'badge unpaid',
        'Partially Paid' => 'badge partial',
        default => 'badge'
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {
    if (!$arEnabled) {
        $_SESSION['error'] = 'You do not have access to Accounts Receivable.';
        header("Location: " . buildSaleViewUrl($sale_id, $salesReturnParams));
        exit();
    }

    if (!validateCsrfToken('sale_view_payment_form', $_POST['csrf_token'] ?? null)) {
        $_SESSION['error'] = 'Your session expired. Please try again.';
        header("Location: " . buildSaleViewUrl($sale_id, $salesReturnParams));
        exit();
    }

    $additional_payment = (float)($_POST['additional_payment'] ?? 0);
    $due_date = trim($_POST['due_date'] ?? '');

    if ($additional_payment <= 0) {
        $_SESSION['error'] = 'Please enter a valid payment amount.';
        header("Location: " . buildSaleViewUrl($sale_id, $salesReturnParams));
        exit();
    }

    $conn->begin_transaction();

    try {
        $saleCheckStmt = $conn->prepare("
            SELECT id, customer_id, total_amount, payment_status, order_status
            FROM sales
            WHERE id = ? AND business_id = ?
            LIMIT 1
        ");
        $saleCheckStmt->bind_param("ii", $sale_id, $businessId);
        $saleCheckStmt->execute();
        $saleCheckResult = $saleCheckStmt->get_result();

        if ($saleCheckResult->num_rows === 0) {
            throw new Exception('Sale not found.');
        }

        $saleRow = $saleCheckResult->fetch_assoc();
        $saleCheckStmt->close();

        if ($saleRow['payment_status'] === 'Paid') {
            throw new Exception('This sale is already fully paid.');
        }

        $arStmt = $conn->prepare("
            SELECT id, total_amount, amount_paid, balance_due, due_date, status
            FROM accounts_receivable
            WHERE sale_id = ? AND business_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $arStmt->bind_param("ii", $sale_id, $businessId);
        $arStmt->execute();
        $arResult = $arStmt->get_result();

        if ($arResult->num_rows === 0) {
            throw new Exception('No receivable record found for this sale.');
        }

        $receivable = $arResult->fetch_assoc();
        $arStmt->close();

        $currentAmountPaid = (float)$receivable['amount_paid'];
        $totalAmount = (float)$receivable['total_amount'];
        $newAmountPaid = $currentAmountPaid + $additional_payment;

        if ($newAmountPaid < 0) {
            $newAmountPaid = 0;
        }

        if ($newAmountPaid > $totalAmount) {
            $newAmountPaid = $totalAmount;
        }

        $newBalance = max(0, $totalAmount - $newAmountPaid);

        if ($newAmountPaid <= 0) {
            $newPaymentStatus = 'Unpaid';
            $newOrderStatus = 'Pending';
            $newArStatus = 'Unpaid';
        } elseif ($newAmountPaid < $totalAmount) {
            $newPaymentStatus = 'Partially Paid';
            $newOrderStatus = 'Pending';
            $newArStatus = 'Partially Paid';
        } else {
            $newPaymentStatus = 'Paid';
            $newOrderStatus = 'Fulfilled';
            $newArStatus = 'Paid';
            $due_date = '';
        }

        $finalDueDate = $receivable['due_date'];
        if ($newPaymentStatus !== 'Paid' && $due_date !== '') {
            $finalDueDate = $due_date;
        }
        if ($newPaymentStatus === 'Paid') {
            $finalDueDate = null;
        }

        $updateAr = $conn->prepare("
            UPDATE accounts_receivable
            SET amount_paid = ?, balance_due = ?, due_date = ?, status = ?
            WHERE id = ? AND business_id = ?
        ");
        $updateAr->bind_param(
            "ddssii",
            $newAmountPaid,
            $newBalance,
            $finalDueDate,
            $newArStatus,
            $receivable['id'],
            $businessId
        );
        $updateAr->execute();
        $updateAr->close();

        $updateSale = $conn->prepare("
            UPDATE sales
            SET payment_status = ?, order_status = ?
            WHERE id = ? AND business_id = ?
        ");
        $updateSale->bind_param("ssii", $newPaymentStatus, $newOrderStatus, $sale_id, $businessId);
        $updateSale->execute();
        $updateSale->close();

        $conn->commit();
        $_SESSION['success'] = 'Payment updated successfully.';
        header("Location: " . buildSaleViewUrl($sale_id, $salesReturnParams));
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = $e->getMessage();
        header("Location: " . buildSaleViewUrl($sale_id, $salesReturnParams));
        exit();
    }
}

$saleStmt = $conn->prepare("
    SELECT 
        s.*,
        u.full_name AS salesperson
    FROM sales s
    LEFT JOIN users u ON s.salesperson_id = u.id
    WHERE s.id = ? AND s.business_id = ?
");
$saleStmt->bind_param("ii", $sale_id, $businessId);
$saleStmt->execute();
$saleResult = $saleStmt->get_result();

if ($saleResult->num_rows === 0) {
    die("Sale not found.");
}

$sale = $saleResult->fetch_assoc();
$receiptPath = nxSaleReceiptPath($sale_id);

/* Customer details are sensitive AR data. Only retrieve them for a user who
   currently has AR access, and always keep the lookup inside the active
   business so a malformed customer_id cannot expose another tenant's record. */
$customerInfo = null;
if ($arEnabled && (int)($sale['customer_id'] ?? 0) > 0) {
    $customerStmt = $conn->prepare("
        SELECT customer_code, customer_name, phone, email, address
        FROM customers
        WHERE id = ? AND business_id = ?
        LIMIT 1
    ");
    $customerId = (int)$sale['customer_id'];
    $customerStmt->bind_param("ii", $customerId, $businessId);
    $customerStmt->execute();
    $customerResult = $customerStmt->get_result();

    if ($customerResult->num_rows > 0) {
        $customerInfo = $customerResult->fetch_assoc();
    }

    $customerStmt->close();
}

$itemStmt = $conn->prepare("
    SELECT 
        si.quantity,
        si.unit_price,
        si.subtotal,
        p.product_name
    FROM sale_items si
    LEFT JOIN products p ON si.product_id = p.id
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");
$itemStmt->bind_param("i", $sale_id);
$itemStmt->execute();
$itemResult = $itemStmt->get_result();

$arInfo = null;
$arStmt = $conn->prepare("
    SELECT id, total_amount, amount_paid, balance_due, due_date, status
    FROM accounts_receivable
    WHERE sale_id = ? AND business_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$arStmt->bind_param("ii", $sale_id, $businessId);
$arStmt->execute();
$arResult = $arStmt->get_result();
if ($arResult->num_rows > 0) {
    $arInfo = $arResult->fetch_assoc();
}
$arStmt->close();

$popupMessage = $_SESSION['success'] ?? $_SESSION['error'] ?? '';
$popupType = isset($_SESSION['success']) ? 'success' : (isset($_SESSION['error']) ? 'error' : '');
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <?php include __DIR__ . '/theme_init.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Sale</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            color: #eef5ff;
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246,0.14), transparent 25%),
                radial-gradient(circle at top right, rgba(255, 255, 255,0.05), transparent 18%),
                linear-gradient(180deg, #050b24 0%, #0a1550 45%, #02040f 100%);
            min-height: 100vh;
            padding: 34px;
        }

        .page-shell {
            max-width: 1100px;
            margin: 0 auto;
            background: rgba(10, 21, 80, 0.58);
            border: 1px solid rgba(255, 255, 255,0.07);
            border-radius: 26px;
            overflow: hidden;
            box-shadow: 0 18px 55px rgba(0, 0, 0,0.35);
        }

        .header {
            padding: 20px 24px;
            border-bottom: 1px solid rgba(255, 255, 255,0.08);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .menu-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: linear-gradient(180deg, #3b82f6, #0033ff);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .header-title {
            font-size: 24px;
            font-weight: 700;
        }

        .content {
            padding: 24px;
        }

        .card {
            background: rgba(255, 255, 255,0.035);
            border: 1px solid rgba(255, 255, 255,0.07);
            border-radius: 18px;
            padding: 20px;
            margin-bottom: 18px;
        }

        .section-title {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 16px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .info-box {
            background: rgba(255, 255, 255,0.04);
            border: 1px solid rgba(255, 255, 255,0.06);
            border-radius: 14px;
            padding: 16px;
        }

        .info-box small {
            display: block;
            color: #dbeafe;
            margin-bottom: 6px;
        }

        .info-box strong {
            font-size: 18px;
            color: #ffffff;
        }

        .customer-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .customer-box {
            min-width: 0;
            position: relative;
            overflow: hidden;
            padding: 15px 16px 15px 19px;
            border: 1px solid rgba(143, 201, 255, 0.18);
            border-radius: 14px;
            background: linear-gradient(155deg, rgba(143, 201, 255, 0.09), rgba(255, 255, 255, 0.035));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
        }

        .customer-box::before {
            content: "";
            position: absolute;
            top: 10px;
            bottom: 10px;
            left: 0;
            width: 4px;
            border-radius: 0 4px 4px 0;
            background: linear-gradient(180deg, #ffdd55, #3b82f6);
        }

        .customer-box small,
        .customer-box strong {
            display: block;
        }

        .customer-box small {
            margin-bottom: 5px;
            color: #b9d4f5;
            font-size: 12px;
            font-weight: 700;
        }

        .customer-box strong {
            color: #ffffff;
            font-size: 16px;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }

        .customer-address-box {
            grid-column: 1 / -1;
        }

        .receivable-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
            margin-top: 12px;
        }

        .receivable-box {
            background: rgba(59, 130, 246,0.08);
            border: 1px solid rgba(59, 130, 246,0.22);
            border-radius: 14px;
            padding: 16px;
        }

        .receivable-box small {
            display: block;
            color: #dbeafe;
            margin-bottom: 6px;
        }

        .receivable-box strong {
            font-size: 18px;
            color: #ffffff;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            overflow: hidden;
        }

        th, td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255,0.08);
        }

        th {
            background: rgba(255, 255, 255,0.04);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            min-width: 100px;
        }

        .paid {
            background: rgba(94, 234, 212, 0.27);
            color: #a7f3d0;
        }

        .unpaid {
            background: rgba(255, 85, 119, 0.25);
            color: #fecdd3;
        }

        .partial {
            background: rgba(255, 221, 85, 0.25);
            color: #ffdd55;
        }

        .fulfilled {
            background: rgba(94, 234, 212, 0.27);
            color: #a7f3d0;
        }

        .pending {
            background: rgba(255, 221, 85, 0.25);
            color: #ffdd55;
        }

        .btn {
            border: none;
            border-radius: 12px;
            padding: 12px 18px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-secondary {
            background: rgba(255, 255, 255,0.05);
            color: white;
            border: 1px solid rgba(255, 255, 255,0.08);
        }

        .btn-primary {
            background: linear-gradient(135deg, #ffdd55, #f6cb08);
            color: #0a1550;
            border: none;
            min-width: 200px;
        }

        .total-panel {
            display: flex;
            justify-content: flex-end;
            margin-top: 18px;
        }

        .total-card {
            min-width: 260px;
            background: rgba(59, 130, 246,0.10);
            border: 1px solid rgba(59, 130, 246,0.25);
            border-radius: 16px;
            padding: 18px 20px;
            text-align: right;
        }

        .total-card small {
            color: #dbeafe;
            display: block;
            margin-bottom: 6px;
        }

        .total-card strong {
            font-size: 30px;
            color: #ffffff;
        }

        .payment-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            align-items: end;
            margin-top: 8px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #dbeafe;
            font-weight: 700;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            height: 50px;
            padding: 0 16px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255,0.10);
            background: rgba(255, 255, 255,0.08);
            color: #ffffff;
            outline: none;
        }

        .form-note {
            margin-top: 10px;
            color: #dbeafe;
            font-size: 13px;
        }

        .empty-note {
            color: #dbeafe;
            font-size: 14px;
        }

        .receipt-view img {
           display: block;
           width: min(100%, 420px);
           max-height: 560px;
           object-fit: contain;
           border: 1px solid rgba(255, 255, 255, 0.12);
           border-radius: 14px;
           background: rgba(0, 0, 0, 0.18);
        }

        .nx-toast-wrap {
            position: fixed;
            top: 22px;
            right: 22px;
            z-index: 99999;
            width: min(380px, calc(100vw - 24px));
        }

        .nx-toast {
            display: flex;
            align-items: stretch;
            border-radius: 18px;
            overflow: hidden;
            background: rgba(0, 3, 61, 0.92);
            box-shadow: 0 24px 50px rgba(0, 0, 0,0.35);
            border: 1px solid rgba(255, 255, 255,0.10);
            animation: slideIn .25s ease;
        }

        .nx-toast-bar {
            width: 7px;
            flex: 0 0 7px;
        }

        .nx-toast.success .nx-toast-bar {
            background: linear-gradient(180deg, #5eead4, #34d399);
        }

        .nx-toast.error .nx-toast-bar {
            background: linear-gradient(180deg, #ff8fab, #ff5577);
        }

        .nx-toast-body {
            flex: 1;
            padding: 14px 16px;
        }

        .nx-toast-title {
            font-weight: 800;
            margin-bottom: 5px;
            color: #ffffff;
        }

        .nx-toast-message {
            font-size: 14px;
            line-height: 1.45;
            color: #dbeafe;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-8px) translateX(12px);
            }
            to {
                opacity: 1;
                transform: translateY(0) translateX(0);
            }
        }

        @media (max-width: 768px) {
            body {
                padding: 16px;
            }

            .content {
                padding: 16px;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            .nx-toast-wrap {
                right: 12px;
                top: 12px;
                width: calc(100vw - 24px);
            }

            .customer-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .customer-box,
            .customer-address-box {
                grid-column: auto;
                padding: 13px 14px 13px 17px;
            }
        }

        /* =========================================================
           LIGHT MODE
           This page had no theme awareness at all before — no
           theme_init include, no data-theme rules — so it always
           rendered dark navy regardless of what theme was set
           everywhere else in the app. Wired to the same
           html[data-theme="light"] switch and blue/gold palette
           used across the Sales module — with actual depth this
           time (gradients + shadows + hover life), not just flat
           tinted boxes.
        ========================= */
        html[data-theme="light"] body {
            color: #0b1f73;
            background:
                radial-gradient(circle at 10% 0%, rgba(162, 210, 255, 0.55) 0%, transparent 45%),
                radial-gradient(circle at 90% 10%, rgba(189, 224, 254, 0.45) 0%, transparent 42%),
                radial-gradient(circle at 50% 100%, rgba(209, 232, 252, 0.55) 0%, transparent 55%),
                linear-gradient(160deg, #eaf4ff 0%, #dbeafe 40%, #eef6ff 75%, #ffffff 100%);
        }

        html[data-theme="light"] .page-shell {
            background:
                radial-gradient(circle at 100% 0%, rgba(246, 203, 8, 0.08) 0%, transparent 30%),
                linear-gradient(165deg, rgba(255, 255, 255, 0.94) 0%, rgba(219, 234, 254, 0.88) 100%);
            border-color: rgba(59, 130, 246, 0.24);
            box-shadow:
                0 24px 55px rgba(30, 64, 175, 0.14),
                inset 0 1px 0 rgba(255, 255, 255, 0.8);
        }

        html[data-theme="light"] .header {
            border-bottom-color: rgba(59, 130, 246, 0.18);
            background: linear-gradient(90deg, rgba(219, 234, 254, 0.4), transparent 60%);
        }

        html[data-theme="light"] .header-title {
            color: #0b1f73;
        }

        html[data-theme="light"] .card {
            background: linear-gradient(155deg, #ffffff 0%, #eef4ff 55%, #dcecfd 100%);
            border: 1px solid rgba(59, 130, 246, 0.18);
            box-shadow: 0 14px 30px rgba(30, 64, 175, 0.08);
        }

        html[data-theme="light"] .section-title {
            color: #0b1f73;
            position: relative;
            padding-left: 14px;
        }

        html[data-theme="light"] .section-title::before {
            content: "";
            position: absolute;
            left: 0;
            top: 2px;
            bottom: 2px;
            width: 4px;
            border-radius: 4px;
            background: linear-gradient(180deg, #ffdd55, #2563eb);
        }

        html[data-theme="light"] .info-box {
            background: linear-gradient(160deg, rgba(255, 255, 255, 0.85) 0%, rgba(210, 231, 253, 0.65) 100%);
            border: 1px solid rgba(59, 130, 246, 0.2);
            box-shadow: 0 8px 18px rgba(30, 64, 175, 0.07);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        html[data-theme="light"] .info-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 26px rgba(30, 64, 175, 0.12);
        }

        html[data-theme="light"] .info-box small {
            color: rgba(11, 31, 115, 0.6);
        }

        html[data-theme="light"] .info-box strong {
            color: #0b1f73;
        }

        html[data-theme="light"] .customer-box {
            background: linear-gradient(160deg, rgba(255, 255, 255, 0.9), rgba(210, 231, 253, 0.62));
            border-color: rgba(59, 130, 246, 0.2);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.9),
                0 8px 18px rgba(30, 64, 175, 0.07);
        }

        html[data-theme="light"] .customer-box small {
            color: rgba(11, 31, 115, 0.62);
        }

        html[data-theme="light"] .customer-box strong {
            color: #0b1f73;
        }

        html[data-theme="light"] .receivable-box {
            background: linear-gradient(155deg, rgba(191, 219, 254, 0.5) 0%, rgba(255, 255, 255, 0.7) 100%);
            border: 1px solid rgba(59, 130, 246, 0.3);
            box-shadow: 0 8px 18px rgba(30, 64, 175, 0.08);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        html[data-theme="light"] .receivable-box:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 26px rgba(30, 64, 175, 0.14);
        }

        html[data-theme="light"] .receivable-box small {
            color: rgba(11, 31, 115, 0.62);
        }

        html[data-theme="light"] .receivable-box strong {
            color: #0b1f73;
        }

        html[data-theme="light"] th,
        html[data-theme="light"] td {
            border-bottom-color: rgba(59, 130, 246, 0.14);
            color: #0b1f73;
        }

        html[data-theme="light"] th {
            background: linear-gradient(90deg, rgba(191, 219, 254, 0.6), rgba(219, 234, 254, 0.3));
            color: #1d4ed8;
            font-weight: 800;
        }

        html[data-theme="light"] tbody tr:hover {
            background: rgba(59, 130, 246, 0.05);
        }

        html[data-theme="light"] .paid,
        html[data-theme="light"] .fulfilled {
            background: linear-gradient(135deg, rgba(110, 231, 183, 0.35), rgba(16, 185, 129, 0.18));
            color: #047857;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        html[data-theme="light"] .unpaid {
            background: linear-gradient(135deg, rgba(253, 164, 175, 0.4), rgba(225, 29, 72, 0.15));
            color: #be123c;
            border: 1px solid rgba(225, 29, 72, 0.3);
        }

        html[data-theme="light"] .partial,
        html[data-theme="light"] .pending {
            background: linear-gradient(135deg, rgba(253, 230, 138, 0.5), rgba(246, 203, 8, 0.2));
            color: #92620a;
            border: 1px solid rgba(212, 160, 23, 0.35);
        }

        html[data-theme="light"] .btn-secondary {
            background: linear-gradient(160deg, #ffffff, #eaf2ff);
            color: #0b1f73;
            border: 1px solid rgba(11, 31, 115, 0.2);
            box-shadow: 0 8px 16px rgba(30, 64, 175, 0.08);
        }

        html[data-theme="light"] .btn-secondary:hover {
            background: #ffffff;
            border-color: rgba(37, 99, 235, 0.4);
            transform: translateY(-1px);
        }

        html[data-theme="light"] .btn-primary {
            box-shadow: 0 14px 28px rgba(212, 160, 23, 0.32);
        }

        html[data-theme="light"] .btn-primary:hover {
            box-shadow: 0 18px 34px rgba(212, 160, 23, 0.4);
        }

        /* Total Due is the number that matters most on this page —
           give it the gold treatment instead of blending into the
           same blue as every other box. */
        html[data-theme="light"] .total-card {
            background: linear-gradient(150deg, rgba(255, 236, 179, 0.55) 0%, rgba(255, 255, 255, 0.85) 60%, rgba(219, 234, 254, 0.7) 100%);
            border: 1.5px solid rgba(212, 160, 23, 0.35);
            box-shadow: 0 16px 32px rgba(180, 130, 10, 0.14);
        }

        html[data-theme="light"] .total-card small {
            color: rgba(11, 31, 115, 0.6);
        }

        html[data-theme="light"] .total-card strong {
            background: linear-gradient(100deg, #92620a, #d4a017 60%, #0b1f73);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        html[data-theme="light"] .form-group label {
            color: rgba(11, 31, 115, 0.72);
        }

        html[data-theme="light"] .form-group input {
            background: #ffffff;
            border: 1px solid rgba(59, 130, 246, 0.25);
            color: #0b1f73;
            box-shadow: inset 0 1px 3px rgba(30, 64, 175, 0.06);
        }

        html[data-theme="light"] .form-group input:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        html[data-theme="light"] .form-note,
        html[data-theme="light"] .empty-note {
            color: rgba(11, 31, 115, 0.6);
        }

        html[data-theme="light"] .nx-toast {
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(59, 130, 246, 0.22);
            box-shadow: 0 24px 50px rgba(30, 64, 175, 0.2);
        }

        html[data-theme="light"] .nx-toast-title {
            color: #0b1f73;
        }

        html[data-theme="light"] .nx-toast-message {
            color: rgba(11, 31, 115, 0.68);
        }
        /* =========================================================
           PROFESSIONAL UI REFINEMENT
           Visual-only overrides: PHP flow and markup remain unchanged.
        ========================================================= */
        :root {
            --nx-ink: #f6f8ff;
            --nx-muted: #aebbd8;
            --nx-line: rgba(148, 163, 184, 0.16);
            --nx-panel: rgba(15, 23, 52, 0.76);
            --nx-panel-strong: rgba(17, 28, 66, 0.92);
            --nx-blue: #5b8cff;
            --nx-gold: #f7c948;
            --nx-radius-lg: 24px;
            --nx-radius-md: 16px;
        }

        html {
            min-height: 100%;
            background: #060b20;
        }

        body {
            position: relative;
            isolation: isolate;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            letter-spacing: -0.01em;
            background:
                radial-gradient(900px 500px at -8% -10%, rgba(91, 140, 255, 0.28), transparent 64%),
                radial-gradient(700px 460px at 108% 6%, rgba(247, 201, 72, 0.13), transparent 62%),
                linear-gradient(145deg, #060b20 0%, #0b1740 46%, #050817 100%);
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            z-index: -1;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            filter: blur(70px);
            pointer-events: none;
            opacity: 0.26;
        }

        body::before { left: -120px; top: 42%; background: #2563eb; }
        body::after { right: -120px; bottom: 8%; background: #eab308; }

        .page-shell {
            position: relative;
            max-width: 1180px;
            background: linear-gradient(145deg, rgba(13, 24, 61, 0.88), rgba(8, 14, 37, 0.72));
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: var(--nx-radius-lg);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.42), inset 0 1px 0 rgba(255, 255, 255, 0.07);
            backdrop-filter: blur(22px);
        }

        .header {
            min-height: 88px;
            padding: 20px 28px;
            background: linear-gradient(90deg, rgba(91, 140, 255, 0.11), transparent 58%);
            border-bottom-color: var(--nx-line);
        }

        .header-left { gap: 16px; }

        .menu-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            background: linear-gradient(145deg, #77a4ff, #376be0 58%, #2145a9);
            box-shadow: 0 12px 26px rgba(49, 94, 218, 0.38), inset 0 1px 0 rgba(255,255,255,.35);
            font-size: 20px;
        }

        .header-title {
            font-size: clamp(22px, 3vw, 30px);
            letter-spacing: -0.04em;
        }

        .content { padding: 28px; }

        .card {
            position: relative;
            overflow: hidden;
            padding: 24px;
            margin-bottom: 20px;
            background: linear-gradient(145deg, rgba(24, 38, 82, 0.78), rgba(11, 20, 51, 0.68));
            border: 1px solid rgba(148, 163, 184, 0.15);
            border-radius: var(--nx-radius-md);
            box-shadow: 0 16px 34px rgba(0, 0, 0, 0.16), inset 0 1px 0 rgba(255,255,255,.045);
        }

        .card::after {
            content: "";
            position: absolute;
            top: 0;
            right: 0;
            width: 150px;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--nx-gold));
            opacity: .75;
        }

        .section-title {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 18px;
            color: var(--nx-ink);
            font-size: 17px;
            letter-spacing: -0.02em;
        }

        .section-title::before {
            content: "";
            width: 5px;
            height: 22px;
            border-radius: 99px;
            background: linear-gradient(180deg, var(--nx-gold), var(--nx-blue));
            box-shadow: 0 0 16px rgba(247, 201, 72, .28);
        }

        .info-grid,
        .receivable-grid { gap: 14px; }

        .info-box,
        .receivable-box,
        .customer-box {
            transition: transform .2s ease, border-color .2s ease, box-shadow .2s ease;
        }

        .info-box:hover,
        .receivable-box:hover,
        .customer-box:hover {
            transform: translateY(-3px);
            border-color: rgba(119, 164, 255, .42);
            box-shadow: 0 14px 26px rgba(0, 0, 0, .18);
        }

        .info-box {
            min-height: 88px;
            padding: 17px;
            background: linear-gradient(145deg, rgba(255,255,255,.075), rgba(255,255,255,.025));
            border-color: var(--nx-line);
        }

        .info-box small,
        .receivable-box small,
        .customer-box small,
        .total-card small { color: var(--nx-muted); text-transform: uppercase; letter-spacing: .08em; font-size: 10px; }

        .info-box strong,
        .receivable-box strong { font-size: 17px; }

        .receivable-box {
            background: linear-gradient(145deg, rgba(59, 130, 246, .16), rgba(19, 37, 84, .48));
            border-color: rgba(91, 140, 255, .25);
        }

        .customer-box {
            background: linear-gradient(145deg, rgba(76, 126, 224, .14), rgba(255,255,255,.035));
        }

        .badge {
            min-width: 112px;
            padding: 7px 13px;
            border: 1px solid currentColor;
            border-opacity: .18;
            border-radius: 999px;
            font-size: 11px;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .btn {
            min-height: 44px;
            padding: 12px 19px;
            border-radius: 13px;
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease, background .2s ease;
        }

        .btn:hover { transform: translateY(-2px); }

        .btn-secondary {
            background: rgba(255,255,255,.055);
            border-color: rgba(148,163,184,.24);
            box-shadow: 0 8px 18px rgba(0,0,0,.14);
        }

        .btn-secondary:hover { background: rgba(255,255,255,.1); border-color: rgba(119,164,255,.55); }

        .btn-primary {
            background: linear-gradient(135deg, #ffe47b, #f5bd2e);
            box-shadow: 0 12px 24px rgba(230, 172, 29, .22);
        }

        .btn-primary:hover { box-shadow: 0 16px 30px rgba(230, 172, 29, .34); }

        table { border-spacing: 0 7px; border-collapse: separate; }
        th { background: rgba(91, 140, 255, .12); color: #dbe7ff; text-transform: uppercase; letter-spacing: .08em; font-size: 10px; }
        th:first-child { border-radius: 11px 0 0 11px; }
        th:last-child { border-radius: 0 11px 11px 0; }
        td { border-bottom: 1px solid rgba(148,163,184,.10); color: #e8efff; }
        tbody tr { transition: background .18s ease, transform .18s ease; }
        tbody tr:hover { background: rgba(91, 140, 255, .08); }

        .total-panel { margin-top: 22px; }
        .total-card {
            min-width: 280px;
            background: linear-gradient(145deg, rgba(247, 201, 72, .18), rgba(30, 47, 96, .6));
            border-color: rgba(247, 201, 72, .34);
            box-shadow: 0 16px 30px rgba(0,0,0,.18), inset 0 1px 0 rgba(255,255,255,.08);
        }
        .total-card strong { color: #ffe27b; text-shadow: 0 3px 20px rgba(247,201,72,.2); }

        .form-group input { transition: border-color .2s ease, box-shadow .2s ease, background .2s ease; }
        .form-group input:focus { border-color: var(--nx-blue); background: rgba(255,255,255,.11); box-shadow: 0 0 0 4px rgba(91,140,255,.16); }

        @media (max-width: 768px) {
            body { padding: 10px; }
            .page-shell { border-radius: 20px; }
            .header { padding: 18px; }
            .content { padding: 14px; }
            .card { padding: 18px; border-radius: 15px; }
            .total-card { min-width: 100%; }
        }
        </style>
</head>
<body>
    <?php if (!empty($popupMessage)): ?>
        <div class="nx-toast-wrap" id="nxToastWrap">
            <div class="nx-toast <?php echo htmlspecialchars($popupType ?: 'success'); ?>">
                <div class="nx-toast-bar"></div>
                <div class="nx-toast-body">
                    <div class="nx-toast-title"><?php echo $popupType === 'error' ? 'Attention' : 'Success'; ?></div>
                    <div class="nx-toast-message"><?php echo htmlspecialchars($popupMessage); ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="page-shell">
        <div class="header">
            <div class="header-left">
                <div class="menu-icon">👁</div>
                <div class="header-title">Sale Details</div>
            </div>
            <a href="<?php echo htmlspecialchars(
    buildSalesRecordingUrl($salesReturnParams),
    ENT_QUOTES,
    'UTF-8'
); ?>" class="btn btn-secondary">
    Back to Sales
</a>
        </div>

        <div class="content">
            <div class="card">
                <div class="section-title">Sale Information</div>

                <div class="info-grid">
                    <div class="info-box">
                        <small>Sales No.</small>
                        <strong><?php echo htmlspecialchars($sale['sales_no']); ?></strong>
                    </div>

                    <div class="info-box">
                        <small>Salesperson</small>
                        <strong><?php echo htmlspecialchars($sale['salesperson'] ?: 'N/A'); ?></strong>
                    </div>

                    <div class="info-box">
                        <small>Sale Date</small>
                        <strong><?php echo htmlspecialchars(nxFormatLocalDateTime($sale['sale_date']) ?? '—'); ?></strong>
                    </div>

                    <div class="info-box">
                        <small>Payment Method</small>
                        <strong><?php echo htmlspecialchars($sale['payment_method']); ?></strong>
                    </div>

                    <div class="info-box">
                        <small>Payment Status</small>
                        <strong>
                            <span class="<?php echo badgeClassPayment($sale['payment_status']); ?>">
                                <?php echo htmlspecialchars($sale['payment_status']); ?>
                            </span>
                        </strong>
                    </div>

                </div>
            </div>

            <?php if ($arEnabled && $customerInfo): ?>
                <div class="card customer-card">
                    <div class="section-title">Customer Information</div>

                    <div class="customer-grid">
                        <div class="customer-box">
                            <small>Customer Code</small>
                            <strong><?php echo htmlspecialchars(!empty($customerInfo['customer_code']) ? $customerInfo['customer_code'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>

                        <div class="customer-box">
                            <small>Customer Name</small>
                            <strong><?php echo htmlspecialchars(!empty($customerInfo['customer_name']) ? $customerInfo['customer_name'] : 'N/A', ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>

                        <div class="customer-box">
                            <small>Phone Number</small>
                            <strong><?php echo htmlspecialchars(!empty($customerInfo['phone']) ? $customerInfo['phone'] : 'Not provided', ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>

                        <div class="customer-box">
                            <small>Email Address</small>
                            <strong><?php echo htmlspecialchars(!empty($customerInfo['email']) ? $customerInfo['email'] : 'Not provided', ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>

                        <div class="customer-box customer-address-box">
                            <small>Address</small>
                            <strong><?php echo htmlspecialchars(!empty($customerInfo['address']) ? $customerInfo['address'] : 'Not provided', ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($arEnabled && $arInfo): ?>
                <div class="card">
                    <div class="section-title">Receivable Status</div>

                    <div class="receivable-grid">
                        <div class="receivable-box">
                            <small>Total Amount</small>
                            <strong>₱<?php echo number_format((float)$arInfo['total_amount'], 2); ?></strong>
                        </div>

                        <div class="receivable-box">
                            <small>Amount Paid</small>
                            <strong>₱<?php echo number_format((float)$arInfo['amount_paid'], 2); ?></strong>
                        </div>

                        <div class="receivable-box">
                            <small>Balance Due</small>
                            <strong>₱<?php echo number_format((float)$arInfo['balance_due'], 2); ?></strong>
                        </div>

                        <div class="receivable-box">
                            <small>Receivable Status</small>
                            <strong><?php echo htmlspecialchars($arInfo['status']); ?></strong>
                        </div>

                        <div class="receivable-box">
                            <small>Due Date</small>
                            <strong><?php echo !empty($arInfo['due_date']) ? htmlspecialchars($arInfo['due_date']) : 'N/A'; ?></strong>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($arEnabled && $arInfo && $sale['payment_status'] !== 'Paid'): ?>
                <div class="card">
                    <div class="section-title">Update Payment</div>

                    <form method="POST" class="payment-form">
                        <input type="hidden" name="update_payment" value="1">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken('sale_view_payment_form')); ?>">

                        <div class="form-group">
                            <label>Additional Payment</label>
                            <input type="number" step="0.01" min="0.01" name="additional_payment" placeholder="Enter additional payment" required>
                        </div>

                        <div class="form-group">
                            <label>Due Date</label>
                            <input type="date" name="due_date" value="<?php echo !empty($arInfo['due_date']) ? htmlspecialchars($arInfo['due_date']) : ''; ?>">
                        </div>

                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Update Payment</button>
                        </div>
                    </form>

                    <div class="form-note">
                        Rule applied: Unpaid or Partially Paid stays <strong>Pending</strong>. Once fully paid, it automatically becomes <strong>Paid</strong> and <strong>Fulfilled</strong>.
                    </div>
                </div>
            <?php elseif ($arEnabled && !$arInfo): ?>
                <div class="card">
                    <div class="section-title">Update Payment</div>
                    <div class="empty-note">This sale has no accounts receivable record, so there is no payment update form available.</div>
                </div>
            <?php endif; ?>

            <div class="card">
                <div class="section-title">Receipt</div>
                <?php if ($receiptPath !== null): ?>
                    <div class="receipt-view">
                        <img src="<?php echo htmlspecialchars('sale_receipt.php?id=' . $sale_id, ENT_QUOTES, 'UTF-8'); ?>" alt="Receipt for sale <?php echo htmlspecialchars($sale['sales_no'], ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                <?php else: ?>
                    <div class="empty-note">No receipt photo was attached to this sale.</div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="section-title">Items Sold</div>

                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Unit Price</th>
                            <th>Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($itemResult->num_rows > 0): ?>
                            <?php while ($item = $itemResult->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                    <td><?php echo formatQty($item['quantity']); ?></td>
                                    <td>₱<?php echo number_format($item['unit_price'], 2); ?></td>
                                    <td>₱<?php echo number_format($item['subtotal'], 2); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4">No items found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="total-panel">
                    <div class="total-card">
                        <small>Total Amount</small>
                        <strong>₱<?php echo number_format($sale['total_amount'], 2); ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const nxToastWrap = document.getElementById('nxToastWrap');
        if (nxToastWrap) {
            setTimeout(() => {
                nxToastWrap.style.transition = 'opacity .25s ease, transform .25s ease';
                nxToastWrap.style.opacity = '0';
                nxToastWrap.style.transform = 'translateY(-8px)';
                setTimeout(() => {
                    nxToastWrap.remove();
                }, 260);
            }, 3200);
        }
    </script>
</body>
</html>
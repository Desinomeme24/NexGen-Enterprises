<?php
session_start();
require_once("config.php");
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/ar_helper.php';
$businessId = nxRequireBusinessId($conn);
require_once(__DIR__ . "/audit_helper.php");
set_audit_context($conn);


if (!isset($_SESSION['user_id'])) {
    header('Location: ' . nxLoginPathForRole((string)($_SESSION['role'] ?? '')));
    exit();
}

if (!nxArEnabled()) {
    $_SESSION['error'] = 'You do not have access to Accounts Receivable.';
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($id <= 0) {
    $_SESSION['error'] = 'Invalid receivable ID.';
    header('Location: ' . nxAppUrl('accounts_receivable.php'));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken('receivable_payment_form', $_POST['csrf_token'] ?? null)) {
        $_SESSION['error'] = 'Your session expired. Please try again.';
        header('Location: ' . nxAppUrl('receivable_payment.php?id=' . $id));
        exit();
    }

    $additional_payment = (float)($_POST['additional_payment'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($additional_payment <= 0) {
        $_SESSION['error'] = 'Additional payment must be greater than 0.';
        header('Location: ' . nxAppUrl('receivable_payment.php?id=' . $id));
        exit();
    }

    $maxRetries = 3;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {

    $conn->begin_transaction();

    try {
        // Step 1: Read WITHOUT lock to get sale_id (no lock held yet)
        $stmtRead = $conn->prepare("
            SELECT id, sale_id, total_amount, amount_paid, balance_due, due_date
            FROM accounts_receivable
            WHERE id = ? AND business_id = {$businessId}
        ");

        if (!$stmtRead) {
            throw new Exception('Failed to prepare receivable read query.');
        }

        $stmtRead->bind_param("i", $id);
        $stmtRead->execute();
        $rowRead = $stmtRead->get_result()->fetch_assoc();
        $stmtRead->close();

        if (!$rowRead) {
            throw new Exception('Receivable not found.');
        }

        $saleId = (int)$rowRead['sale_id'];

        // Step 2: Lock SALES first (global order: sales → accounts_receivable)
        $saleLock = $conn->prepare("
            SELECT id
            FROM sales
            WHERE id = ? AND business_id = {$businessId}
            FOR UPDATE
        ");

        if (!$saleLock) {
            throw new Exception('Failed to prepare sales lock query.');
        }

        $saleLock->bind_param("i", $saleId);
        $saleLock->execute();
        $saleRow = $saleLock->get_result()->fetch_assoc();
        $saleLock->close();

        if (!$saleRow) {
            throw new Exception('Related sale record not found.');
        }

        // Step 3: Lock ACCOUNTS_RECEIVABLE second (correct global order)
        $stmt = $conn->prepare("
            SELECT id, sale_id, total_amount, amount_paid, balance_due, due_date
            FROM accounts_receivable
            WHERE id = ? AND business_id = {$businessId}
            FOR UPDATE
        ");

        if (!$stmt) {
            throw new Exception('Failed to prepare receivable lock query.');
        }

        $stmt->bind_param("i", $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new Exception('Receivable not found.');
        }

        $totalAmount = (float)$row['total_amount'];
        $currentAmountPaid = (float)$row['amount_paid'];

        $newAmountPaid = $currentAmountPaid + $additional_payment;

        if ($newAmountPaid > $totalAmount) {
            $newAmountPaid = $totalAmount;
        }

        if ($newAmountPaid < 0) {
            $newAmountPaid = 0;
        }

        $newBalance = max(0, $totalAmount - $newAmountPaid);

        $newReceivableStatus = 'Unpaid';
        $newSalesPaymentStatus = 'Unpaid';
        $newSalesOrderStatus = 'Pending';

        if ($newBalance <= 0) {
            $newReceivableStatus = 'Paid';
            $newSalesPaymentStatus = 'Paid';
            $newSalesOrderStatus = 'Fulfilled';
        } elseif ($newAmountPaid > 0) {
            $newReceivableStatus = 'Partially Paid';
            $newSalesPaymentStatus = 'Partially Paid';
            $newSalesOrderStatus = 'Pending';
        }

        if (
            $newReceivableStatus !== 'Paid' &&
            !empty($row['due_date']) &&
            strtotime($row['due_date']) < strtotime(date('Y-m-d'))
        ) {
            $newReceivableStatus = 'Overdue';
        }

        $updateReceivable = $conn->prepare("
            UPDATE accounts_receivable
            SET amount_paid = ?, balance_due = ?, status = ?, notes = ?
            WHERE id = ? AND business_id = {$businessId}
        ");

        if (!$updateReceivable) {
            throw new Exception('Failed to prepare receivable update query.');
        }

        $updateReceivable->bind_param(
            "ddssi",
            $newAmountPaid,
            $newBalance,
            $newReceivableStatus,
            $notes,
            $id
        );

        if (!$updateReceivable->execute()) {
            throw new Exception('Failed to update receivable payment.');
        }

        $updateReceivable->close();

        $updateSale = $conn->prepare("
            UPDATE sales
            SET payment_status = ?, order_status = ?
            WHERE id = ? AND business_id = {$businessId}
        ");

        if (!$updateSale) {
            throw new Exception('Failed to prepare sales sync query.');
        }

        $updateSale->bind_param(
            "ssi",
            $newSalesPaymentStatus,
            $newSalesOrderStatus,
            $saleId
        );

        if (!$updateSale->execute()) {
            throw new Exception('Failed to sync sales payment status.');
        }

        $updateSale->close();

        $appliedPayment = max(0, $newAmountPaid - $currentAmountPaid);
        if ($appliedPayment > 0) {
            $paymentMethod = 'Manual Update';
            $paymentReference = null;
            $paymentStmt = $conn->prepare("
                INSERT INTO receivable_payments (
                    business_id, receivable_id, sale_id, amount, payment_method, reference_no, notes, recorded_by, paid_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            if (!$paymentStmt) {
                throw new Exception('Failed to prepare payment history query.');
            }
            $userId = (int)$_SESSION['user_id'];
            $paymentStmt->bind_param(
                "iiidsssi",
                $businessId,
                $id,
                $saleId,
                $appliedPayment,
                $paymentMethod,
                $paymentReference,
                $notes,
                $userId
            );
            if (!$paymentStmt->execute()) {
                throw new Exception('Failed to save payment history.');
            }
            $paymentStmt->close();
        }

        $conn->commit();

        $_SESSION['success'] = 'Receivable payment updated successfully.';
        header('Location: ' . nxAppUrl('accounts_receivable.php'));
        exit();

    } catch (Exception $e) {
        $conn->rollback();

        // Retry on deadlock (MySQL error 1213), otherwise fail immediately
        if ($conn->errno === 1213 && $attempt < $maxRetries) {
            usleep(100000 * $attempt); // wait 100ms, 200ms before retrying
            continue;
        }

        $_SESSION['error'] = $e->getMessage();
        header('Location: ' . nxAppUrl('receivable_payment.php?id=' . $id));
        exit();
    }

    } // end retry loop
}

$stmt = $conn->prepare("
    SELECT ar.*, c.customer_name, s.sales_no
    FROM accounts_receivable ar
    INNER JOIN customers c ON ar.customer_id = c.id
    INNER JOIN sales s ON ar.sale_id = s.id
    WHERE ar.id = ? AND ar.business_id = {$businessId} AND c.business_id = {$businessId} AND s.business_id = {$businessId}
    LIMIT 1
");

$stmt->bind_param("i", $id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$data) {
    $_SESSION['error'] = 'Receivable record not found.';
    header('Location: ' . nxAppUrl('accounts_receivable.php'));
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <?php include __DIR__ . '/theme_init.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Receivable Payment</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap');

        :root{
            --royal:#0033ff;
            --azure:#3b82f6;
            --sky:#7dd3fc;
            --indigo:#0b1f73;
            --midnight:#00033d;
            --gold:#f6cb08;
            --gold-light:#ffdd55;
            --ink:#eef5ff;
            --ink-soft:#b9d4f5;

            --page-bg:
                radial-gradient(circle at 12% 8%, rgba(59,130,246,0.32) 0%, rgba(59,130,246,0) 34%),
                radial-gradient(circle at 88% 92%, rgba(125,211,252,0.16) 0%, rgba(125,211,252,0) 32%),
                linear-gradient(180deg,#050b24 0%,#0a1550 45%,#02040f 100%);
            --card-bg:linear-gradient(180deg, rgba(59,130,246,0.10) 0%, rgba(11,31,115,0.18) 100%);
            --card-border:rgba(59,130,246,0.26);
            --info-bg:rgba(11,31,115,0.28);
            --info-border:rgba(59,130,246,0.24);
            --stat-bg:rgba(255,255,255,0.04);
            --input-bg:rgba(8,16,50,0.55);
            --input-border:rgba(59,130,246,0.30);
            --text-main:#eef5ff;
            --text-soft:#b9d4f5;
            --value:#eef5ff;
            --prefix:#62749a;
            --cancel-bg:rgba(59,130,246,0.14);
            --cancel-border:rgba(59,130,246,0.36);
            --shadow:0 20px 46px rgba(2,4,20,0.50), inset 0 1px 0 rgba(255,255,255,0.07);
        }

        html[data-theme="light"]{
            --ink:#0a1f4d;
            --ink-soft:#4a5568;
            --page-bg:
                radial-gradient(circle at 12% 8%, rgba(59,130,246,0.14) 0%, rgba(59,130,246,0) 34%),
                radial-gradient(circle at 88% 92%, rgba(125,211,252,0.18) 0%, rgba(125,211,252,0) 32%),
                linear-gradient(180deg,#eaf4ff 0%,#d1e8fc 48%,#e5f0fa 100%);
            --card-bg:
                linear-gradient(135deg, rgba(255,255,255,0.88), rgba(239,247,255,0.78));
            --card-border:rgba(59,130,246,0.24);
            --info-bg:rgba(255,255,255,0.62);
            --info-border:rgba(59,130,246,0.20);
            --stat-bg:rgba(219,234,254,0.54);
            --input-bg:rgba(255,255,255,0.82);
            --input-border:rgba(59,130,246,0.28);
            --text-main:#0a1f4d;
            --text-soft:#475569;
            --value:#0a1f4d;
            --prefix:#64748b;
            --cancel-bg:rgba(219,234,254,0.82);
            --cancel-border:rgba(59,130,246,0.26);
            --shadow:0 20px 46px rgba(59,130,246,0.15), inset 0 1px 0 rgba(255,255,255,0.92);
        }

        *{box-sizing:border-box}

        body{
            margin:0;
            font-family:'Inter',Arial,sans-serif;
            background:var(--page-bg);
            background-attachment:fixed;
            color:var(--text-main);
            min-height:100vh;
            min-height:100dvh;
            display:flex;
            align-items:center;
            justify-content:center;
            padding:32px 24px;
            transition:background .28s ease,color .28s ease;
        }

        .card{
            position:relative;
            width:100%;
            max-width:640px;
            background:var(--card-bg);
            border:1px solid var(--card-border);
            border-radius:30px;
            padding:34px;
            box-shadow:var(--shadow);
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
            overflow:hidden;
        }

        .card::before{
            content:"";
            position:absolute;
            inset:0;
            pointer-events:none;
            background:
                radial-gradient(circle at 0 0, rgba(255,255,255,.09), transparent 28%),
                linear-gradient(120deg, transparent 0%, rgba(255,255,255,.04) 45%, transparent 70%);
        }

        html[data-theme="light"] .card::before{
            background:
                radial-gradient(circle at 0 0, rgba(255,255,255,.85), transparent 30%),
                linear-gradient(120deg, transparent 0%, rgba(59,130,246,.04) 45%, transparent 70%);
        }

        .card > *{position:relative;z-index:1}

        .card-header{
            display:flex;
            align-items:center;
            gap:16px;
            margin-bottom:24px;
        }

        .icon-wrap{
            flex:0 0 54px;
            width:54px;
            height:54px;
            border-radius:16px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:24px;
            background:linear-gradient(135deg,var(--gold-light) 0%,var(--gold) 100%);
            color:#2b1a00;
            box-shadow:0 10px 26px rgba(246,203,8,.34);
        }

        h1{
            margin:0;
            font-family:'Poppins','Inter',sans-serif;
            font-size:1.55rem;
            font-weight:800;
            line-height:1.15;
            color:var(--text-main);
        }

        .card-header p{
            margin:4px 0 0;
            font-size:.9rem;
            color:var(--text-soft);
            font-weight:600;
        }

        .info{
            background:var(--info-bg);
            border:1px solid var(--info-border);
            border-radius:20px;
            padding:20px 20px 8px;
            margin-bottom:24px;
            box-shadow:inset 0 1px 0 rgba(255,255,255,.06);
        }

        html[data-theme="light"] .info{
            box-shadow:inset 0 1px 0 rgba(255,255,255,.82);
        }

        .info-line{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:14px;
            padding-bottom:13px;
            margin-bottom:13px;
            border-bottom:1px solid var(--info-border);
        }

        .info-line .label{
            font-size:.82rem;
            color:var(--text-soft);
            font-weight:700;
            text-transform:uppercase;
            letter-spacing:.35px;
        }

        .info-line .value{
            font-size:.98rem;
            font-weight:800;
            color:var(--value);
            text-align:right;
            word-break:break-word;
        }

        .info-stats{
            display:grid;
            grid-template-columns:repeat(3,1fr);
            gap:12px;
            padding-bottom:14px;
        }

        .info-stat{
            background:var(--stat-bg);
            border:1px solid var(--info-border);
            border-radius:16px;
            padding:14px 10px;
            text-align:center;
        }

        .info-stat span{display:block}

        .stat-label{
            font-size:.72rem;
            color:var(--text-soft);
            font-weight:800;
            text-transform:uppercase;
            letter-spacing:.35px;
            margin-bottom:7px;
        }

        .stat-value{
            font-family:'Poppins','Inter',sans-serif;
            font-size:1.08rem;
            font-weight:800;
            color:var(--value);
        }

        .info-stat.balance .stat-value{color:var(--gold-light)}
        html[data-theme="light"] .info-stat.balance .stat-value{color:#9a6b00}

        .row{margin-bottom:18px}

        label{
            display:flex;
            align-items:center;
            gap:8px;
            margin-bottom:9px;
            font-size:.92rem;
            font-weight:800;
            color:var(--text-main);
        }

        label i{
            color:var(--sky);
            font-size:1rem;
        }

        html[data-theme="light"] label i{color:#2563eb}

        .input-wrap{position:relative}

        .prefix{
            position:absolute;
            left:17px;
            top:50%;
            transform:translateY(-50%);
            font-weight:800;
            color:var(--prefix);
            pointer-events:none;
        }

        .input-wrap input[type="number"]{padding-left:38px}

        input,textarea{
            width:100%;
            padding:14px 16px;
            border:1px solid var(--input-border);
            border-radius:15px;
            font-size:15px;
            font-family:inherit;
            background:var(--input-bg);
            color:var(--text-main);
            transition:border-color .2s ease,box-shadow .2s ease,background .2s ease;
        }

        textarea{
            min-height:112px;
            resize:vertical;
            line-height:1.5;
        }

        input::placeholder,textarea::placeholder{color:var(--text-soft);opacity:.52}

        input:focus,textarea:focus{
            outline:none;
            border-color:rgba(246,203,8,.65);
            box-shadow:0 0 0 4px rgba(246,203,8,.14);
        }

        .actions{
            display:flex;
            gap:12px;
            margin-top:22px;
        }

        .actions button,.actions a{
            flex:1;
            min-height:50px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            padding:13px 18px;
            border-radius:15px;
            text-decoration:none;
            font-family:inherit;
            font-size:.94rem;
            font-weight:800;
            cursor:pointer;
            transition:transform .22s ease,box-shadow .22s ease,background .22s ease;
        }

        .actions button{
            border:0;
            background:linear-gradient(135deg,var(--gold-light) 0%,var(--gold) 100%);
            color:#2b1a00;
            box-shadow:0 10px 22px rgba(246,203,8,.28);
        }

        .actions button:hover{
            transform:translateY(-2px);
            box-shadow:0 15px 28px rgba(246,203,8,.38);
        }

        .actions a{
            background:var(--cancel-bg);
            border:1px solid var(--cancel-border);
            color:var(--text-main);
        }

        .actions a:hover{
            transform:translateY(-2px);
            box-shadow:0 12px 24px rgba(3,3,30,.18);
        }

        @media(max-width:620px){
            body{align-items:flex-start;padding:18px 12px}
            .card{padding:22px 16px;border-radius:24px}
            .card-header{align-items:flex-start}
            .info{padding:16px 14px 8px}
            .info-line{
                display:grid;
                grid-template-columns:110px minmax(0,1fr);
                align-items:start;
                justify-content:start;
                gap:10px;
                padding-bottom:11px;
                margin-bottom:11px;
            }
            .info-line .value{
                justify-self:start;
                min-width:0;
                text-align:left;
                overflow-wrap:anywhere;
            }
            .info-stats{
                grid-template-columns:1fr;
                gap:8px;
            }
            .info-stat{
                padding:12px 14px;
                text-align:left;
            }
            .stat-label{margin-bottom:4px}
            .actions{flex-direction:column}
        }

        @media(max-width:380px){
            .info-line{grid-template-columns:94px minmax(0,1fr);gap:8px}
            .info-line .label{font-size:.75rem}
            .info-line .value{font-size:.9rem}
        }
    </style>
</head>
<body>
    <form method="POST" class="card">
        <input type="hidden" name="id" value="<?php echo (int)$data['id']; ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generateCsrfToken('receivable_payment_form')); ?>">

        <div class="card-header">
            <div class="icon-wrap"><i class="bi bi-cash-stack"></i></div>
            <div>
                <h1>Update Receivable</h1>
                <p>Record a new payment against this sale</p>
            </div>
        </div>

        <div class="info">
            <div class="info-line">
                <span class="label">Sales No.</span>
                <span class="value"><?php echo htmlspecialchars($data['sales_no']); ?></span>
            </div>
            <div class="info-line">
                <span class="label">Customer</span>
                <span class="value"><?php echo htmlspecialchars($data['customer_name']); ?></span>
            </div>

            <div class="info-stats">
                <div class="info-stat">
                    <span class="stat-label">Total</span>
                    <span class="stat-value">₱<?php echo number_format((float)$data['total_amount'], 2); ?></span>
                </div>
                <div class="info-stat">
                    <span class="stat-label">Paid</span>
                    <span class="stat-value">₱<?php echo number_format((float)$data['amount_paid'], 2); ?></span>
                </div>
                <div class="info-stat balance">
                    <span class="stat-label">Balance</span>
                    <span class="stat-value">₱<?php echo number_format((float)$data['balance_due'], 2); ?></span>
                </div>
            </div>
        </div>

        <div class="row">
            <label for="additional_payment"><i class="bi bi-wallet2"></i> Additional Payment</label>
            <div class="input-wrap">
                <span class="prefix">₱</span>
                <input id="additional_payment" type="number" step="0.01" min="0.01" name="additional_payment" placeholder="0.00" required>
            </div>
        </div>

        <div class="row">
            <label for="notes"><i class="bi bi-journal-text"></i> Notes / Remarks</label>
            <textarea id="notes" name="notes" rows="4" placeholder="Optional remarks about this payment"><?php echo htmlspecialchars($data['notes'] ?? ''); ?></textarea>
        </div>

        <div class="actions">
            <button type="submit"><i class="bi bi-check2-circle"></i> Save Payment</button>
            <a href="<?php echo htmlspecialchars(nxAppUrl('accounts_receivable.php'), ENT_QUOTES, 'UTF-8'); ?>"><i class="bi bi-x-circle"></i> Cancel</a>
        </div>
    </form>
</body>
</html>

<?php
session_start();
require_once("config.php");
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/ar_helper.php';
$businessId = nxRequireBusinessId($conn);

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . nxLoginPathForRole((string)($_SESSION['role'] ?? '')));
    exit();
}

if (!nxArEnabled($conn, (int)$_SESSION['user_id'])) {
    $_SESSION['error'] = 'You do not have access to Accounts Receivable.';
    header('Location: ' . nxAppUrl('dashboard.php'));
    exit();
}

$displayName = $_SESSION['username'] ?? 'Client';
$fullName = $_SESSION['full_name'] ?? 'Client';
$profileImage = !empty($_SESSION['profile_image']) ? $_SESSION['profile_image'] : 'uploads/default.png';
$role = $_SESSION['role'];

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$dueDateFilter = trim($_GET['due_date'] ?? '');
$isAjax = isset($_GET['ajax']) && $_GET['ajax'] === '1';

$allowedStatuses = ['Unpaid', 'Partially Paid', 'Paid', 'Overdue'];
$allowedDueDateFilters = ['overdue', 'this_week', 'this_month', 'next_30'];

/*
|--------------------------------------------------------------------------
| OPTIMIZED MAIN RECEIVABLE QUERY
| - Computes live_status once only
| - Avoids repeating the same CASE in WHERE and ORDER BY
| - Selects only needed columns instead of ar.*
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        x.id,
        x.sale_id,
        x.customer_id,
        x.total_amount,
        x.amount_paid,
        x.balance_due,
        x.due_date,
        x.status,
        x.notes,
        x.created_at,
        x.updated_at,
        x.customer_name,
        x.customer_code,
        x.sales_no,
        x.live_status
    FROM (
        SELECT
            ar.id,
            ar.sale_id,
            ar.customer_id,
            ar.total_amount,
            ar.amount_paid,
            ar.balance_due,
            ar.due_date,
            ar.status,
            ar.notes,
            ar.created_at,
            ar.updated_at,
            c.customer_name,
            c.customer_code,
            s.sales_no,
            CASE
                WHEN ar.balance_due <= 0 THEN 'Paid'
                WHEN ar.due_date IS NOT NULL
                     AND ar.due_date < CURDATE()
                     AND ar.balance_due > 0 THEN 'Overdue'
                WHEN ar.amount_paid > 0
                     AND ar.balance_due > 0 THEN 'Partially Paid'
                ELSE 'Unpaid'
            END AS live_status
        FROM accounts_receivable ar
        INNER JOIN customers c ON ar.customer_id = c.id AND c.business_id = {$businessId}
        INNER JOIN sales s ON ar.sale_id = s.id AND s.business_id = {$businessId}
        WHERE ar.business_id = {$businessId}
    ) x
    WHERE 1=1
";

$params = [];
$types = "";

if ($search !== '') {
    $sql .= " AND (x.customer_name LIKE ? OR x.sales_no LIKE ?) ";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
    $sql .= " AND x.live_status = ? ";
    $params[] = $statusFilter;
    $types .= "s";
}

if ($dueDateFilter !== '' && in_array($dueDateFilter, $allowedDueDateFilters, true)) {
    switch ($dueDateFilter) {
        case 'overdue':
            $sql .= " AND x.due_date IS NOT NULL AND x.due_date < CURDATE() AND x.balance_due > 0 ";
            break;
        case 'this_week':
            $sql .= " AND x.due_date IS NOT NULL AND x.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ";
            break;
        case 'this_month':
            $sql .= " AND x.due_date IS NOT NULL AND x.due_date BETWEEN CURDATE() AND LAST_DAY(CURDATE()) ";
            break;
        case 'next_30':
            $sql .= " AND x.due_date IS NOT NULL AND x.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) ";
            break;
    }
}

$sql .= "
    ORDER BY
        CASE x.live_status
            WHEN 'Overdue' THEN 1
            WHEN 'Unpaid' THEN 2
            WHEN 'Partially Paid' THEN 3
            ELSE 4
        END,
        x.due_date ASC,
        x.created_at DESC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("Query error: " . $conn->error);
}

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
}
$stmt->close();

$summary = [
    'total_receivables' => 0,
    'total_balance_due' => 0,
    'overdue_count' => 0,
    'unpaid_count' => 0,
    'partial_count' => 0
];

$summaryQuery = $conn->query("
    SELECT
        COUNT(*) AS total_receivables,
        COALESCE(SUM(balance_due), 0) AS total_balance_due,
        SUM(
            CASE
                WHEN due_date IS NOT NULL AND due_date < CURDATE() AND balance_due > 0 THEN 1
                ELSE 0
            END
        ) AS overdue_count,
        SUM(
            CASE
                WHEN balance_due > 0
                 AND (amount_paid IS NULL OR amount_paid <= 0)
                 AND NOT (due_date IS NOT NULL AND due_date < CURDATE())
                THEN 1
                ELSE 0
            END
        ) AS unpaid_count,
        SUM(
            CASE
                WHEN amount_paid > 0 AND balance_due > 0
                 AND NOT (due_date IS NOT NULL AND due_date < CURDATE())
                THEN 1
                ELSE 0
            END
        ) AS partial_count
    FROM accounts_receivable
    WHERE business_id = {$businessId}
");
if ($summaryQuery) {
    $summary = $summaryQuery->fetch_assoc();
}

$recentPayments = [];
$recentPaymentsQuery = $conn->query("
    SELECT
        ar.amount_paid,
        ar.updated_at,
        c.customer_name
    FROM accounts_receivable ar
    INNER JOIN customers c ON ar.customer_id = c.id AND c.business_id = {$businessId}
    WHERE ar.business_id = {$businessId} AND ar.amount_paid > 0
    ORDER BY ar.updated_at DESC
    LIMIT 5
");
if ($recentPaymentsQuery) {
    while ($paymentRow = $recentPaymentsQuery->fetch_assoc()) {
        $recentPayments[] = $paymentRow;
    }
}

/*
|--------------------------------------------------------------------------
| AGING REPORT
| Buckets outstanding balance_due by how many days past due_date.
| "Current" = not yet due (or no due date) but still has a balance.
|--------------------------------------------------------------------------
*/
$aging = [
    'current_amt' => 0,
    'd1_30'       => 0,
    'd31_60'      => 0,
    'd61_90'      => 0,
    'd90_plus'    => 0,
];

$agingQuery = $conn->query("
    SELECT
        SUM(CASE
            WHEN balance_due > 0
             AND (due_date IS NULL OR due_date >= CURDATE())
            THEN balance_due ELSE 0
        END) AS current_amt,
        SUM(CASE
            WHEN balance_due > 0 AND due_date IS NOT NULL AND due_date < CURDATE()
             AND DATEDIFF(CURDATE(), due_date) BETWEEN 1 AND 30
            THEN balance_due ELSE 0
        END) AS d1_30,
        SUM(CASE
            WHEN balance_due > 0 AND due_date IS NOT NULL AND due_date < CURDATE()
             AND DATEDIFF(CURDATE(), due_date) BETWEEN 31 AND 60
            THEN balance_due ELSE 0
        END) AS d31_60,
        SUM(CASE
            WHEN balance_due > 0 AND due_date IS NOT NULL AND due_date < CURDATE()
             AND DATEDIFF(CURDATE(), due_date) BETWEEN 61 AND 90
            THEN balance_due ELSE 0
        END) AS d61_90,
        SUM(CASE
            WHEN balance_due > 0 AND due_date IS NOT NULL AND due_date < CURDATE()
             AND DATEDIFF(CURDATE(), due_date) > 90
            THEN balance_due ELSE 0
        END) AS d90_plus
    FROM accounts_receivable
    WHERE business_id = {$businessId}
");
if ($agingQuery) {
    $agingRow = $agingQuery->fetch_assoc();
    foreach ($aging as $key => $val) {
        $aging[$key] = (float)($agingRow[$key] ?? 0);
    }
}

$popupMessage = $_SESSION['success'] ?? $_SESSION['error'] ?? "";
$popupType = isset($_SESSION['success']) ? "success" : (isset($_SESSION['error']) ? "error" : "");
unset($_SESSION['success'], $_SESSION['error']);

function arBadge($status) {
    return match ($status) {
        'Paid' => 'badge paid',
        'Unpaid' => 'badge unpaid',
        'Partially Paid' => 'badge partial',
        'Overdue' => 'badge overdue',
        default => 'badge'
    };
}

function renderReceivableDynamicArea(array $summary, array $rows, array $recentPayments = [], array $aging = []): void
{
    ?>
    <section class="receivable-summary" aria-label="Receivable overview">
        <article class="summary-card grad-1">
            <div class="summary-card-head">
                <span class="summary-icon"><i class="bi bi-receipt" aria-hidden="true"></i></span>
                <span class="summary-label">Total Receivables</span>
            </div>
            <strong class="summary-value"><?php echo (int)($summary['total_receivables'] ?? 0); ?></strong>
        </article>

        <article class="summary-card grad-2">
            <div class="summary-card-head">
                <span class="summary-icon"><i class="bi bi-wallet2" aria-hidden="true"></i></span>
                <span class="summary-label">Outstanding Balance</span>
            </div>
            <strong class="summary-value">₱<?php echo number_format((float)($summary['total_balance_due'] ?? 0), 2); ?></strong>
        </article>

        <article class="summary-card grad-4">
            <div class="summary-card-head">
                <span class="summary-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span>
                <span class="summary-label">Overdue</span>
            </div>
            <strong class="summary-value"><?php echo (int)($summary['overdue_count'] ?? 0); ?></strong>
        </article>

        <article class="summary-card grad-3">
            <div class="summary-card-head">
                <span class="summary-icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
                <span class="summary-label">Unpaid</span>
            </div>
            <strong class="summary-value"><?php echo (int)($summary['unpaid_count'] ?? 0); ?></strong>
        </article>

        <article class="summary-card grad-5">
            <div class="summary-card-head">
                <span class="summary-icon"><i class="bi bi-pie-chart" aria-hidden="true"></i></span>
                <span class="summary-label">Partially Paid</span>
            </div>
            <strong class="summary-value"><?php echo (int)($summary['partial_count'] ?? 0); ?></strong>
        </article>
    </section>

    <section class="receivable-table-card">
        <div class="receivable-list-header">
            <div>
                <span class="section-kicker">Customer Ledger</span>
                <h2>Receivable Accounts</h2>
            </div>
            <span class="record-count"><i class="bi bi-people" aria-hidden="true"></i> <?php echo count($rows); ?> record<?php echo count($rows) === 1 ? '' : 's'; ?></span>
        </div>
        <div class="table-scroll table-scroll-7">
            <table class="receivable-table">
                <thead>
                    <tr>
                        <th>Sales No.</th>
                        <th>Customer</th>
                        <th>Total Amount</th>
                        <th>Amount Paid</th>
                        <th>Balance Due</th>
                        <th class="due-date-col">Due Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $liveStatus = $row['live_status'] ?? $row['status'] ?? 'Unpaid';
                            $rowId = (int)($row['id'] ?? 0);
                            $rowStatusClass = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)$liveStatus)), '-');
                            $customerName = trim((string)($row['customer_name'] ?? ''));
                            $customerInitial = $customerName !== '' ? mb_strtoupper(mb_substr($customerName, 0, 1)) : '?';
                            $totalAmount = number_format((float)($row['total_amount'] ?? 0), 2);
                            $amountPaid = number_format((float)($row['amount_paid'] ?? 0), 2);
                            $balanceDue = number_format((float)($row['balance_due'] ?? 0), 2);
                            $dueDateDisplay = !empty($row['due_date']) ? htmlspecialchars($row['due_date']) : 'N/A';
                            $invoiceDateDisplay = !empty($row['created_at']) ? htmlspecialchars(date('M d, Y', strtotime($row['created_at']))) : 'N/A';
                        ?>
                        <tr class="receivable-row status-<?php echo htmlspecialchars($rowStatusClass); ?>">
                            <td data-label="Sales No." class="sales-no-cell">
                                <span class="ar-cell-value ar-sales-number"><?php echo htmlspecialchars($row['sales_no'] ?? ''); ?></span>
                            </td>
                            <td data-label="Customer" class="customer-cell">
                                <div class="customer-identity">
                                    <span class="customer-avatar" aria-hidden="true"><?php echo htmlspecialchars($customerInitial); ?></span>
                                    <span class="customer-details">
                                        <strong><?php echo htmlspecialchars($customerName); ?></strong>
                                        <small>Customer account</small>
                                    </span>
                                </div>
                            </td>
                            <td data-label="Total Amount" class="total-cell"><span class="ar-cell-value">₱<?php echo $totalAmount; ?></span></td>
                            <td data-label="Amount Paid" class="paid-cell"><span class="ar-cell-value">₱<?php echo $amountPaid; ?></span></td>
                            <td data-label="Balance Due" class="balance-cell"><strong class="ar-cell-value">₱<?php echo $balanceDue; ?></strong></td>
                            <td data-label="Due Date" class="due-date-col"><span class="ar-cell-value"><i class="bi bi-calendar3" aria-hidden="true"></i><?php echo $dueDateDisplay; ?></span></td>
                            <td data-label="Status" class="status-cell">
                                <span class="<?php echo arBadge($liveStatus); ?>">
                                    <?php echo htmlspecialchars($liveStatus); ?>
                                </span>
                            </td>
                            <td data-label="Action" class="action-td">
                                <div class="action-cell">
                                    <button
                                        type="button"
                                        class="icon-btn view"
                                        aria-label="View receivable details for <?php echo htmlspecialchars($row['sales_no'] ?? 'this sale'); ?>"
                                        title="View receivable details"
                                        data-id="<?php echo $rowId; ?>"
                                        data-sales-no="<?php echo htmlspecialchars($row['sales_no'] ?? ''); ?>"
                                        data-customer="<?php echo htmlspecialchars($row['customer_name'] ?? ''); ?>"
                                        data-invoice-date="<?php echo $invoiceDateDisplay; ?>"
                                        data-due-date="<?php echo $dueDateDisplay; ?>"
                                        data-total="₱<?php echo $totalAmount; ?>"
                                        data-paid="₱<?php echo $amountPaid; ?>"
                                        data-balance="₱<?php echo $balanceDue; ?>"
                                        data-status="<?php echo htmlspecialchars($liveStatus); ?>"
                                    ><i class="bi bi-eye"></i> View</button>
                                    <?php if (strcasecmp($liveStatus, 'Paid') === 0): ?>
                                        <span class="icon-btn pay disabled" aria-disabled="true" title="This receivable is fully paid">
                                            <i class="bi bi-check2-circle"></i> Paid
                                        </span>
                                    <?php else: ?>
                                        <a href="<?php echo htmlspecialchars(nxAppUrl('receivable_payment.php?id=' . $rowId), ENT_QUOTES, 'UTF-8'); ?>" class="icon-btn pay">
                                            <i class="bi bi-cash-coin"></i> Update Payment
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="8" class="empty-state">No receivable records found.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="receivable-bottom-grid">
        <div class="aging-card">
            <?php
                $agingBuckets = [
                    ['label' => 'Current',    'key' => 'current_amt', 'color' => '#22c55e'],
                    ['label' => '1-30 Days',  'key' => 'd1_30',       'color' => '#facc15'],
                    ['label' => '31-60 Days', 'key' => 'd31_60',      'color' => '#fb923c'],
                    ['label' => '61-90 Days', 'key' => 'd61_90',      'color' => '#ef4444'],
                    ['label' => '90+ Days',   'key' => 'd90_plus',    'color' => '#1f2937'],
                ];

                $agingTotal = 0;
                foreach ($agingBuckets as $b) {
                    $agingTotal += (float)($aging[$b['key']] ?? 0);
                }

                $gradientStops = [];
                $cursor = 0;
                foreach ($agingBuckets as $b) {
                    $amt = (float)($aging[$b['key']] ?? 0);
                    $pct = $agingTotal > 0 ? ($amt / $agingTotal * 100) : 0;
                    $start = $cursor;
                    $end = $cursor + $pct;
                    $gradientStops[] = "{$b['color']} " . number_format($start, 3) . "% " . number_format($end, 3) . "%";
                    $cursor = $end;
                }
                $donutCss = $agingTotal > 0
                    ? implode(', ', $gradientStops)
                    : 'rgba(255,255,255,0.08) 0% 100%';
            ?>

            <div class="aging-card-header">
                <h2>Aging Report</h2>
                <div class="aging-total-pill">
                    <span class="aging-total-label">Total Outstanding</span>
                    <span class="aging-total-value">₱<?php echo number_format($agingTotal, 2); ?></span>
                </div>
            </div>

            <div class="aging-body">
                <div class="aging-donut-wrap">
                    <div class="aging-donut" style="background: conic-gradient(<?php echo $donutCss; ?>);">
                        <div class="aging-donut-hole"></div>
                    </div>
                </div>

                <div class="aging-legend">
                    <?php foreach ($agingBuckets as $b): ?>
                        <?php
                            $amt = (float)($aging[$b['key']] ?? 0);
                            $pct = $agingTotal > 0 ? ($amt / $agingTotal * 100) : 0;
                        ?>
                        <div class="aging-legend-item">
                            <span class="aging-dot" style="background:<?php echo $b['color']; ?>;"></span>
                            <span class="aging-legend-label"><?php echo $b['label']; ?></span>
                            <span class="aging-legend-amount">₱<?php echo number_format($amt, 2); ?></span>
                            <span class="aging-legend-pct">(<?php echo number_format($pct, 0); ?>%)</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="recent-payments-card">
            <div class="aging-card-header">
                <h2>Recent Payments</h2>
                <?php if (!empty($recentPayments)): ?>
                    <?php
                        $recentPaymentsSum = 0;
                        foreach ($recentPayments as $p) {
                            $recentPaymentsSum += (float)($p['amount_paid'] ?? 0);
                        }
                    ?>
                    <div class="aging-total-pill">
                        <span class="aging-total-label">Last <?php echo count($recentPayments); ?> Payments</span>
                        <span class="aging-total-value">₱<?php echo number_format($recentPaymentsSum, 2); ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="recent-payments-list">
                <?php if (!empty($recentPayments)): ?>
                    <?php
                        $avatarPalettes = [
                            ['#3b82f6', '#0033ff'],
                            ['#ffdd55', '#f6cb08'],
                            ['#7dd3fc', '#0ea5e9'],
                            ['#a5b4fc', '#3b82f6'],
                            ['#fbbf24', '#f59e0b'],
                        ];
                    ?>
                    <?php foreach ($recentPayments as $i => $payment): ?>
                        <?php
                            $customerName = trim($payment['customer_name'] ?? '');
                            $nameParts = preg_split('/\s+/', $customerName);
                            $initials = '';
                            if (!empty($nameParts[0])) { $initials .= mb_substr($nameParts[0], 0, 1); }
                            if (!empty($nameParts[1])) { $initials .= mb_substr($nameParts[1], 0, 1); }
                            $initials = mb_strtoupper($initials ?: '?');
                            $palette = $avatarPalettes[$i % count($avatarPalettes)];
                        ?>
                        <div class="recent-payment-item">
                            <div class="recent-payment-avatar" style="background: linear-gradient(135deg, <?php echo $palette[0]; ?> 0%, <?php echo $palette[1]; ?> 100%);">
                                <?php echo htmlspecialchars($initials); ?>
                                <span class="recent-payment-check">✔</span>
                            </div>
                            <div class="recent-payment-mid">
                                <span class="recent-payment-customer"><?php echo htmlspecialchars($customerName); ?></span>
                                <span class="recent-payment-date"><?php echo !empty($payment['updated_at']) ? htmlspecialchars(date('M d, Y', strtotime($payment['updated_at']))) : 'N/A'; ?></span>
                            </div>
                            <span class="recent-payment-amount">₱<?php echo number_format((float)($payment['amount_paid'] ?? 0), 2); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p class="empty-state">No recent payments yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php
}

if ($isAjax) {
    renderReceivableDynamicArea($summary, $rows, $recentPayments, $aging);
    exit;
}

$overdueCount = (int)($summary['overdue_count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <?php include __DIR__ . '/theme_init.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts Receivable - NexGen</title>
    <link rel="stylesheet" href="<?php echo htmlspecialchars(nxCodeUrl('STYLE/header.css?v=2'), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(nxCodeUrl('STYLE/accounts_receivable.css?v=10'), ENT_QUOTES, 'UTF-8'); ?>">
        <link rel="stylesheet" href="<?php echo htmlspecialchars(nxCodeUrl('STYLE/module_footer.css'), ENT_QUOTES, 'UTF-8'); ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .overdue-alert-wrap {
            position: fixed;
            top: 92px;
            right: 22px;
            z-index: 9999;
            width: min(380px, calc(100vw - 24px));
        }

        .overdue-alert {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: linear-gradient(135deg, rgba(9, 22, 82, 0.96), rgba(4, 8, 38, 0.96));
            color: #eef5ff;
            border: 1px solid rgba(246, 203, 8, 0.4);
            border-left: 6px solid #f6cb08;
            border-radius: 18px;
            padding: 16px 18px;
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.45);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            opacity: 0;
            transform: translateY(-12px);
            transition: opacity 0.3s ease, transform 0.3s ease;
        }

        .overdue-alert.show {
            opacity: 1;
            transform: translateY(0);
        }

        .overdue-alert-icon {
            flex: 0 0 42px;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(246, 203, 8, 0.16);
            color: #ffdd55;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .overdue-alert-content h4 {
            margin: 0 0 4px;
            font-size: 16px;
            font-weight: 800;
            color: #ffdd55;
        }

        .overdue-alert-content p {
            margin: 0;
            font-size: 14px;
            line-height: 1.45;
            color: #b9d4f5;
        }

        .overdue-alert-content strong {
            color: #ffffff;
        }

        .overdue-alert-close {
            margin-left: auto;
            border: none;
            background: transparent;
            color: #b9d4f5;
            font-size: 18px;
            cursor: pointer;
            line-height: 1;
            padding: 2px;
        }

        .overdue-alert-close:hover {
            color: #ffdd55;
        }

        @media (max-width: 640px) {
            .overdue-alert-wrap {
                top: 84px;
                right: 12px;
                left: 12px;
                width: auto;
            }
        }

        .icon-btn.pay.disabled {
            background: rgba(255, 255, 255, 0.12) !important;
            color: rgba(255, 255, 255, 0.5) !important;
            cursor: not-allowed;
            pointer-events: none;
            box-shadow: none !important;
        }

        /* ---------------- View Modal ---------------- */
        .ar-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(8, 21, 47, 0.72);
            z-index: 999;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .ar-modal-overlay.show {
            display: flex;
        }

        .ar-modal {
            width: 100%;
            max-width: 640px;
            max-height: 88vh;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            background: linear-gradient(180deg, #0e1f47, #16305f);
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 20px;
            padding: 28px;
            position: relative;
            color: #fff;
            box-shadow: 0 24px 60px rgba(0, 0, 0, 0.45);
        }

        .ar-modal-titlebar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
            padding-bottom: 18px;
            padding-right: 54px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        .ar-modal-eyebrow {
            display: block;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: rgba(255, 255, 255, 0.55);
            margin-bottom: 4px;
        }

        .ar-modal-titlebar h2 {
            margin: 0;
            font-size: 1.3rem;
            padding-right: 0;
        }

        .ar-modal-status-badge {
            flex-shrink: 0;
            padding: 7px 16px;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 700;
            white-space: nowrap;
            background: rgba(255, 255, 255, 0.15);
        }

        .ar-modal-status-badge.status-paid {
            background: rgba(45, 201, 138, 0.2);
            color: #4ee2a6;
        }

        .ar-modal-status-badge.status-partially-paid {
            background: rgba(250, 204, 21, 0.2);
            color: #facc15;
        }

        .ar-modal-status-badge.status-unpaid {
            background: rgba(148, 163, 184, 0.25);
            color: #cbd5e1;
        }

        .ar-modal-status-badge.status-overdue {
            background: rgba(240, 104, 95, 0.22);
            color: #f5847c;
        }

        .ar-modal-section-last {
            margin-bottom: 4px;
        }

        .ar-modal h3 {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ar-modal h2 {
            margin: 0 0 18px;
            font-size: 1.35rem;
        }

        .ar-modal h3 {
            margin: 0 0 12px;
            font-size: 1.05rem;
        }

        .ar-modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 44px;
            height: 44px;
            border: none;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            font-size: 1.3rem;
            line-height: 1;
            cursor: pointer;
        }

        .ar-modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .ar-modal-section {
            margin-bottom: 22px;
        }

        .ar-modal-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .ar-modal-label {
            display: block;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: rgba(255, 255, 255, 0.6);
            margin-bottom: 4px;
        }

        .ar-modal-value {
            display: block;
            font-size: 1rem;
            font-weight: 600;
            word-break: break-word;
        }

        .ar-modal-summary-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
        }

        .ar-modal-summary-card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 14px;
            padding: 14px;
            border-left: 3px solid transparent;
        }

        .ar-modal-summary-card.is-total {
            border-left-color: rgba(255, 255, 255, 0.35);
        }

        .ar-modal-summary-card.is-paid {
            border-left-color: #4ee2a6;
        }

        .ar-modal-summary-card.is-balance {
            border-left-color: #facc15;
        }

        .ar-modal-summary-card strong {
            display: block;
            font-size: 1.15rem;
            margin-top: 6px;
        }

        .ar-modal-table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .ar-modal-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 480px;
        }

        .ar-modal-table th,
        .ar-modal-table td {
            padding: 10px 14px;
            text-align: left;
            font-size: 0.9rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            white-space: nowrap;
        }

        .ar-modal-table th {
            background: rgba(255, 255, 255, 0.06);
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .ar-modal-loading {
            text-align: center;
            color: rgba(255, 255, 255, 0.65);
            white-space: normal;
            padding: 22px 14px !important;
        }

        /* ---------------- Other Transactions (same customer) ---------------- */
        .ar-modal-customer-tag {
            font-size: 0.78rem;
            font-weight: 500;
            color: rgba(255, 255, 255, 0.5);
        }

        .ar-modal-other-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 260px;
            overflow-y: auto;
            padding: 2px 4px 2px 2px;
            -webkit-overflow-scrolling: touch;
        }

        .ar-modal-other-list::-webkit-scrollbar {
            width: 8px;
        }

        .ar-modal-other-list::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.18);
            border-radius: 999px;
        }

        .ar-modal-other-list::-webkit-scrollbar-track {
            background: transparent;
        }

        .ar-modal-other-list p.ar-modal-loading {
            padding: 16px 4px;
        }

        .ar-modal-other-row {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.09) 0%, rgba(255, 255, 255, 0.03) 100%);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 14px;
            padding: 12px 14px 12px 16px;
            color: #fff;
            font-family: inherit;
            text-align: left;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.18);
            transition: background 0.22s ease, border-color 0.22s ease, transform 0.22s ease, box-shadow 0.22s ease;
        }

        .ar-modal-other-row::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: rgba(255, 255, 255, 0.3);
            opacity: 0.9;
        }

        .ar-modal-other-row.accent-paid::before {
            background: linear-gradient(180deg, #4ee2a6, #059669);
        }

        .ar-modal-other-row.accent-partially-paid::before {
            background: linear-gradient(180deg, #ffe27a, #f6cb08);
        }

        .ar-modal-other-row.accent-unpaid::before {
            background: linear-gradient(180deg, #cbd5e1, #7d93ad);
        }

        .ar-modal-other-row.accent-overdue::before {
            background: linear-gradient(180deg, #ff9d94, #f5847c);
        }

        .ar-modal-other-row:hover,
        .ar-modal-other-row:focus-visible {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.15) 0%, rgba(255, 255, 255, 0.05) 100%);
            border-color: rgba(255, 255, 255, 0.26);
            transform: translateY(-2px);
            box-shadow: 0 10px 22px rgba(0, 0, 0, 0.28);
        }

        .ar-modal-other-row:hover .ar-modal-other-chevron,
        .ar-modal-other-row:focus-visible .ar-modal-other-chevron {
            transform: translateX(2px);
            opacity: 0.9;
        }

        .ar-modal-other-icon {
            flex-shrink: 0;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.08);
            color: rgba(255, 255, 255, 0.75);
        }

        .ar-modal-other-row.accent-paid .ar-modal-other-icon {
            background: rgba(78, 226, 166, 0.16);
            color: #4ee2a6;
        }

        .ar-modal-other-row.accent-partially-paid .ar-modal-other-icon {
            background: rgba(246, 203, 8, 0.16);
            color: #facc15;
        }

        .ar-modal-other-row.accent-unpaid .ar-modal-other-icon {
            background: rgba(203, 213, 225, 0.14);
            color: #cbd5e1;
        }

        .ar-modal-other-row.accent-overdue .ar-modal-other-icon {
            background: rgba(245, 132, 124, 0.16);
            color: #f5847c;
        }

        .ar-modal-other-main {
            display: flex;
            flex-direction: column;
            gap: 2px;
            min-width: 0;
            flex: 1;
        }

        .ar-modal-other-sales {
            font-size: 0.9rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ar-modal-other-date {
            font-size: 0.74rem;
            color: rgba(255, 255, 255, 0.55);
        }

        .ar-modal-other-side {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 5px;
            flex-shrink: 0;
        }

        .ar-modal-other-amount {
            font-size: 0.92rem;
            font-weight: 800;
            font-family: 'Poppins', 'Inter', sans-serif;
        }

        .ar-modal-status-badge-sm {
            padding: 3px 10px;
            font-size: 0.64rem;
        }

        .ar-modal-other-chevron {
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.35);
            font-size: 0.9rem;
            opacity: 0.6;
            transition: transform 0.22s ease, opacity 0.22s ease;
        }

        @media (max-width: 600px) {
            .ar-modal {
                padding: 20px 16px;
                border-radius: 16px;
                max-height: 92vh;
            }

            .ar-modal-grid {
                grid-template-columns: 1fr;
            }

            .ar-modal-summary-cards {
                grid-template-columns: 1fr;
            }

            .ar-modal-titlebar {
                flex-direction: column;
                gap: 10px;
                padding-right: 54px;
            }

            .ar-modal h2 {
                font-size: 1.1rem;
            }

            .ar-modal-other-list {
                max-height: 200px;
            }

            .ar-modal-other-row {
                padding: 10px 10px 10px 14px;
                gap: 10px;
            }

            .ar-modal-other-icon {
                width: 30px;
                height: 30px;
                font-size: 0.86rem;
                border-radius: 9px;
            }

            .ar-modal-other-sales {
                font-size: 0.84rem;
            }

            .ar-modal-other-amount {
                font-size: 0.82rem;
            }

            .ar-modal-other-chevron {
                display: none;
            }
        }

        /* App-style receivable detail sheet */
        .ar-modal-grabber {
            display: none;
        }

        .ar-modal-actions {
            display: none;
        }

        html[data-theme="light"] .ar-modal-overlay {
            background: rgba(15, 35, 70, 0.42);
        }

        html[data-theme="light"] .ar-modal {
            background: linear-gradient(180deg, #ffffff, #eaf4ff);
            border-color: rgba(59, 130, 246, 0.2);
            color: #0b1f73;
            box-shadow: 0 24px 60px rgba(34, 70, 120, 0.28);
        }

        html[data-theme="light"] .ar-modal-titlebar {
            border-bottom-color: rgba(59, 130, 246, 0.14);
        }

        html[data-theme="light"] .ar-modal-eyebrow,
        html[data-theme="light"] .ar-modal-label,
        html[data-theme="light"] .ar-modal-customer-tag,
        html[data-theme="light"] .ar-modal-other-date {
            color: #55709f;
        }

        html[data-theme="light"] .ar-modal-close {
            background: rgba(59, 130, 246, 0.1);
            color: #0b1f73;
        }

        html[data-theme="light"] .ar-modal-summary-card,
        html[data-theme="light"] .ar-modal-other-row {
            background: rgba(255, 255, 255, 0.72);
            border-color: rgba(59, 130, 246, 0.16);
            color: #0b1f73;
            box-shadow: 0 5px 16px rgba(57, 88, 134, 0.1);
        }

        html[data-theme="light"] .ar-modal-other-icon {
            background: rgba(59, 130, 246, 0.1);
        }

        html[data-theme="light"] .ar-modal-table-wrap,
        html[data-theme="light"] .ar-modal-table th,
        html[data-theme="light"] .ar-modal-table td {
            border-color: rgba(59, 130, 246, 0.14);
        }

        html[data-theme="light"] .ar-modal-table th {
            background: rgba(59, 130, 246, 0.08);
        }

        html[data-theme="light"] .ar-modal-loading {
            color: #55709f;
        }

        @keyframes arSheetIn {
            from { transform: translateY(32px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        @media (max-width: 720px) {
            .overdue-alert-wrap {
                top: auto;
                right: 10px;
                bottom: calc(12px + env(safe-area-inset-bottom));
                left: 10px;
                width: auto;
            }

            .overdue-alert {
                align-items: center;
                padding: 12px 13px;
                border-left-width: 4px;
                border-radius: 16px;
            }

            .overdue-alert-icon {
                flex-basis: 36px;
                width: 36px;
                height: 36px;
                border-radius: 11px;
                font-size: 16px;
            }

            .overdue-alert-content h4 {
                font-size: 13px;
            }

            .overdue-alert-content p {
                font-size: 11px;
                line-height: 1.35;
            }

            .ar-modal-overlay {
                align-items: flex-end;
                padding: 0;
                background: rgba(3, 9, 28, 0.76);
                backdrop-filter: blur(5px);
                -webkit-backdrop-filter: blur(5px);
            }

            .ar-modal-overlay.show .ar-modal {
                animation: arSheetIn 0.24s ease-out both;
            }

            .ar-modal {
                width: 100%;
                max-width: none;
                max-height: calc(100dvh - 58px);
                margin: 0;
                padding: 25px 14px max(20px, env(safe-area-inset-bottom));
                border-right: 0;
                border-bottom: 0;
                border-left: 0;
                border-radius: 26px 26px 0 0;
                overscroll-behavior: contain;
                box-shadow: 0 -18px 52px rgba(0, 0, 0, 0.46);
            }

            .ar-modal-grabber {
                display: block;
                width: 44px;
                height: 5px;
                margin: -13px auto 14px;
                border-radius: 999px;
                background: rgba(255, 255, 255, 0.3);
            }

            html[data-theme="light"] .ar-modal-grabber {
                background: rgba(11, 31, 115, 0.2);
            }

            .ar-modal-close {
                top: 18px;
                right: 14px;
                width: 40px;
                height: 40px;
                border-radius: 13px;
                font-size: 1.18rem;
                z-index: 4;
            }

            .ar-modal-titlebar {
                display: grid;
                grid-template-columns: minmax(0, 1fr) auto;
                gap: 8px;
                margin-bottom: 14px;
                padding: 0 48px 13px 1px;
            }

            .ar-modal-titlebar > div {
                min-width: 0;
            }

            .ar-modal-titlebar h2 {
                max-width: 100%;
                overflow: hidden;
                font-size: 1.08rem;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .ar-modal-eyebrow {
                font-size: 0.62rem;
            }

            .ar-modal-titlebar > .ar-modal-status-badge {
                align-self: end;
                padding: 6px 9px;
                font-size: 0.62rem;
            }

            .ar-modal-section {
                margin-bottom: 15px;
            }

            .ar-modal-section h3 {
                margin-bottom: 9px;
                font-size: 0.9rem;
            }

            .ar-modal-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .ar-modal-grid > div {
                min-width: 0;
                padding: 10px 11px;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 13px;
                background: rgba(255, 255, 255, 0.055);
            }

            .ar-modal-grid > div:first-child {
                grid-column: 1 / -1;
            }

            html[data-theme="light"] .ar-modal-grid > div {
                border-color: rgba(59, 130, 246, 0.14);
                background: rgba(255, 255, 255, 0.62);
            }

            .ar-modal-label {
                margin-bottom: 4px;
                font-size: 0.61rem;
            }

            .ar-modal-value {
                overflow-wrap: anywhere;
                font-size: 0.83rem;
            }

            .ar-modal-summary-cards {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .ar-modal-summary-card {
                min-width: 0;
                padding: 11px;
                border-radius: 13px;
            }

            .ar-modal-summary-card.is-balance {
                grid-column: 1 / -1;
                background: linear-gradient(135deg, rgba(250, 204, 21, 0.13), rgba(255, 255, 255, 0.055));
            }

            html[data-theme="light"] .ar-modal-summary-card.is-balance {
                background: linear-gradient(135deg, rgba(250, 204, 21, 0.13), rgba(255, 255, 255, 0.78));
            }

            .ar-modal-summary-card strong {
                margin-top: 3px;
                overflow-wrap: anywhere;
                font-size: 0.94rem;
            }

            .ar-modal-actions {
                display: block;
                margin: -3px 0 15px;
            }

            .ar-modal-payment-action {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                width: 100%;
                min-height: 46px;
                padding: 10px 16px;
                border: 1px solid rgba(246, 203, 8, 0.48);
                border-radius: 14px;
                background: linear-gradient(135deg, #ffdd55, #f6cb08);
                color: #2b1a00;
                font-family: 'Inter', sans-serif;
                font-size: 0.84rem;
                font-weight: 800;
                text-decoration: none;
                box-shadow: 0 10px 22px rgba(246, 203, 8, 0.2);
            }

            .ar-modal-payment-action.is-disabled {
                border-color: rgba(52, 211, 153, 0.25);
                background: rgba(52, 211, 153, 0.12);
                color: #5eead4;
                box-shadow: none;
                pointer-events: none;
            }

            html[data-theme="light"] .ar-modal-payment-action.is-disabled {
                color: #087f65;
            }

            .ar-modal-table-wrap {
                overflow: visible;
                border: 0;
                border-radius: 0;
            }

            .ar-modal-table {
                display: block;
                width: 100%;
                min-width: 0;
            }

            .ar-modal-table thead {
                display: none;
            }

            .ar-modal-table tbody {
                display: grid;
                gap: 8px;
            }

            .ar-modal-table tbody tr {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0;
                overflow: hidden;
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 13px;
                background: rgba(255, 255, 255, 0.055);
            }

            html[data-theme="light"] .ar-modal-table tbody tr {
                border-color: rgba(59, 130, 246, 0.14);
                background: rgba(255, 255, 255, 0.64);
            }

            .ar-modal-table tbody td {
                display: flex;
                min-width: 0;
                flex-direction: column;
                gap: 3px;
                padding: 9px 10px;
                border: 0;
                white-space: normal;
            }

            .ar-modal-table tbody td::before {
                content: attr(data-label);
                color: rgba(255, 255, 255, 0.5);
                font-size: 0.57rem;
                font-weight: 750;
                letter-spacing: 0.04em;
                text-transform: uppercase;
            }

            html[data-theme="light"] .ar-modal-table tbody td::before {
                color: #55709f;
            }

            .ar-modal-table tbody td span,
            .ar-modal-table tbody td strong {
                overflow-wrap: anywhere;
                font-size: 0.75rem;
            }

            .ar-modal-table tbody td strong {
                color: #ffdd55;
            }

            html[data-theme="light"] .ar-modal-table tbody td strong {
                color: #8d6212;
            }

            .ar-modal-table tbody td.ar-modal-loading {
                display: block;
                grid-column: 1 / -1;
                padding: 18px 10px !important;
                text-align: center;
            }

            .ar-modal-table tbody td.ar-modal-loading::before {
                display: none;
            }

            .ar-modal-other-list {
                max-height: 220px;
                gap: 8px;
            }

            .ar-modal-other-row {
                min-height: 58px;
                padding: 10px 9px 10px 13px;
                border-radius: 13px;
            }

            .ar-modal-other-side {
                max-width: 42%;
            }

            .ar-modal-other-amount {
                max-width: 100%;
                overflow-wrap: anywhere;
                text-align: right;
            }
        }

        @media (max-width: 380px) {
            .ar-modal {
                padding-right: 11px;
                padding-left: 11px;
            }

            .ar-modal-other-icon {
                display: none;
            }

            .ar-modal-other-row {
                gap: 7px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .ar-modal-overlay.show .ar-modal {
                animation: none;
            }
        }
    </style>
</head>
<body>

<?php include 'header.php'; ?>

<?php if (!empty($popupMessage)): ?>
<div class="popup-overlay" id="popupOverlay">
    <div class="popup-box <?php echo $popupType; ?>" id="popupBox">
        <h3><?php echo $popupType === 'success' ? 'Success' : 'Error'; ?></h3>
        <p><?php echo htmlspecialchars($popupMessage); ?></p>
    </div>
</div>
<?php endif; ?>

<?php if ($overdueCount > 0): ?>
<div class="overdue-alert-wrap" id="overdueAlertWrap">
    <div class="overdue-alert" id="overdueAlertBox">
        <div class="overdue-alert-icon">
            <i class="bi bi-exclamation-triangle-fill"></i>
        </div>

        <div class="overdue-alert-content">
            <h4>Overdue Payment Alert</h4>
            <p>
                You currently have
                <strong><?php echo $overdueCount; ?></strong>
                overdue payment<?php echo $overdueCount > 1 ? 's' : ''; ?> that need<?php echo $overdueCount > 1 ? '' : 's'; ?> attention.
            </p>
        </div>

        <button type="button" class="overdue-alert-close" id="overdueAlertClose">&times;</button>
    </div>
</div>
<?php endif; ?>

<div class="receivable-page">
    <div class="receivable-shell">

        <section class="receivable-hero">
            <div class="receivable-hero-text">
                <span class="receivable-hero-kicker"><i class="bi bi-credit-card-2-front" aria-hidden="true"></i> Finance Workspace</span>
                <h1>Accounts Receivable</h1>
                <p>Track customer balances, due dates, and incoming payments.</p>
            </div>
        </section>

        <section class="receivable-toolbar-card">
            <div class="toolbar-heading">
                <div>
                    <span class="section-kicker">Quick Find</span>
                    <h2>Search &amp; filter</h2>
                </div>
                <span class="toolbar-heading-icon"><i class="bi bi-sliders2" aria-hidden="true"></i></span>
            </div>
            <form method="GET" class="receivable-toolbar" id="receivableFilterForm">
                <label class="toolbar-field toolbar-search-field" for="receivableSearchInput">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input
                        type="search"
                        name="search"
                        class="toolbar-input"
                        id="receivableSearchInput"
                        placeholder="Customer or sales number"
                        value="<?php echo htmlspecialchars($search); ?>"
                        autocomplete="off"
                    >
                </label>

                <label class="toolbar-field toolbar-select-field" for="receivableStatusSelect">
                    <i class="bi bi-funnel" aria-hidden="true"></i>
                    <select name="status" class="toolbar-select" id="receivableStatusSelect">
                        <option value="">All Status</option>
                        <option value="Unpaid" <?php echo $statusFilter === 'Unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                        <option value="Partially Paid" <?php echo $statusFilter === 'Partially Paid' ? 'selected' : ''; ?>>Partially Paid</option>
                        <option value="Paid" <?php echo $statusFilter === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                        <option value="Overdue" <?php echo $statusFilter === 'Overdue' ? 'selected' : ''; ?>>Overdue</option>
                    </select>
                    <i class="bi bi-chevron-down toolbar-select-chevron" aria-hidden="true"></i>
                </label>

                <button type="submit" class="toolbar-btn primary-btn"><i class="bi bi-check2" aria-hidden="true"></i><span>Apply</span></button>
                <a href="<?php echo htmlspecialchars(nxAppUrl('accounts_receivable.php'), ENT_QUOTES, 'UTF-8'); ?>" class="toolbar-btn secondary-btn" id="receivableResetBtn"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i><span>Reset</span></a>
            </form>
        </section>

        <div id="receivableDynamicArea">
            <?php renderReceivableDynamicArea($summary, $rows, $recentPayments, $aging); ?>
        </div>

    </div>
</div>

<div class="ar-modal-overlay" id="arViewModalOverlay">
    <div class="ar-modal" role="dialog" aria-modal="true" aria-labelledby="arViewModalTitle">
        <span class="ar-modal-grabber" aria-hidden="true"></span>
        <button type="button" class="ar-modal-close" id="arViewModalClose" aria-label="Close">&times;</button>

        <div class="ar-modal-titlebar">
            <div>
                <span class="ar-modal-eyebrow">Sales No.</span>
                <h2 id="arViewModalTitle"><span id="arModalSalesNo">-</span></h2>
            </div>
            <span class="ar-modal-status-badge" id="arModalStatus">-</span>
        </div>

        <div class="ar-modal-section">
            <div class="ar-modal-grid">
                <div><span class="ar-modal-label">Customer</span><span class="ar-modal-value" id="arModalCustomer">-</span></div>
                <div><span class="ar-modal-label">Invoice Date</span><span class="ar-modal-value" id="arModalInvoiceDate">-</span></div>
                <div><span class="ar-modal-label">Due Date</span><span class="ar-modal-value" id="arModalDueDate">-</span></div>
            </div>
        </div>

        <div class="ar-modal-section">
            <div class="ar-modal-summary-cards">
                <div class="ar-modal-summary-card is-total">
                    <span class="ar-modal-label">Total Amount</span>
                    <strong id="arModalTotal">-</strong>
                </div>
                <div class="ar-modal-summary-card is-paid">
                    <span class="ar-modal-label">Amount Paid</span>
                    <strong id="arModalPaid">-</strong>
                </div>
                <div class="ar-modal-summary-card is-balance">
                    <span class="ar-modal-label">Remaining Balance</span>
                    <strong id="arModalBalance">-</strong>
                </div>
            </div>
        </div>

        <div class="ar-modal-actions">
            <a href="#" class="ar-modal-payment-action" id="arModalPaymentAction">
                <i class="bi bi-cash-coin" aria-hidden="true"></i>
                <span>Update Payment</span>
            </a>
        </div>

        <div class="ar-modal-section">
            <h3><i class="bi bi-clock-history"></i> Payment History</h3>
            <div class="ar-modal-table-wrap">
                <table class="ar-modal-table" id="arModalPaymentTable">
                    <thead>
                        <tr>
                            <th>Payment Date</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Reference No.</th>
                        </tr>
                    </thead>
                    <tbody id="arModalPaymentBody">
                        <tr><td colspan="4" class="ar-modal-loading">Loading payment history...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="ar-modal-section ar-modal-section-last">
            <h3><i class="bi bi-people"></i> Other Transactions <span class="ar-modal-customer-tag" id="arModalOtherCustomerTag"></span></h3>
            <div class="ar-modal-other-list" id="arModalOtherList">
                <p class="ar-modal-loading">Loading...</p>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars(nxCodeUrl('JS/header.js?v=2'), ENT_QUOTES, 'UTF-8'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const arPageUrl = <?php echo json_encode(nxAppUrl('accounts_receivable.php'), JSON_UNESCAPED_SLASHES); ?>;
    const arPaymentUrl = <?php echo json_encode(nxAppUrl('receivable_payment.php'), JSON_UNESCAPED_SLASHES); ?>;
    const arViewUrl = <?php echo json_encode(nxAppUrl('receivable_view.php'), JSON_UNESCAPED_SLASHES); ?>;
    const popupOverlay = document.getElementById('popupOverlay');
    const popupBox = document.getElementById('popupBox');
    const overdueAlertWrap = document.getElementById('overdueAlertWrap');
    const overdueAlertBox = document.getElementById('overdueAlertBox');
    const overdueAlertClose = document.getElementById('overdueAlertClose');

    const filterForm = document.getElementById('receivableFilterForm');
    const searchInput = document.getElementById('receivableSearchInput');
    const statusSelect = document.getElementById('receivableStatusSelect');
    const dynamicArea = document.getElementById('receivableDynamicArea');
    const resetBtn = document.getElementById('receivableResetBtn');

    let activeRequest = null;

    if (popupOverlay) {
        setTimeout(() => {
            if (popupBox) {
                popupBox.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                popupBox.style.opacity = '0';
                popupBox.style.transform = 'translateY(-10px)';
            }

            popupOverlay.style.transition = 'opacity 0.4s ease';
            popupOverlay.style.opacity = '0';

            setTimeout(() => {
                popupOverlay.remove();
            }, 450);
        }, 2500);

        popupOverlay.addEventListener('click', function () {
            popupOverlay.remove();
        });
    }

    if (overdueAlertBox) {
        requestAnimationFrame(() => {
            overdueAlertBox.classList.add('show');
        });

        const removeOverdueAlert = () => {
            if (!overdueAlertWrap) return;
            overdueAlertBox.classList.remove('show');
            setTimeout(() => {
                if (overdueAlertWrap.parentNode) {
                    overdueAlertWrap.parentNode.removeChild(overdueAlertWrap);
                }
            }, 300);
        };

        if (overdueAlertClose) {
            overdueAlertClose.addEventListener('click', removeOverdueAlert);
        }

        setTimeout(removeOverdueAlert, 5000);
    }

    async function loadReceivables(pushUrl = true) {
        if (!filterForm || !dynamicArea) return;

        const formData = new FormData(filterForm);
        const params = new URLSearchParams();

        const searchValue = (formData.get('search') || '').toString().trim();
        const statusValue = (formData.get('status') || '').toString().trim();

        if (searchValue !== '') {
            params.set('search', searchValue);
        }

        if (statusValue !== '') {
            params.set('status', statusValue);
        }

        params.set('ajax', '1');

        dynamicArea.style.opacity = '0.55';
        dynamicArea.style.pointerEvents = 'none';

        try {
            if (activeRequest) {
                activeRequest.abort();
            }

            activeRequest = new AbortController();

            const response = await fetch(arPageUrl + '?' + params.toString(), {
                method: 'GET',
                signal: activeRequest.signal,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error('Failed to load receivables.');
            }

            const html = await response.text();
            dynamicArea.innerHTML = html;

            if (pushUrl) {
                const cleanParams = new URLSearchParams();
                if (searchValue !== '') cleanParams.set('search', searchValue);
                if (statusValue !== '') cleanParams.set('status', statusValue);

                const newUrl = cleanParams.toString()
                    ? arPageUrl + '?' + cleanParams.toString()
                    : arPageUrl;

                window.history.replaceState({}, '', newUrl);
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                console.error(error);
            }
        } finally {
            dynamicArea.style.opacity = '1';
            dynamicArea.style.pointerEvents = 'auto';
        }
    }

    if (filterForm) {
        filterForm.addEventListener('submit', function (event) {
            event.preventDefault();
            loadReceivables(true);
        });
    }

    if (statusSelect) {
        statusSelect.addEventListener('change', function () {
            loadReceivables(true);
        });
    }

    if (searchInput) {
        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                loadReceivables(true);
            }
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function (event) {
            event.preventDefault();

            if (searchInput) searchInput.value = '';
            if (statusSelect) statusSelect.value = '';

            loadReceivables(true);
        });
    }

    /* ---------------- View modal ---------------- */
    const arModalOverlay = document.getElementById('arViewModalOverlay');
    const arModalClose = document.getElementById('arViewModalClose');
    const arModalPaymentBody = document.getElementById('arModalPaymentBody');
    const arModalPaymentAction = document.getElementById('arModalPaymentAction');
    const arModalOtherList = document.getElementById('arModalOtherList');
    const arModalOtherCustomerTag = document.getElementById('arModalOtherCustomerTag');

    function escapeHtml(str) {
        return String(str == null ? '' : str).replace(/[&<>"']/g, function (ch) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[ch];
        });
    }

    function statusClass(statusText) {
        return 'status-' + String(statusText || '').toLowerCase().replace(/\s+/g, '-');
    }

    // payload: { id, salesNo, customer, invoiceDate, dueDate, status, total, paid, balance }
    function loadArModal(payload) {
        document.getElementById('arModalSalesNo').textContent = payload.salesNo || '-';
        document.getElementById('arModalCustomer').textContent = payload.customer || '-';
        document.getElementById('arModalInvoiceDate').textContent = payload.invoiceDate || '-';
        document.getElementById('arModalDueDate').textContent = payload.dueDate || '-';

        const statusText = payload.status || '-';
        const statusEl = document.getElementById('arModalStatus');
        statusEl.textContent = statusText;
        statusEl.className = 'ar-modal-status-badge ' + statusClass(statusText);

        document.getElementById('arModalTotal').textContent = payload.total || '-';
        document.getElementById('arModalPaid').textContent = payload.paid || '-';
        document.getElementById('arModalBalance').textContent = payload.balance || '-';

        if (arModalPaymentAction) {
            const isPaid = statusText.toLowerCase() === 'paid';

            if (isPaid) {
                arModalPaymentAction.removeAttribute('href');
                arModalPaymentAction.setAttribute('aria-disabled', 'true');
                arModalPaymentAction.classList.add('is-disabled');
                arModalPaymentAction.innerHTML = '<i class="bi bi-check2-circle" aria-hidden="true"></i><span>Payment Complete</span>';
            } else {
                arModalPaymentAction.href = arPaymentUrl + '?id=' + encodeURIComponent(payload.id);
                arModalPaymentAction.removeAttribute('aria-disabled');
                arModalPaymentAction.classList.remove('is-disabled');
                arModalPaymentAction.innerHTML = '<i class="bi bi-cash-coin" aria-hidden="true"></i><span>Update Payment</span>';
            }
        }

        arModalOtherCustomerTag.textContent = payload.customer ? '· ' + payload.customer : '';

        arModalPaymentBody.innerHTML = '<tr><td colspan="4" class="ar-modal-loading">Loading payment history...</td></tr>';
        arModalOtherList.innerHTML = '<p class="ar-modal-loading">Loading...</p>';

        fetch(arViewUrl + '?id=' + encodeURIComponent(payload.id), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.error) {
                    arModalPaymentBody.innerHTML = '<tr><td colspan="4" class="ar-modal-loading">' + escapeHtml(data.error) + '</td></tr>';
                    arModalOtherList.innerHTML = '<p class="ar-modal-loading">' + escapeHtml(data.error) + '</p>';
                    return;
                }

                if (!data.payments || data.payments.length === 0) {
                    arModalPaymentBody.innerHTML = '<tr><td colspan="4" class="ar-modal-loading">No payments recorded yet.</td></tr>';
                } else {
                    arModalPaymentBody.innerHTML = data.payments.map(function (p) {
                        return '<tr>' +
                            '<td data-label="Payment Date"><span>' + escapeHtml(p.date) + '</span></td>' +
                            '<td data-label="Amount"><strong>₱' + escapeHtml(p.amount) + '</strong></td>' +
                            '<td data-label="Method"><span>' + escapeHtml(p.method) + '</span></td>' +
                            '<td data-label="Reference No."><span>' + escapeHtml(p.reference) + '</span></td>' +
                            '</tr>';
                    }).join('');
                }

                if (!data.other_transactions || data.other_transactions.length === 0) {
                    arModalOtherList.innerHTML = '<p class="ar-modal-loading">No other transactions for this customer yet.</p>';
                    return;
                }

                arModalOtherList.innerHTML = data.other_transactions.map(function (t) {
                    const accent = statusClass(t.status).replace('status-', 'accent-');
                    return '<button type="button" class="ar-modal-other-row ' + accent + '" ' +
                        'data-id="' + escapeHtml(t.id) + '" ' +
                        'data-sales-no="' + escapeHtml(t.sales_no) + '" ' +
                        'data-invoice-date="' + escapeHtml(t.date) + '" ' +
                        'data-due-date="' + escapeHtml(t.due_date) + '" ' +
                        'data-status="' + escapeHtml(t.status) + '" ' +
                        'data-total="₱' + escapeHtml(t.total) + '" ' +
                        'data-paid="₱' + escapeHtml(t.paid) + '" ' +
                        'data-balance="₱' + escapeHtml(t.balance) + '">' +
                        '<span class="ar-modal-other-icon"><i class="bi bi-receipt"></i></span>' +
                        '<span class="ar-modal-other-main">' +
                            '<span class="ar-modal-other-sales">' + escapeHtml(t.sales_no) + '</span>' +
                            '<span class="ar-modal-other-date">' + escapeHtml(t.date) + '</span>' +
                        '</span>' +
                        '<span class="ar-modal-other-side">' +
                            '<span class="ar-modal-other-amount">₱' + escapeHtml(t.total) + '</span>' +
                            '<span class="ar-modal-status-badge ar-modal-status-badge-sm ' + statusClass(t.status) + '">' + escapeHtml(t.status) + '</span>' +
                        '</span>' +
                        '<span class="ar-modal-other-chevron"><i class="bi bi-chevron-right"></i></span>' +
                    '</button>';
                }).join('');
            })
            .catch(function () {
                arModalPaymentBody.innerHTML = '<tr><td colspan="4" class="ar-modal-loading">Failed to load payment history.</td></tr>';
                arModalOtherList.innerHTML = '<p class="ar-modal-loading">Failed to load other transactions.</p>';
            });
    }

    function openArModal(btn) {
        arModalOverlay.classList.add('show');
        document.body.style.overflow = 'hidden';

        loadArModal({
            id: btn.dataset.id,
            salesNo: btn.dataset.salesNo,
            customer: btn.dataset.customer,
            invoiceDate: btn.dataset.invoiceDate,
            dueDate: btn.dataset.dueDate,
            status: btn.dataset.status,
            total: btn.dataset.total,
            paid: btn.dataset.paid,
            balance: btn.dataset.balance
        });
    }

    function closeArModal() {
        arModalOverlay.classList.remove('show');
        document.body.style.overflow = '';
    }

    // Delegated listener so this keeps working after the table is
    // replaced by the AJAX filter/search refresh above.
    document.addEventListener('click', function (event) {
        const viewBtn = event.target.closest('.icon-btn.view');
        if (viewBtn) {
            openArModal(viewBtn);
            return;
        }

        // Clicking a customer's other-transaction row swaps the modal
        // to that record, same customer, no need to close/reopen.
        const otherRow = event.target.closest('.ar-modal-other-row');
        if (otherRow) {
            const currentCustomer = document.getElementById('arModalCustomer').textContent;
            loadArModal({
                id: otherRow.dataset.id,
                salesNo: otherRow.dataset.salesNo,
                customer: currentCustomer,
                invoiceDate: otherRow.dataset.invoiceDate,
                dueDate: otherRow.dataset.dueDate,
                status: otherRow.dataset.status,
                total: otherRow.dataset.total,
                paid: otherRow.dataset.paid,
                balance: otherRow.dataset.balance
            });
        }
    });

    if (arModalClose) {
        arModalClose.addEventListener('click', closeArModal);
    }

    if (arModalOverlay) {
        arModalOverlay.addEventListener('click', function (event) {
            if (event.target === arModalOverlay) {
                closeArModal();
            }
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && arModalOverlay && arModalOverlay.classList.contains('show')) {
            closeArModal();
        }
    });
});
</script>
    <footer class="nx-footer">
        <div class="nx-footer-line"></div>
        <p class="nx-footer-copy">Copyright &copy; 2026 NexGen.</p>
    </footer>
<?php include 'chatbot.php'; ?>
</body>
</html>

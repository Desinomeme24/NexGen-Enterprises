<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

enforceSessionTimeout();

if (empty($_SESSION['user_id']) || !nxIsAdminRole((string)($_SESSION['role'] ?? ''))) {
    header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
    exit();
}

try {
    $financialResult = $conn->query(
        "SELECT be.id AS business_entity_id,
            be.business_name,
            COALESCE(owners.owner_names, 'No owner assigned') AS owner_names,
            COALESCE(revenue.gross_revenue, 0) AS gross_revenue,
            COALESCE(costs.cost_of_goods_sold, 0) AS cost_of_goods_sold,
            COALESCE(revenue.gross_revenue, 0) - COALESCE(costs.cost_of_goods_sold, 0) AS net_profit,
            be.permit_payment_status,
            be.permit_unpaid_since
     FROM business_entities be
     LEFT JOIN (
         SELECT business_owners.business_entity_id,
                GROUP_CONCAT(DISTINCT u.full_name ORDER BY u.full_name SEPARATOR ', ') AS owner_names
         FROM (
             SELECT uba.user_id, uba.business_entity_id
             FROM user_business_assignments uba
             WHERE uba.status = 'active'
             UNION
             SELECT u.id, b.business_entity_id
             FROM users u
             INNER JOIN businesses b ON b.id = u.business_id
             WHERE u.role = 'owner'
               AND NOT EXISTS (
                   SELECT 1
                   FROM user_business_assignments existing_uba
                   WHERE existing_uba.user_id = u.id
               )
         ) business_owners
         INNER JOIN users u ON u.id = business_owners.user_id AND u.role = 'owner'
         GROUP BY business_owners.business_entity_id
     ) owners ON owners.business_entity_id = be.id
     LEFT JOIN (
         SELECT b.business_entity_id, SUM(s.total_amount) AS gross_revenue
         FROM businesses b
         INNER JOIN sales s ON s.business_id = b.id
         GROUP BY b.business_entity_id
     ) revenue ON revenue.business_entity_id = be.id
     LEFT JOIN (
         SELECT b.business_entity_id, SUM(p.cost_price * si.quantity) AS cost_of_goods_sold
         FROM businesses b
         INNER JOIN sales s ON s.business_id = b.id
         INNER JOIN sale_items si ON si.sale_id = s.id
         INNER JOIN products p ON p.id = si.product_id AND p.business_id = s.business_id
         GROUP BY b.business_entity_id
     ) costs ON costs.business_entity_id = be.id
         ORDER BY gross_revenue DESC, be.business_name ASC, be.id ASC"
    );
} catch (Throwable $error) {
    error_log('NexGen San Andres analytics query failed: ' . $error->getMessage());
    http_response_code(503);
    exit('Business analytics are temporarily unavailable.');
}

if (!$financialResult instanceof mysqli_result) {
    error_log('NexGen San Andres analytics query failed: ' . $conn->error);
    http_response_code(503);
    exit('Business analytics are temporarily unavailable.');
}

$businesses = [];
$totalProfit = 0.0;
while ($row = $financialResult->fetch_assoc()) {
    $row['gross_revenue'] = (float)$row['gross_revenue'];
    $row['cost_of_goods_sold'] = (float)$row['cost_of_goods_sold'];
    $row['net_profit'] = (float)$row['net_profit'];
    $totalProfit += $row['net_profit'];
    $businesses[] = $row;
}
$businessCount = count($businesses);
$paidPermitCount = 0;
$unpaidPermitCount = 0;
foreach ($businesses as $business) {
    if (strtolower((string)$business['permit_payment_status']) === 'paid') {
        $paidPermitCount++;
    } elseif (strtolower((string)$business['permit_payment_status']) === 'unpaid') {
        $unpaidPermitCount++;
    }
}

$locationsByEntity = [];
$locationResult = $conn->query(
    "SELECT business_entity_id, branch_name, business_address, is_main_branch
     FROM businesses
     WHERE branch_status = 'active'
     ORDER BY business_entity_id, is_main_branch DESC, branch_name ASC, id ASC"
);
if (!$locationResult instanceof mysqli_result) {
    error_log('NexGen San Andres analytics location query failed: ' . $conn->error);
    http_response_code(503);
    exit('Business locations are temporarily unavailable.');
}
while ($location = $locationResult->fetch_assoc()) {
    $entityId = (int)$location['business_entity_id'];
    $locationsByEntity[$entityId][] = [
        'branch_name' => (string)$location['branch_name'],
        'business_address' => (string)$location['business_address'],
        'is_main_branch' => (bool)$location['is_main_branch'],
    ];
}

$permitTablesResult = $conn->query(
    "SELECT COUNT(DISTINCT TABLE_NAME) AS total
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN ('business_permit_assessments', 'business_permit_quarterly_payments')"
);
if (!($permitTablesResult instanceof mysqli_result)) {
    error_log('NexGen San Andres analytics permit schema check failed: ' . $conn->error);
    http_response_code(503);
    exit('Permit payment details are temporarily unavailable.');
}
$permitTrackingReady = (int)($permitTablesResult->fetch_assoc()['total'] ?? 0) === 2;
$permitByEntity = [];
if ($permitTrackingReady) {
    $permitYear = (int)date('Y');
    $permitStmt = $conn->prepare(
        "SELECT a.id AS assessment_id, a.business_entity_id, a.permit_year, a.annual_fee,
                p.quarter_number, p.assessed_amount, p.payment_status, p.paid_at
         FROM business_permit_assessments a
         INNER JOIN business_permit_quarterly_payments p ON p.assessment_id = a.id
         WHERE a.permit_year = ?
         ORDER BY a.business_entity_id, p.quarter_number"
    );
    if (!$permitStmt) {
        error_log('NexGen San Andres analytics permit query preparation failed: ' . $conn->error);
        http_response_code(503);
        exit('Permit payment details are temporarily unavailable.');
    }
    $permitStmt->bind_param('i', $permitYear);
    if (!$permitStmt->execute()) {
        error_log('NexGen San Andres analytics permit query failed: ' . $permitStmt->error);
        $permitStmt->close();
        http_response_code(503);
        exit('Permit payment details are temporarily unavailable.');
    }
    $permitResult = $permitStmt->get_result();
    while ($permit = $permitResult->fetch_assoc()) {
        $entityId = (int)$permit['business_entity_id'];
        if (!isset($permitByEntity[$entityId])) {
            $permitByEntity[$entityId] = [
                'assessment_id' => (int)$permit['assessment_id'],
                'permit_year' => (int)$permit['permit_year'],
                'annual_fee' => (float)$permit['annual_fee'],
                'quarters' => [],
            ];
        }
        $quarterNumber = (int)$permit['quarter_number'];
        $permitByEntity[$entityId]['quarters'][$quarterNumber] = [
            'assessed_amount' => (float)$permit['assessed_amount'],
            'payment_status' => (string)$permit['payment_status'],
            'paid_at' => $permit['paid_at'] !== null ? (string)$permit['paid_at'] : null,
        ];
    }
    $permitStmt->close();
}

$permitCsrfByEntity = [];
foreach ($businesses as $business) {
    $entityId = (int)$business['business_entity_id'];
    $permitCsrfByEntity[$entityId] = [
        'assessment' => generateCsrfToken('permit_assessment_' . $entityId . '_' . date('Y')),
        'quarters' => [],
    ];
    for ($quarter = 1; $quarter <= 4; $quarter++) {
        $permitCsrfByEntity[$entityId]['quarters'][$quarter] = generateCsrfToken(
            'permit_quarter_' . $entityId . '_' . date('Y') . '_' . $quarter
        );
    }
}

$permitFlash = '';
$permitFlashType = '';
if (isset($_SESSION['success'])) {
    $permitFlash = (string)$_SESSION['success'];
    $permitFlashType = 'notice-success';
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $permitFlash = (string)$_SESSION['error'];
    $permitFlashType = 'notice-error';
    unset($_SESSION['error']);
}

function nxAnalyticsMoney(float $amount): string
{
    return '₱' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cainta Analytics | NexGen</title>
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('nexgen-theme');
                if (saved === 'light' || saved === 'dark') {
                    document.documentElement.setAttribute('data-theme', saved);
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="<?php echo e(nxCodeUrl('STYLE/admin_module.css')); ?>">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .analytics-heading {
            display: flex;
            align-items: center;
            margin-bottom: 26px;
        }
        .analytics-heading h1 { margin: 0 0 6px; }
        .analytics-heading p { margin: 0; color: var(--muted); }
        .analytics-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 26px;
        }
        .analytics-metric {
            position: relative;
            min-width: 0;
            min-height: 132px;
            padding: 22px;
            border: 1px solid var(--glass-border, var(--line));
            border-radius: 18px;
            background: linear-gradient(145deg, var(--panel, var(--card)), var(--card));
            box-shadow: 0 12px 28px rgba(0, 0, 0, .18), inset 0 1px 0 rgba(255, 255, 255, .045);
        }
        .analytics-metric::before {
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            border-radius: 18px 0 0 18px;
            background: var(--gold, #eab75e);
            content: '';
        }
        .analytics-filter-button {
            width: 100%;
            color: inherit;
            font: inherit;
            text-align: left;
            cursor: pointer;
            transition: border-color .18s ease, transform .18s ease, box-shadow .18s ease, background .18s ease;
        }
        .analytics-filter-button:hover,
        .analytics-filter-button:focus-visible,
        .analytics-filter-button[aria-pressed="true"] {
            border-color: color-mix(in srgb, var(--gold, #eab75e) 70%, var(--line));
            outline: none;
            box-shadow: 0 16px 34px rgba(0, 0, 0, .23), 0 0 0 2px color-mix(in srgb, var(--gold, #eab75e) 25%, transparent);
        }
        .analytics-filter-button:hover { transform: translateY(-2px); }
        .analytics-filter-button:focus-visible { outline: 2px solid var(--gold, #eab75e); outline-offset: 2px; }
        .analytics-filter-hint { display: block; margin-top: 9px; color: var(--muted); font-size: .78rem; }
        .analytics-metric span { display: block; color: var(--muted); font-size: .86rem; font-weight: 650; }
        .analytics-metric strong {
            display: block;
            margin-top: 14px;
            overflow-wrap: anywhere;
            color: var(--text);
            font-size: clamp(1.5rem, 2.4vw, 2rem);
            line-height: 1.15;
            letter-spacing: -.025em;
        }
        .analytics-metric:not(.analytics-filter-button)::before { background: #71aaff; }
        .analytics-filter-button[data-permit-filter="paid"]::before { background: #34d399; }
        .analytics-filter-button[data-permit-filter="unpaid"]::before { background: #ff9aa3; }
        .analytics-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 22px 24px;
        }
        .analytics-panel-header h2 { margin: 0; font-size: 1.2rem; }
        .analytics-panel-body { padding: 0 20px 20px; }
        .analytics-search {
            position: relative;
            width: min(100%, 340px);
            flex: 0 1 340px;
        }
        .analytics-search i {
            position: absolute;
            top: 50%;
            left: 14px;
            color: var(--muted);
            transform: translateY(-50%);
            pointer-events: none;
        }
        .analytics-search input {
            width: 100%;
            min-height: 44px;
            padding: 10px 14px 10px 40px;
            border: 1px solid var(--line);
            border-radius: 12px;
            color: var(--text);
            background: var(--card);
            font: inherit;
            font-size: .9rem;
        }
        .analytics-search input:focus {
            border-color: var(--gold, #eab75e);
            outline: 3px solid color-mix(in srgb, var(--gold, #eab75e) 20%, transparent);
        }
        .analytics-table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--panel, var(--card));
            box-shadow: 0 10px 24px rgba(0, 0, 0, .12);
        }
        .analytics-table {
            width: 100%;
            min-width: 760px;
            border-collapse: separate;
            border-spacing: 0;
        }
        .analytics-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 14px 18px;
            border-bottom: 1px solid var(--line);
            color: var(--muted);
            background: var(--card);
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .075em;
            text-align: left;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .analytics-table tbody td {
            padding: 16px 18px;
            border-bottom: 1px solid color-mix(in srgb, var(--line) 78%, transparent);
            color: var(--text);
            font-size: .9rem;
            vertical-align: middle;
        }
        .analytics-table tbody tr:last-child td { border-bottom: 0; }
        .analytics-business-row { transition: background .16s ease; }
        .analytics-business-row:hover { background: color-mix(in srgb, var(--gold, #eab75e) 7%, var(--panel, var(--card))); }
        .analytics-business { min-width: 230px; }
        .analytics-business-name { display: block; font-weight: 750; color: var(--text); }
        .analytics-owner { display: block; margin-top: 4px; color: var(--muted); font-size: .82rem; }
        .analytics-business-address { min-width: 240px; max-width: 380px; color: var(--muted) !important; line-height: 1.5; }
        .analytics-profit-cell { font-weight: 700; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .analytics-permit-status {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border: 1px solid var(--line);
            border-radius: 999px;
            font-size: .78rem;
            font-weight: 750;
        }
        .analytics-permit-status.is-paid { color: #34d399; background: rgba(52, 211, 153, .1); }
        .analytics-permit-status.is-unpaid { color: #ff9aa3; background: rgba(239, 68, 68, .1); }
        .analytics-permit-status.is-unknown { color: var(--muted); background: var(--line); }
        .analytics-empty { padding: 32px 24px; color: var(--muted); text-align: center; }
        .analytics-business-row { cursor: pointer; }
        .analytics-business-row:hover,
        .analytics-business-row:focus-visible { outline: 2px solid var(--gold, #eab75e); outline-offset: -2px; }
        .analytics-table-wrap table.analytics-table { min-width: 760px; table-layout: auto; }
        .table-wrap.analytics-table-wrap td:nth-child(1),
        .table-wrap.analytics-table-wrap th:nth-child(1) { min-width: 220px; text-align: left; }
        .table-wrap.analytics-table-wrap td:nth-child(2),
        .table-wrap.analytics-table-wrap th:nth-child(2) { min-width: 260px; text-align: left; }
        .table-wrap.analytics-table-wrap td:nth-child(3),
        .table-wrap.analytics-table-wrap th:nth-child(3) { min-width: 150px; text-align: right; }
        .table-wrap.analytics-table-wrap td:nth-child(4),
        .table-wrap.analytics-table-wrap th:nth-child(4) { min-width: 130px; text-align: center; }
        .analytics-table tbody tr.analytics-business-row:hover td {
            background: color-mix(in srgb, var(--gold, #eab75e) 7%, var(--panel, var(--card)));
        }
        .analytics-notice {
            margin-bottom: 16px;
            padding: 12px 16px;
            border-radius: 10px;
            opacity: 1;
            transition: opacity .2s ease;
        }
        .analytics-auto-dismiss.is-dismissing { opacity: 0; }
        .notice-success { color: var(--text); background: rgba(42, 160, 96, .14); }
        .notice-error { color: var(--text); background: rgba(210, 65, 65, .14); }
        .analytics-sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .analytics-modal-backdrop {
            position: fixed;
            z-index: 1200;
            inset: 0;
            display: grid;
            place-items: center;
            padding: 24px;
            background: rgba(9, 14, 24, .72);
        }
        .analytics-modal-backdrop[hidden] { display: none; }
        .analytics-modal {
            width: min(100%, 1050px);
            max-height: min(90vh, 900px);
            overflow: auto;
            padding: clamp(20px, 3vw, 34px);
            border: 1px solid var(--line);
            border-radius: 22px;
            color: var(--text);
            background: #0e1b3d;
            box-shadow: 0 28px 90px rgba(0, 0, 0, .48);
        }
        html[data-theme="light"] .analytics-modal { background: #fff; }
        .analytics-modal-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--line);
        }
        .analytics-modal-title-line { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; }
        .analytics-modal-header h2 { margin: 0; font-size: clamp(1.25rem, 2.4vw, 1.65rem); }
        .analytics-modal-header p { margin: 7px 0 0; color: var(--muted); }
        .analytics-business-status {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border: 1px solid var(--line);
            border-radius: 999px;
            font-size: .73rem;
            font-weight: 800;
        }
        .analytics-business-status.is-paid { color: #34d399; background: rgba(52, 211, 153, .1); }
        .analytics-business-status.is-unpaid { color: #ff9aa3; background: rgba(239, 68, 68, .1); }
        .analytics-business-status.is-unknown { color: var(--muted); background: var(--card); }
        .analytics-modal-close {
            flex: 0 0 auto;
            width: 40px;
            height: 40px;
            border: 1px solid var(--line);
            border-radius: 50%;
            color: var(--text);
            background: transparent;
            font-size: 1.4rem;
            cursor: pointer;
        }
        .analytics-modal-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin: 20px 0 0; }
        .analytics-financial-grid { grid-template-columns: minmax(0, 360px); }
        .analytics-financial-grid .analytics-detail-card { min-height: 100px; }
        .analytics-financial-grid .analytics-detail-card h3 { color: var(--muted); font-size: .82rem; }
        .analytics-financial-grid .analytics-detail-card p { color: var(--text); font-size: 1.3rem; font-weight: 750; font-variant-numeric: tabular-nums; }
        .analytics-detail-card,
        .analytics-quarter-card {
            min-width: 0;
            padding: 17px;
            border: 1px solid var(--line);
            border-radius: 15px;
            background: #14264b;
            box-shadow: 0 6px 16px rgba(0, 0, 0, .1);
        }
        html[data-theme="light"] .analytics-detail-card,
        html[data-theme="light"] .analytics-quarter-card { background: #f6f8fc; }
        .analytics-detail-card h3,
        .analytics-quarter-card h4 { margin: 0 0 8px; font-size: 1rem; }
        .analytics-detail-card p { margin: 0; overflow-wrap: anywhere; color: var(--muted); }
        .analytics-modal-section {
            margin-top: 24px;
            padding-top: 22px;
            border-top: 1px solid var(--line);
        }
        .analytics-modal-section-heading { display: flex; justify-content: space-between; gap: 16px; align-items: center; margin-bottom: 14px; }
        .analytics-modal-section-heading h3 { margin: 0 0 5px; font-size: 1.05rem; }
        .analytics-modal-section-heading p { margin: 0; color: var(--muted); }
        .analytics-permit-summary {
            flex: 0 0 auto;
            padding: 9px 13px;
            border: 1px solid var(--line);
            border-radius: 11px;
            background: var(--card);
            font-size: .88rem;
            font-weight: 750;
            white-space: nowrap;
        }
        .analytics-permit-access {
            display: flex;
            align-items: center;
            gap: 9px;
            margin: 0 0 14px;
            padding: 10px 13px;
            border: 1px solid var(--line);
            border-radius: 11px;
            color: var(--muted);
            background: var(--card);
            font-size: .84rem;
        }
        .analytics-permit-access.is-active { color: #34d399; border-color: rgba(52, 211, 153, .28); }
        .analytics-permit-access.is-inactive { color: #ff9aa3; border-color: rgba(239, 68, 68, .28); }
        .analytics-quarter-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .analytics-quarter-grid.is-empty { grid-template-columns: 1fr; }
        .analytics-quarter-card { display: flex; flex-direction: column; min-height: 182px; }
        .analytics-quarter-card h4 { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .analytics-quarter-card .analytics-quarter-status { margin-top: auto; padding-top: 8px; }
        .analytics-quarter-status span { font-size: .76rem; font-weight: 800; }
        .analytics-quarter-status.is-paid { color: #34d399 !important; }
        .analytics-quarter-status.is-unpaid { color: #ff9aa3 !important; }
        .analytics-permit-empty { max-width: none; }
        .analytics-assessment-panel {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 22px;
            margin-top: 16px;
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 15px;
            background: var(--card);
        }
        .analytics-assessment-copy { max-width: 520px; }
        .analytics-assessment-copy h4 { margin: 0 0 5px; font-size: .95rem; }
        .analytics-assessment-copy p { margin: 0; color: var(--muted); font-size: .82rem; line-height: 1.5; }
        .analytics-quarter-card p { margin: 6px 0; color: var(--muted); }
        .analytics-quarter-card form { margin-top: 12px; }
        .analytics-quarter-card button,
        .analytics-assessment-form button {
            border: 0;
            border-radius: 8px;
            padding: 9px 12px;
            color: var(--text);
            background: var(--line);
            cursor: pointer;
        }
        .analytics-assessment-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; }
        .analytics-assessment-form label { display: grid; gap: 6px; color: var(--muted); }
        .analytics-assessment-form input {
            min-width: 190px;
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--text);
            background: var(--card);
        }
        .analytics-permit-help { color: var(--muted); }
        .analytics-permit-help.analytics-assessment-note { margin: 10px 0 0; }
        @media (max-width: 900px) {
            .analytics-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .analytics-panel-header { align-items: flex-start; flex-direction: column; }
            .analytics-search { width: 100%; flex-basis: auto; }
            .analytics-quarter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 560px) {
            .analytics-summary { grid-template-columns: 1fr; }
            .analytics-metric { min-height: 118px; padding: 19px; }
            .analytics-panel-header { padding: 18px 16px; }
            .analytics-panel-body { padding: 0 12px 14px; }
            .analytics-modal-backdrop { padding: 8px; }
            .analytics-modal-grid, .analytics-quarter-grid { grid-template-columns: 1fr; }
            .analytics-modal-section-heading { align-items: flex-start; flex-direction: column; }
            .analytics-assessment-panel { align-items: stretch; flex-direction: column; }
            .analytics-assessment-form { width: 100%; }
            .analytics-assessment-form label, .analytics-assessment-form input { width: 100%; }
            .analytics-quarter-card { min-height: 0; }
        }
    </style>
</head>
<body>
<div class="admin-shell">
    <?php include __DIR__ . '/admin_sidebar.php'; ?>
    <main class="admin-content">
        <?php if ($permitFlash !== ''): ?>
            <div class="analytics-notice analytics-auto-dismiss <?php echo e($permitFlashType); ?>" id="permitFlashNotice" role="status"><?php echo e($permitFlash); ?></div>
        <?php endif; ?>
        <div class="topbar">
            <div class="analytics-heading">
                <div>
                    <div class="page-title"><h1>Cainta Analytics</h1></div>
                </div>
            </div>
        </div>

        <section class="analytics-summary" aria-label="Business and permit summary">
            <button type="button" class="analytics-metric analytics-filter-button" data-permit-filter="all" aria-pressed="true" aria-controls="analyticsBusinessRows">
                <span>Registered businesses</span>
                <strong><?php echo number_format($businessCount); ?></strong>
               
            </button>
            <article class="analytics-metric">
                <span>Total net profit</span>
                <strong><?php echo e(nxAnalyticsMoney($totalProfit)); ?></strong>
            </article>
            <button type="button" class="analytics-metric analytics-filter-button" data-permit-filter="paid" aria-pressed="false" aria-controls="analyticsBusinessRows">
                <span>Paid business permits</span>
                <strong><?php echo number_format($paidPermitCount); ?></strong>
              
            </button>
            <button type="button" class="analytics-metric analytics-filter-button" data-permit-filter="unpaid" aria-pressed="false" aria-controls="analyticsBusinessRows">
                <span>Unpaid business permits</span>
                <strong><?php echo number_format($unpaidPermitCount); ?></strong>
                
            </button>
        </section>

        <section class="panel" aria-labelledby="analyticsBusinessesHeading">
            <div class="panel-header analytics-panel-header">
                <h2 id="analyticsBusinessesHeading">Business Performance</h2>
                <label class="analytics-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="analyticsBusinessSearch" placeholder="Search business, owner, or address" autocomplete="off" aria-label="Search businesses by business name, owner, or address">
                </label>
            </div>
            <p class="analytics-sr-only" id="analyticsFilterStatus" aria-live="polite">Showing all businesses.</p>
            <div class="panel-body analytics-panel-body">
                <?php if ($businesses === []): ?>
                    <div class="analytics-empty">No registered businesses are available to report.</div>
                <?php else: ?>
                    <div class="table-wrap analytics-table-wrap">
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Business</th>
                                    <th>Business Address</th>
                                    <th>Net Profit</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody id="analyticsBusinessRows">
                            <?php foreach ($businesses as $business): ?>
                                <?php
                                $entityId = (int)$business['business_entity_id'];
                                $revenue = (float)$business['gross_revenue'];
                                $searchParts = [
                                    (string)$business['business_name'],
                                    (string)$business['owner_names'],
                                ];
                                foreach ($locationsByEntity[$entityId] ?? [] as $businessLocation) {
                                    $searchParts[] = (string)$businessLocation['branch_name'];
                                    $searchParts[] = (string)$businessLocation['business_address'];
                                }
                                $businessPayload = [
                                    'business_entity_id' => $entityId,
                                    'business_name' => (string)$business['business_name'],
                                    'owner_names' => (string)$business['owner_names'],
                                    'locations' => $locationsByEntity[$entityId] ?? [],
                                    'net_profit' => (float)$business['net_profit'],
                                    'permit_status' => (string)$business['permit_payment_status'],
                                    'permit_unpaid_since' => $business['permit_unpaid_since'],
                                    'permit_tracking_ready' => $permitTrackingReady,
                                    'permit' => $permitByEntity[$entityId] ?? null,
                                    'csrf' => $permitCsrfByEntity[$entityId],
                                    'permit_year' => (int)date('Y'),
                                ];
                                ?>
                                <tr class="analytics-business-row" data-business-row tabindex="0" role="button" aria-haspopup="dialog"
                                    aria-label="<?php echo e('View details for ' . $business['business_name']); ?>"
                                    data-permit-status="<?php echo e(strtolower((string)$business['permit_payment_status'])); ?>"
                                    data-search="<?php echo e(implode(' ', $searchParts)); ?>"
                                    data-business="<?php echo e((string)json_encode($businessPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)); ?>">
                                    <td class="analytics-business">
                                        <span class="analytics-business-name"><?php echo e($business['business_name']); ?></span>
                                        <span class="analytics-owner"><?php echo e($business['owner_names']); ?></span>
                                    </td>
                                    <?php
                                    $permitStatus = strtolower(trim((string)$business['permit_payment_status']));
                                    $permitStatusLabel = $permitStatus === 'paid' ? 'Paid' : ($permitStatus === 'unpaid' ? 'Unpaid' : 'Not set');
                                    $permitStatusClass = $permitStatus === 'paid' ? 'is-paid' : ($permitStatus === 'unpaid' ? 'is-unpaid' : 'is-unknown');
                                    ?>
                                    <td class="analytics-business-address" data-label="Business Address">
                                        <?php if (!empty($locationsByEntity[$entityId])): ?>
                                            <?php foreach ($locationsByEntity[$entityId] as $businessLocation): ?>
                                                <div><?php echo e($businessLocation['business_address'] !== '' ? $businessLocation['business_address'] : $businessLocation['branch_name']); ?></div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <span>Address not available</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="analytics-profit-cell" data-label="Net Profit"><?php echo e(nxAnalyticsMoney((float)$business['net_profit'])); ?></td>
                                    <td data-label="Status">
                                        <span class="analytics-permit-status <?php echo e($permitStatusClass); ?>">
                                            <?php echo e($permitStatusLabel); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="analytics-empty" id="analyticsNoFilterMatches" hidden>No businesses match your search and permit filter.</p>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<div class="analytics-modal-backdrop" id="businessDetailsBackdrop" hidden>
    <section class="analytics-modal" id="businessDetailsModal" role="dialog" aria-modal="true" aria-labelledby="businessDetailsTitle" tabindex="-1">
        <div class="analytics-modal-header">
            <div>
                <div class="analytics-modal-title-line">
                    <h2 id="businessDetailsTitle"></h2>
                    <span class="analytics-business-status" id="businessDetailsPermitBadge"></span>
                </div>
                <p id="businessDetailsOwner"></p>
            </div>
            <button type="button" class="analytics-modal-close" id="businessDetailsClose" aria-label="Close business details">&times;</button>
        </div>
        <div class="analytics-modal-grid analytics-financial-grid" id="businessDetailsFinancials"></div>
        <section class="analytics-modal-section">
            <div class="analytics-modal-section-heading">
                <div><h3>Business locations</h3><p>Active branches registered to this business.</p></div>
            </div>
            <div class="analytics-modal-grid" id="businessDetailsLocations"></div>
        </section>
        <section class="analytics-modal-section" aria-labelledby="permitDetailsHeading">
            <div class="analytics-modal-section-heading">
                <div><h3 id="permitDetailsHeading">Business permit payments</h3><p id="permitScheduleDescription"></p></div>
                <strong class="analytics-permit-summary" id="permitAnnualTotal"></strong>
            </div>
            <p class="analytics-permit-access" id="permitAccessSummary"></p>
            <div class="analytics-quarter-grid" id="permitQuarterDetails"></div>
            <div id="permitAssessmentControls"></div>
        </section>
    </section>
</div>
<script src="<?php echo e(nxCodeUrl('JS/admin_module.js')); ?>"></script>
<script>
    (function () {
        var backdrop = document.getElementById('businessDetailsBackdrop');
        var modal = document.getElementById('businessDetailsModal');
        var closeButton = document.getElementById('businessDetailsClose');
        var financials = document.getElementById('businessDetailsFinancials');
        var locations = document.getElementById('businessDetailsLocations');
        var quarters = document.getElementById('permitQuarterDetails');
        var controls = document.getElementById('permitAssessmentControls');
        var lastFocused = null;
        var dismissTimers = new WeakMap();
        var phpEndpoint = <?php echo json_encode(nxAppUrl('update_business_permit.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function dismissAfterOneSecond(element) {
            if (!element) return;
            var existingTimers = dismissTimers.get(element);
            if (existingTimers) {
                existingTimers.forEach(function (timerId) { window.clearTimeout(timerId); });
            }
            element.hidden = false;
            element.classList.remove('is-dismissing');
            element.setAttribute('aria-hidden', 'false');
            var fadeTimer = window.setTimeout(function () {
                element.classList.add('is-dismissing');
            }, 800);
            var hideTimer = window.setTimeout(function () {
                element.hidden = true;
                element.setAttribute('aria-hidden', 'true');
                element.classList.remove('is-dismissing');
            }, 1000);
            dismissTimers.set(element, [fadeTimer, hideTimer]);
        }

        function addCard(container, heading, content) {
            var card = document.createElement('article');
            card.className = 'analytics-detail-card';
            var title = document.createElement('h3');
            title.textContent = heading;
            var text = document.createElement('p');
            text.textContent = content;
            card.append(title, text);
            container.appendChild(card);
        }

        function hiddenField(form, name, value) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        }

        function paymentForm(data, quarterNumber, nextStatus) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = phpEndpoint;
            hiddenField(form, 'action', 'set_quarter');
            hiddenField(form, 'business_entity_id', data.business_entity_id);
            hiddenField(form, 'permit_year', data.permit_year);
            hiddenField(form, 'quarter_number', quarterNumber);
            hiddenField(form, 'payment_status', nextStatus);
            hiddenField(form, 'csrf_token', data.csrf.quarters[quarterNumber]);
            var button = document.createElement('button');
            button.type = 'submit';
            button.textContent = nextStatus === 'paid' ? 'Mark paid' : 'Mark unpaid';
            form.appendChild(button);
            return form;
        }

        function openDetails(row) {
            var data;
            try {
                data = JSON.parse(row.getAttribute('data-business'));
            } catch (error) {
                return;
            }
            lastFocused = document.activeElement;
            document.getElementById('businessDetailsTitle').textContent = data.business_name;
            document.getElementById('businessDetailsOwner').textContent = 'Owner(s): ' + data.owner_names;
            var permitBadge = document.getElementById('businessDetailsPermitBadge');
            var permitStatus = (data.permit_status || '').toLowerCase();
            permitBadge.className = 'analytics-business-status ' + (
                permitStatus === 'paid' ? 'is-paid' : (permitStatus === 'unpaid' ? 'is-unpaid' : 'is-unknown')
            );
            permitBadge.textContent = permitStatus === 'paid' ? 'Permit paid' : (permitStatus === 'unpaid' ? 'Permit unpaid' : 'Status not set');
            financials.replaceChildren();
            addCard(financials, 'Net profit', money(data.net_profit));
            locations.replaceChildren();
            if (!data.locations.length) {
                addCard(locations, 'No active locations', 'No active business branches are currently listed.');
            } else {
                data.locations.forEach(function (location) {
                    var branchName = location.branch_name || 'Business location';
                    addCard(locations, branchName + (location.is_main_branch ? ' · Main' : ''), location.business_address || 'No address recorded');
                });
            }

            var year = data.permit_year;
            document.getElementById('permitScheduleDescription').textContent =
                'Calendar quarters for ' + year + ' (Jan 1, Apr 1, Jul 1, and Oct 1).';
            var accessSummary = document.getElementById('permitAccessSummary');
            if (data.permit_status === 'paid') {
                accessSummary.className = 'analytics-permit-access is-active';
                accessSummary.textContent = 'Business access: Active · permit paid.';
            } else {
                var unpaidDate = data.permit_unpaid_since ? new Date(data.permit_unpaid_since.replace(' ', 'T')) : null;
                var graceDeadline = unpaidDate && !Number.isNaN(unpaidDate.getTime())
                    ? new Date(unpaidDate.getTime() + 30 * 24 * 60 * 60 * 1000)
                    : null;
                var withinGrace = graceDeadline && graceDeadline.getTime() > Date.now();
                accessSummary.className = 'analytics-permit-access ' + (withinGrace ? 'is-active' : 'is-inactive');
                accessSummary.textContent = withinGrace
                    ? 'Business access: Active during grace period through ' + graceDeadline.toLocaleDateString('en-PH') + '.'
                    : 'Business access: Inactive · permit grace period has expired.';
            }
            accessSummary.classList.add('analytics-auto-dismiss');
            dismissAfterOneSecond(accessSummary);
            quarters.replaceChildren();
            controls.replaceChildren();
            if (!data.permit_tracking_ready) {
                document.getElementById('permitAnnualTotal').textContent = 'Tracking not configured';
                quarters.classList.add('is-empty');
                var migrationCard = document.createElement('article');
                migrationCard.className = 'analytics-quarter-card analytics-permit-empty';
                var migrationHeading = document.createElement('h4');
                migrationHeading.textContent = 'Permit payment tracking is not configured';
                var migrationText = document.createElement('p');
                migrationText.textContent = 'Apply the quarterly permit-payment database migration to view and manage permit assessments.';
                migrationCard.append(migrationHeading, migrationText);
                quarters.appendChild(migrationCard);
            } else {
                quarters.classList.remove('is-empty');
                var assessment = data.permit;
                document.getElementById('permitAnnualTotal').textContent = assessment
                    ? 'Annual fee: ' + money(assessment.annual_fee)
                    : 'Annual fee: not set';
                if (!assessment) {
                    quarters.classList.add('is-empty');
                    var emptyAssessment = document.createElement('article');
                    emptyAssessment.className = 'analytics-quarter-card analytics-permit-empty';
                    var emptyHeading = document.createElement('h4');
                    emptyHeading.textContent = 'Annual assessment needed';
                    var emptyCopy = document.createElement('p');
                    emptyCopy.textContent = 'Set the annual permit fee below to create the four quarterly records. An overdue unpaid quarter may make business access inactive after the 30-day grace period.';
                    emptyAssessment.append(emptyHeading, emptyCopy);
                    quarters.appendChild(emptyAssessment);
                } else {
                    quarters.classList.remove('is-empty');
                    for (var quarterNumber = 1; quarterNumber <= 4; quarterNumber++) {
                        var quarter = assessment.quarters[quarterNumber];
                        var card = document.createElement('article');
                        card.className = 'analytics-quarter-card';
                        var heading = document.createElement('h4');
                        heading.textContent = 'Quarter ' + quarterNumber;
                        var dueDate = document.createElement('p');
                        dueDate.textContent = 'Quarter starts: ' + new Date(year, (quarterNumber - 1) * 3, 1)
                            .toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' });
                        var amount = document.createElement('p');
                        amount.textContent = 'Amount: ' + money(quarter ? quarter.assessed_amount : 0);
                        var status = document.createElement('p');
                        var isQuarterPaid = quarter && quarter.payment_status === 'paid';
                        status.className = 'analytics-quarter-status ' + (isQuarterPaid ? 'is-paid' : 'is-unpaid');
                        status.textContent = isQuarterPaid
                            ? 'Status: Paid' + (quarter.paid_at ? ' · ' + quarter.paid_at : '')
                            : 'Status: Unpaid';
                        card.append(heading, dueDate, amount, status);
                        card.appendChild(paymentForm(data, quarterNumber, quarter && quarter.payment_status === 'paid' ? 'unpaid' : 'paid'));
                        quarters.appendChild(card);
                    }
                }

                var assessmentForm = document.createElement('form');
                assessmentForm.method = 'POST';
                assessmentForm.action = phpEndpoint;
                assessmentForm.className = 'analytics-assessment-form';
                hiddenField(assessmentForm, 'action', 'save_assessment');
                hiddenField(assessmentForm, 'business_entity_id', data.business_entity_id);
                hiddenField(assessmentForm, 'permit_year', year);
                hiddenField(assessmentForm, 'csrf_token', data.csrf.assessment);
                var feeLabel = document.createElement('label');
                feeLabel.textContent = 'Annual permit fee (PHP)';
                var feeInput = document.createElement('input');
                feeInput.type = 'number';
                feeInput.name = 'annual_fee';
                feeInput.min = '0.01';
                feeInput.max = '9999999999.99';
                feeInput.step = '0.01';
                feeInput.required = true;
                if (assessment) {
                    feeInput.value = Number(assessment.annual_fee).toFixed(2);
                    feeInput.readOnly = Object.values(assessment.quarters).some(function (quarter) {
                        return quarter.payment_status === 'paid';
                    });
                }
                feeLabel.appendChild(feeInput);
                var save = document.createElement('button');
                save.type = 'submit';
                save.textContent = assessment ? 'Update annual assessment' : 'Set annual assessment';
                save.disabled = feeInput.readOnly;
                assessmentForm.append(feeLabel, save);
                var assessmentPanel = document.createElement('div');
                assessmentPanel.className = 'analytics-assessment-panel';
                var assessmentCopy = document.createElement('div');
                assessmentCopy.className = 'analytics-assessment-copy';
                var assessmentHeading = document.createElement('h4');
                assessmentHeading.textContent = 'Annual assessment';
                var assessmentDescription = document.createElement('p');
                assessmentDescription.textContent = 'Set or update the annual permit fee. It is divided across four calendar quarters.';
                assessmentCopy.append(assessmentHeading, assessmentDescription);
                assessmentPanel.append(assessmentCopy, assessmentForm);
                controls.appendChild(assessmentPanel);
                if (feeInput.readOnly) {
                    var lockedNote = document.createElement('p');
                    lockedNote.className = 'analytics-permit-help analytics-assessment-note';
                    lockedNote.textContent = 'The annual fee is locked after a quarterly payment is recorded.';
                    controls.appendChild(lockedNote);
                }
            }
            backdrop.hidden = false;
            document.body.style.overflow = 'hidden';
            modal.focus();
        }

        function money(value) {
            return '₱' + Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function closeDetails() {
            backdrop.hidden = true;
            document.body.style.overflow = '';
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        }

        document.querySelectorAll('[data-business-row]').forEach(function (row) {
            row.addEventListener('click', function () { openDetails(row); });
            row.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openDetails(row);
                }
            });
        });
        var filterButtons = document.querySelectorAll('[data-permit-filter]');
        var filterStatus = document.getElementById('analyticsFilterStatus');
        var noFilterMatches = document.getElementById('analyticsNoFilterMatches');
        var searchInput = document.getElementById('analyticsBusinessSearch');
        var activePermitFilter = 'all';

        function applyBusinessFilters() {
            var searchTerm = searchInput ? searchInput.value.trim().toLocaleLowerCase() : '';
            var visibleCount = 0;
            document.querySelectorAll('[data-business-row]').forEach(function (row) {
                var permitMatches = activePermitFilter === 'all'
                    || row.getAttribute('data-permit-status') === activePermitFilter;
                var searchMatches = searchTerm === ''
                    || (row.getAttribute('data-search') || '').toLocaleLowerCase().indexOf(searchTerm) !== -1;
                var show = permitMatches && searchMatches;
                row.hidden = !show;
                if (show) visibleCount++;
            });
            if (filterStatus) {
                var statusDescription = activePermitFilter === 'all'
                    ? 'all businesses'
                    : activePermitFilter + ' permit businesses';
                filterStatus.textContent = 'Showing ' + visibleCount + ' ' + statusDescription + '.';
            }
            if (noFilterMatches) noFilterMatches.hidden = visibleCount > 0;
        }

        filterButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                activePermitFilter = button.getAttribute('data-permit-filter') || 'all';
                if (activePermitFilter === 'all' && searchInput) searchInput.value = '';
                filterButtons.forEach(function (item) {
                    item.setAttribute('aria-pressed', item === button ? 'true' : 'false');
                });
                applyBusinessFilters();
            });
        });
        if (searchInput) searchInput.addEventListener('input', applyBusinessFilters);
        dismissAfterOneSecond(document.getElementById('permitFlashNotice'));
        closeButton.addEventListener('click', closeDetails);
        backdrop.addEventListener('click', function (event) {
            if (event.target === backdrop) closeDetails();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !backdrop.hidden) closeDetails();
        });
        modal.addEventListener('keydown', function (event) {
            if (event.key !== 'Tab') return;
            var focusable = Array.from(modal.querySelectorAll('button:not(:disabled), input:not(:disabled), a[href]'));
            if (!focusable.length) {
                event.preventDefault();
                modal.focus();
                return;
            }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
        if (window.location.search.indexOf('business_id=') !== -1) {
            var requestedId = new URLSearchParams(window.location.search).get('business_id');
            var target = Array.from(document.querySelectorAll('[data-business-row]')).find(function (row) {
                try { return JSON.parse(row.getAttribute('data-business')).business_entity_id === Number(requestedId); } catch (error) { return false; }
            });
            if (target) openDetails(target);
        }
    })();
</script>
</body>
</html>

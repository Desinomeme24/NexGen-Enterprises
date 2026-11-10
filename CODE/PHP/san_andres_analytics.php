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
$totalRevenue = 0.0;
$totalCosts = 0.0;
$totalProfit = 0.0;
$maxRevenue = 0.0;
while ($row = $financialResult->fetch_assoc()) {
    $row['gross_revenue'] = (float)$row['gross_revenue'];
    $row['cost_of_goods_sold'] = (float)$row['cost_of_goods_sold'];
    $row['net_profit'] = (float)$row['net_profit'];
    $totalRevenue += $row['gross_revenue'];
    $totalCosts += $row['cost_of_goods_sold'];
    $totalProfit += $row['net_profit'];
    $maxRevenue = max($maxRevenue, $row['gross_revenue']);
    $businesses[] = $row;
}
$businessCount = count($businesses);

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
    <title>San Andres Analytics | NexGen</title>
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
            align-items: flex-end;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 22px;
        }
        .analytics-heading h1 { margin: 0 0 6px; }
        .analytics-heading p { margin: 0; color: var(--muted); }
        .analytics-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 20px;
        }
        .analytics-metric {
            min-width: 0;
            padding: 18px;
            border: 1px solid var(--line);
            border-radius: 16px;
            background: var(--panel, var(--card));
        }
        .analytics-metric span { display: block; color: var(--muted); font-size: .82rem; }
        .analytics-metric strong {
            display: block;
            margin-top: 8px;
            overflow-wrap: anywhere;
            color: var(--text);
            font-size: clamp(1.05rem, 2vw, 1.55rem);
        }
        .analytics-business { min-width: 210px; }
        .analytics-business-name { display: block; font-weight: 750; color: var(--text); }
        .analytics-owner { display: block; margin-top: 4px; color: var(--muted); font-size: .8rem; }
        .analytics-bar-track {
            height: 7px;
            margin-top: 8px;
            overflow: hidden;
            border-radius: 99px;
            background: var(--line);
        }
        .analytics-bar {
            height: 100%;
            border-radius: inherit;
            background: var(--gold, #eab75e);
        }
        .analytics-empty { padding: 24px; color: var(--muted); text-align: center; }
        .analytics-business-row { cursor: pointer; }
        .analytics-business-row:hover,
        .analytics-business-row:focus-visible { outline: 2px solid var(--gold, #eab75e); outline-offset: -2px; }
        .analytics-notice { margin-bottom: 16px; padding: 12px 16px; border-radius: 10px; }
        .notice-success { color: var(--text); background: rgba(42, 160, 96, .14); }
        .notice-error { color: var(--text); background: rgba(210, 65, 65, .14); }
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
            max-height: min(92vh, 900px);
            overflow: auto;
            padding: clamp(18px, 3vw, 32px);
            border: 1px solid var(--line);
            border-radius: 20px;
            color: var(--text);
            background: var(--panel, var(--card));
            box-shadow: 0 24px 80px rgba(0, 0, 0, .35);
        }
        .analytics-modal-header,
        .analytics-modal-section-heading { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
        .analytics-modal-header h2 { margin: 0 0 6px; }
        .analytics-modal-header p,
        .analytics-modal-section-heading p { margin: 0; color: var(--muted); }
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
        .analytics-modal-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 20px 0; }
        .analytics-detail-card,
        .analytics-quarter-card {
            min-width: 0;
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: var(--card);
        }
        .analytics-detail-card h3,
        .analytics-quarter-card h4 { margin: 0 0 8px; font-size: 1rem; }
        .analytics-detail-card p { margin: 0; overflow-wrap: anywhere; color: var(--muted); }
        .analytics-modal-section { margin-top: 24px; }
        .analytics-modal-section-heading { margin-bottom: 14px; align-items: center; }
        .analytics-modal-section-heading h3 { margin: 0 0 5px; }
        .analytics-permit-summary { font-weight: 700; white-space: nowrap; }
        .analytics-quarter-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
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
        .analytics-assessment-form { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; margin-top: 16px; }
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
        @media (max-width: 900px) {
            .analytics-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .analytics-quarter-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 560px) {
            .analytics-heading { align-items: flex-start; flex-direction: column; }
            .analytics-summary { grid-template-columns: 1fr; }
            .analytics-modal-backdrop { padding: 8px; }
            .analytics-modal-grid, .analytics-quarter-grid { grid-template-columns: 1fr; }
            .analytics-modal-section-heading { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="admin-shell">
    <?php include __DIR__ . '/admin_sidebar.php'; ?>
    <main class="admin-content">
        <?php if ($permitFlash !== ''): ?>
            <div class="analytics-notice <?php echo e($permitFlashType); ?>" role="status"><?php echo e($permitFlash); ?></div>
        <?php endif; ?>
        <div class="topbar">
            <div class="analytics-heading">
                <div>
                    <div class="page-title"><h1>San Andres Analytics</h1></div>
                    <p>Revenue and net profit across all registered businesses.</p>
                </div>
                <div class="dashboard-date">
                    <i class="bi bi-calendar3"></i>
                    <?php echo e(date('l, F d, Y')); ?>
                </div>
            </div>
        </div>

        <section class="analytics-summary" aria-label="Business financial summary">
            <article class="analytics-metric">
                <span>Registered businesses</span>
                <strong><?php echo number_format($businessCount); ?></strong>
            </article>
            <article class="analytics-metric">
                <span>Total gross revenue</span>
                <strong><?php echo e(nxAnalyticsMoney($totalRevenue)); ?></strong>
            </article>
            <article class="analytics-metric">
                <span>Cost of goods sold</span>
                <strong><?php echo e(nxAnalyticsMoney($totalCosts)); ?></strong>
            </article>
            <article class="analytics-metric">
                <span>Net profit</span>
                <strong><?php echo e(nxAnalyticsMoney($totalProfit)); ?></strong>
            </article>
        </section>

        <section class="panel" aria-labelledby="analyticsBusinessesHeading">
            <div class="panel-header">
                <div>
                    <h2 id="analyticsBusinessesHeading">Business Performance</h2>
                    <p>Net profit is gross revenue minus cost of goods sold; operating expenses are excluded.</p>
                </div>
            </div>
            <div class="panel-body">
                <?php if ($businesses === []): ?>
                    <div class="analytics-empty">No registered businesses are available to report.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <?php
                                $entityId = (int)$business['business_entity_id'];
                                $businessPayload = [
                                    'business_entity_id' => $entityId,
                                    'business_name' => (string)$business['business_name'],
                                    'owner_names' => (string)$business['owner_names'],
                                    'locations' => $locationsByEntity[$entityId] ?? [],
                                    'gross_revenue' => $revenue,
                                    'cost_of_goods_sold' => (float)$business['cost_of_goods_sold'],
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
                                    data-business="<?php echo e((string)json_encode($businessPayload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP)); ?>">
                                    <th>Business</th>
                                    <th>Gross Revenue</th>
                                    <th>Cost of Goods Sold</th>
                                    <th>Net Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($businesses as $business): ?>
                                <?php
                                $revenue = (float)$business['gross_revenue'];
                                $barWidth = $maxRevenue > 0 ? min(100, ($revenue / $maxRevenue) * 100) : 0;
                                ?>
                                <tr>
                                    <td class="analytics-business">
                                        <span class="analytics-business-name"><?php echo e($business['business_name']); ?></span>
                                        <span class="analytics-owner"><?php echo e($business['owner_names']); ?></span>
                                        <span class="analytics-bar-track" aria-hidden="true">
                                            <span class="analytics-bar" style="display:block;width:<?php echo e((string)$barWidth); ?>%"></span>
                                        </span>
                                    </td>
                                    <td data-label="Gross Revenue"><?php echo e(nxAnalyticsMoney($revenue)); ?></td>
                                    <td data-label="Cost of Goods Sold"><?php echo e(nxAnalyticsMoney((float)$business['cost_of_goods_sold'])); ?></td>
                                    <td data-label="Net Profit"><?php echo e(nxAnalyticsMoney((float)$business['net_profit'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<div class="analytics-modal-backdrop" id="businessDetailsBackdrop" hidden>
    <section class="analytics-modal" id="businessDetailsModal" role="dialog" aria-modal="true" aria-labelledby="businessDetailsTitle" tabindex="-1">
        <div class="analytics-modal-header">
            <div>
                <h2 id="businessDetailsTitle"></h2>
                <p id="businessDetailsOwner"></p>
            </div>
            <button type="button" class="analytics-modal-close" id="businessDetailsClose" aria-label="Close business details">&times;</button>
        </div>
        <div class="analytics-modal-grid" id="businessDetailsFinancials"></div>
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
            <p class="analytics-permit-help" id="permitAccessSummary"></p>
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
        var phpEndpoint = <?php echo json_encode(nxAppUrl('update_business_permit.php'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

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
            financials.replaceChildren();
            addCard(financials, 'Gross revenue', money(data.gross_revenue));
            addCard(financials, 'Cost of goods sold', money(data.cost_of_goods_sold));
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
                accessSummary.textContent = 'Business access: Active · permit paid.';
            } else {
                var unpaidDate = data.permit_unpaid_since ? new Date(data.permit_unpaid_since.replace(' ', 'T')) : null;
                var graceDeadline = unpaidDate && !Number.isNaN(unpaidDate.getTime())
                    ? new Date(unpaidDate.getTime() + 30 * 24 * 60 * 60 * 1000)
                    : null;
                accessSummary.textContent = graceDeadline && graceDeadline.getTime() > Date.now()
                    ? 'Business access: Active during grace period through ' + graceDeadline.toLocaleDateString('en-PH') + '.'
                    : 'Business access: Inactive · permit grace period has expired.';
            }
            quarters.replaceChildren();
            controls.replaceChildren();
            if (!data.permit_tracking_ready) {
                document.getElementById('permitAnnualTotal').textContent = 'Tracking not configured';
                addCard(quarters, 'Migration required', 'Apply the quarterly permit-payment migration before recording assessments or payments.');
            } else {
                var assessment = data.permit;
                document.getElementById('permitAnnualTotal').textContent = assessment
                    ? 'Annual fee: ' + money(assessment.annual_fee)
                    : 'Annual fee: not set';
                if (!assessment) {
                    addCard(
                        quarters,
                        'No annual assessment',
                        'Set this year’s annual permit fee to create four unpaid quarterly records. Any started quarter left unpaid for more than 30 days can make business access inactive.'
                    );
                } else {
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
                        status.textContent = quarter && quarter.payment_status === 'paid'
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
                controls.appendChild(assessmentForm);
                if (feeInput.readOnly) {
                    var lockedNote = document.createElement('p');
                    lockedNote.className = 'analytics-permit-help';
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

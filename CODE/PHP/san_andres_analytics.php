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
            COALESCE(revenue.gross_revenue, 0) - COALESCE(costs.cost_of_goods_sold, 0) AS net_profit
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
        @media (max-width: 900px) {
            .analytics-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 560px) {
            .analytics-heading { align-items: flex-start; flex-direction: column; }
            .analytics-summary { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
<div class="admin-shell">
    <?php include __DIR__ . '/admin_sidebar.php'; ?>
    <main class="admin-content">
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
                                <tr>
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
<script src="<?php echo e(nxCodeUrl('JS/admin_module.js')); ?>"></script>
</body>
</html>

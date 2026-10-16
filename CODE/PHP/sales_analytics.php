<?php
session_start();
require_once("config.php");
require_once __DIR__ . '/tenant_helper.php';
require_once __DIR__ . '/milestone_helper.php';
$businessId = nxRequireBusinessId($conn);

if (!isset($_SESSION['user_id'])) {
    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'owner') {
    $_SESSION['error'] = 'Only owners can access Sales Analytics.';
    header("Location: /NexGen/CODE/PHP/dashboard.php");
    exit();
}

date_default_timezone_set('Asia/Manila');

/* config.php sets the MySQL connection to UTC+8 as well, so the local
   Manila date boundaries below can be compared directly with sales.sale_date.
   No UTC conversion is performed in this page. */
$displayName = $_SESSION['username'] ?? 'Client';
$fullName = $_SESSION['full_name'] ?? 'Client';
$profileImage = !empty($_SESSION['profile_image']) ? $_SESSION['profile_image'] : 'uploads/default.png';
$userId = $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| NOTIFICATION READ TRACKING
|--------------------------------------------------------------------------
| Persist the read timestamp per user/module in notification_reads.
| Important: when there is no row yet, do NOT initialize it to NOW().
| Doing that would instantly mark already-created milestone notifications
| as read on the user's first visit to Sales Analytics.
*/
$notifModule = 'sales_analytics';
$userId = (int)$userId;

if (isset($_GET['action']) && $_GET['action'] === 'mark_notifications_seen') {
    $seenAt = date('Y-m-d H:i:s');
    $markStmt = $conn->prepare(
        "INSERT INTO notification_reads (user_id, module, last_seen_at)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)"
    );

    if (!$markStmt) {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unable to update notification read status.'
        ]);
        exit();
    }

    $markStmt->bind_param('iss', $userId, $notifModule, $seenAt);
    $markStmt->execute();
    $markStmt->close();

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Notifications marked as seen.',
        'last_seen_at' => $seenAt
    ]);
    exit();
}

$notificationsLastSeen = '1970-01-01 00:00:00';
$lastSeenStmt = $conn->prepare(
    "SELECT last_seen_at
     FROM notification_reads
     WHERE user_id = ? AND module = ?
     LIMIT 1"
);
if ($lastSeenStmt) {
    $lastSeenStmt->bind_param('is', $userId, $notifModule);
    $lastSeenStmt->execute();
    $lastSeenRow = $lastSeenStmt->get_result()->fetch_assoc();
    $lastSeenStmt->close();

    if (!empty($lastSeenRow['last_seen_at'])) {
        $notificationsLastSeen = $lastSeenRow['last_seen_at'];
    }
}

/*
|--------------------------------------------------------------------------
| FILTER SETUP
|--------------------------------------------------------------------------
*/
$filter = $_GET['filter'] ?? 'today';
$allowedFilters = ['today', 'week', 'month', 'custom'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'today';
}

$customStart = $_GET['start_date'] ?? '';
$customEnd = $_GET['end_date'] ?? '';

$today = new DateTime();
$rangeLabel = 'Today';

switch ($filter) {
    case 'today':
        $startDate = $today->format('Y-m-d 00:00:00');
        $endDate   = $today->format('Y-m-d 23:59:59');
        $rangeLabel = 'Today';
        break;

    case 'week':
        $monday = new DateTime();
        $monday->modify('monday this week');
        $sunday = new DateTime();
        $sunday->modify('sunday this week');
        $startDate = $monday->format('Y-m-d 00:00:00');
        $endDate   = $sunday->format('Y-m-d 23:59:59');
        $rangeLabel = 'This Week';
        break;

    case 'month':
        $firstDay = new DateTime('first day of this month');
        $lastDay  = new DateTime('last day of this month');
        $startDate = $firstDay->format('Y-m-d 00:00:00');
        $endDate   = $lastDay->format('Y-m-d 23:59:59');
        $rangeLabel = 'This Month';
        break;

    case 'custom':
        if (!empty($customStart) && !empty($customEnd)) {
            $startDate = date('Y-m-d 00:00:00', strtotime($customStart));
            $endDate   = date('Y-m-d 23:59:59', strtotime($customEnd));
            $rangeLabel = date('M d, Y', strtotime($customStart)) . ' - ' . date('M d, Y', strtotime($customEnd));
        } else {
            $startDate = $today->format('Y-m-d 00:00:00');
            $endDate   = $today->format('Y-m-d 23:59:59');
            $rangeLabel = 'Today';
            $filter = 'today';
        }
        break;
}

/*
|--------------------------------------------------------------------------
| SUMMARY CARD COMPUTATION
|--------------------------------------------------------------------------
*/
/*
|--------------------------------------------------------------------------
| SUMMARY CARD COMPUTATION
|--------------------------------------------------------------------------
*/
$netProfit = 0.00;
$grossRevenue = 0.00;
$costOfGoodsSold = 0.00;
$totalTransactions = 0;
$salesGrowth = 0.00;

/*
|--------------------------------------------------------------------------
| MAIN SUMMARY FOR SELECTED RANGE
|--------------------------------------------------------------------------
*/
$stmtSummary = $conn->prepare("
    SELECT 
        COALESCE(SUM(total_amount), 0) AS gross_revenue,
        COUNT(*) AS total_transactions
    FROM sales
    WHERE business_id = {$businessId} AND sale_date BETWEEN ? AND ?
");
$stmtSummary->bind_param("ss", $startDate, $endDate);
$stmtSummary->execute();
$summaryRow = $stmtSummary->get_result()->fetch_assoc();
$stmtSummary->close();

$grossRevenue = (float)($summaryRow['gross_revenue'] ?? 0);
$totalTransactions = (int)($summaryRow['total_transactions'] ?? 0);

$stmtCogs = $conn->prepare("
    SELECT 
        COALESCE(SUM(p.cost_price * si.quantity), 0) AS cost_of_goods_sold
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    WHERE s.business_id = {$businessId} AND p.business_id = {$businessId} AND s.sale_date BETWEEN ? AND ?
");
$stmtCogs->bind_param("ss", $startDate, $endDate);
$stmtCogs->execute();
$cogsRow = $stmtCogs->get_result()->fetch_assoc();
$stmtCogs->close();

$costOfGoodsSold = (float)($cogsRow['cost_of_goods_sold'] ?? 0);
$netProfit = $grossRevenue - $costOfGoodsSold;

/*
|--------------------------------------------------------------------------
| SALES GROWTH
|--------------------------------------------------------------------------
| Still based on gross revenue comparison
|--------------------------------------------------------------------------
*/
$currentStartTs = strtotime($startDate);
$currentEndTs = strtotime($endDate);
$rangeSeconds = max(1, ($currentEndTs - $currentStartTs) + 1);

$previousEndTs = $currentStartTs - 1;
$previousStartTs = $previousEndTs - $rangeSeconds + 1;

$previousStart = date('Y-m-d H:i:s', $previousStartTs);
$previousEnd   = date('Y-m-d H:i:s', $previousEndTs);

$stmtPrev = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) AS previous_revenue
    FROM sales
    WHERE business_id = {$businessId} AND sale_date BETWEEN ? AND ?
");
$stmtPrev->bind_param("ss", $previousStart, $previousEnd);
$stmtPrev->execute();
$prevRow = $stmtPrev->get_result()->fetch_assoc();
$stmtPrev->close();

$previousRevenue = (float)($prevRow['previous_revenue'] ?? 0);

if ($previousRevenue > 0) {
    $salesGrowth = (($grossRevenue - $previousRevenue) / $previousRevenue) * 100;
} elseif ($grossRevenue > 0) {
    $salesGrowth = 100.00;
} else {
    $salesGrowth = 0.00;
}

/*
|--------------------------------------------------------------------------
| FIXED PERIOD VALUES
|--------------------------------------------------------------------------
*/
$todayProfit = 0.00;
$weekProfit = 0.00;
$monthProfit = 0.00;

/* Today */
$todayStart = date('Y-m-d 00:00:00');
$todayEnd   = date('Y-m-d 23:59:59');

$stmtTodayRevenue = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) AS gross_revenue
    FROM sales
    WHERE business_id = {$businessId} AND sale_date BETWEEN ? AND ?
");
$stmtTodayRevenue->bind_param("ss", $todayStart, $todayEnd);
$stmtTodayRevenue->execute();
$todayRevenueRow = $stmtTodayRevenue->get_result()->fetch_assoc();
$stmtTodayRevenue->close();

$stmtTodayCogs = $conn->prepare("
    SELECT COALESCE(SUM(p.cost_price * si.quantity), 0) AS cost_of_goods_sold
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    WHERE s.business_id = {$businessId} AND p.business_id = {$businessId} AND s.sale_date BETWEEN ? AND ?
");
$stmtTodayCogs->bind_param("ss", $todayStart, $todayEnd);
$stmtTodayCogs->execute();
$todayCogsRow = $stmtTodayCogs->get_result()->fetch_assoc();
$stmtTodayCogs->close();

$todayRevenue = (float)($todayRevenueRow['gross_revenue'] ?? 0);
$todayCogs = (float)($todayCogsRow['cost_of_goods_sold'] ?? 0);
$todayProfit = $todayRevenue - $todayCogs;

/* Week */
$weekStartObj = new DateTime();
$weekStartObj->modify('monday this week');
$weekEndObj = new DateTime();
$weekEndObj->modify('sunday this week');

$weekStart = $weekStartObj->format('Y-m-d 00:00:00');
$weekEnd   = $weekEndObj->format('Y-m-d 23:59:59');

$stmtWeekRevenue = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) AS gross_revenue
    FROM sales
    WHERE business_id = {$businessId} AND sale_date BETWEEN ? AND ?
");
$stmtWeekRevenue->bind_param("ss", $weekStart, $weekEnd);
$stmtWeekRevenue->execute();
$weekRevenueRow = $stmtWeekRevenue->get_result()->fetch_assoc();
$stmtWeekRevenue->close();

$stmtWeekCogs = $conn->prepare("
    SELECT COALESCE(SUM(p.cost_price * si.quantity), 0) AS cost_of_goods_sold
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    WHERE s.business_id = {$businessId} AND p.business_id = {$businessId} AND s.sale_date BETWEEN ? AND ?
");
$stmtWeekCogs->bind_param("ss", $weekStart, $weekEnd);
$stmtWeekCogs->execute();
$weekCogsRow = $stmtWeekCogs->get_result()->fetch_assoc();
$stmtWeekCogs->close();

$weekRevenue = (float)($weekRevenueRow['gross_revenue'] ?? 0);
$weekCogs = (float)($weekCogsRow['cost_of_goods_sold'] ?? 0);
$weekProfit = $weekRevenue - $weekCogs;

/* Month */
$monthStartObj = new DateTime('first day of this month');
$monthEndObj = new DateTime('last day of this month');

$monthStart = $monthStartObj->format('Y-m-d 00:00:00');
$monthEnd   = $monthEndObj->format('Y-m-d 23:59:59');

$stmtMonthRevenue = $conn->prepare("
    SELECT COALESCE(SUM(total_amount), 0) AS gross_revenue
    FROM sales
    WHERE business_id = {$businessId} AND sale_date BETWEEN ? AND ?
");
$stmtMonthRevenue->bind_param("ss", $monthStart, $monthEnd);
$stmtMonthRevenue->execute();
$monthRevenueRow = $stmtMonthRevenue->get_result()->fetch_assoc();
$stmtMonthRevenue->close();

$stmtMonthCogs = $conn->prepare("
    SELECT COALESCE(SUM(p.cost_price * si.quantity), 0) AS cost_of_goods_sold
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    WHERE s.business_id = {$businessId} AND p.business_id = {$businessId} AND s.sale_date BETWEEN ? AND ?
");
$stmtMonthCogs->bind_param("ss", $monthStart, $monthEnd);
$stmtMonthCogs->execute();
$monthCogsRow = $stmtMonthCogs->get_result()->fetch_assoc();
$stmtMonthCogs->close();

$monthRevenue = (float)($monthRevenueRow['gross_revenue'] ?? 0);
$monthCogs = (float)($monthCogsRow['cost_of_goods_sold'] ?? 0);
$monthProfit = $monthRevenue - $monthCogs;

/*
|--------------------------------------------------------------------------
| DAILY NET PROFIT (Mon-Sun) FOR CURRENT WEEK
|--------------------------------------------------------------------------
*/
$dailyLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$dailySalesData = [0, 0, 0, 0, 0, 0, 0];

$stmtDailyRevenue = $conn->prepare("
    SELECT DAYOFWEEK(sale_date) AS weekday_num,
           COALESCE(SUM(total_amount), 0) AS gross_revenue
    FROM sales
    WHERE business_id = {$businessId} AND sale_date BETWEEN ? AND ?
    GROUP BY DAYOFWEEK(sale_date)
");
$stmtDailyRevenue->bind_param("ss", $weekStart, $weekEnd);
$stmtDailyRevenue->execute();
$dailyRevenueResult = $stmtDailyRevenue->get_result();

$dailyRevenueMap = [];
while ($row = $dailyRevenueResult->fetch_assoc()) {
    $dailyRevenueMap[(int)$row['weekday_num']] = (float)$row['gross_revenue'];
}
$stmtDailyRevenue->close();

$stmtDailyCogs = $conn->prepare("
    SELECT DAYOFWEEK(s.sale_date) AS weekday_num,
           COALESCE(SUM(p.cost_price * si.quantity), 0) AS cost_of_goods_sold
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    WHERE s.business_id = {$businessId} AND p.business_id = {$businessId} AND s.sale_date BETWEEN ? AND ?
    GROUP BY DAYOFWEEK(s.sale_date)
");
$stmtDailyCogs->bind_param("ss", $weekStart, $weekEnd);
$stmtDailyCogs->execute();
$dailyCogsResult = $stmtDailyCogs->get_result();

$dailyCogsMap = [];
while ($row = $dailyCogsResult->fetch_assoc()) {
    $dailyCogsMap[(int)$row['weekday_num']] = (float)$row['cost_of_goods_sold'];
}
$stmtDailyCogs->close();

$map = [
    2 => 0,
    3 => 1,
    4 => 2,
    5 => 3,
    6 => 4,
    7 => 5,
    1 => 6
];

foreach ($map as $mysqlDay => $index) {
    $revenue = $dailyRevenueMap[$mysqlDay] ?? 0;
    $cogs = $dailyCogsMap[$mysqlDay] ?? 0;
    $dailySalesData[$index] = $revenue - $cogs;
}

/*
|--------------------------------------------------------------------------
| MONTHLY NET PROFIT (Jan-Dec) FOR CURRENT YEAR
|--------------------------------------------------------------------------
*/
$monthlyLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
$monthlyRevenueData = array_fill(0, 12, 0);

$currentYear = (int)date('Y');

$stmtMonthlyRevenue = $conn->prepare("
    SELECT MONTH(sale_date) AS sale_month,
           COALESCE(SUM(total_amount), 0) AS gross_revenue
    FROM sales
    WHERE business_id = {$businessId} AND YEAR(sale_date) = ?
    GROUP BY MONTH(sale_date)
    ORDER BY MONTH(sale_date)
");
$stmtMonthlyRevenue->bind_param("i", $currentYear);
$stmtMonthlyRevenue->execute();
$monthlyRevenueResult = $stmtMonthlyRevenue->get_result();

$monthlyRevenueMap = [];
while ($row = $monthlyRevenueResult->fetch_assoc()) {
    $monthlyRevenueMap[(int)$row['sale_month']] = (float)$row['gross_revenue'];
}
$stmtMonthlyRevenue->close();

$stmtMonthlyCogs = $conn->prepare("
    SELECT MONTH(s.sale_date) AS sale_month,
           COALESCE(SUM(p.cost_price * si.quantity), 0) AS cost_of_goods_sold
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    WHERE s.business_id = {$businessId} AND p.business_id = {$businessId} AND YEAR(s.sale_date) = ?
    GROUP BY MONTH(s.sale_date)
    ORDER BY MONTH(s.sale_date)
");
$stmtMonthlyCogs->bind_param("i", $currentYear);
$stmtMonthlyCogs->execute();
$monthlyCogsResult = $stmtMonthlyCogs->get_result();

$monthlyCogsMap = [];
while ($row = $monthlyCogsResult->fetch_assoc()) {
    $monthlyCogsMap[(int)$row['sale_month']] = (float)$row['cost_of_goods_sold'];
}
$stmtMonthlyCogs->close();

for ($m = 1; $m <= 12; $m++) {
    $revenue = $monthlyRevenueMap[$m] ?? 0;
    $cogs = $monthlyCogsMap[$m] ?? 0;
    $monthlyRevenueData[$m - 1] = $revenue - $cogs;
}

/*
|--------------------------------------------------------------------------
| CATEGORY DATA (SELECTED RANGE)
|--------------------------------------------------------------------------
*/
$categoryLabels = [];
$categorySalesData = [];
$categoryBreakdown = [];

$stmtCategory = $conn->prepare("
    SELECT 
        c.category_name,
        COALESCE(SUM(si.quantity), 0) AS units_sold
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    INNER JOIN categories c ON p.category_id = c.id AND c.business_id = {$businessId}
    WHERE s.business_id = {$businessId} AND p.business_id = {$businessId} AND s.sale_date BETWEEN ? AND ?
    GROUP BY c.id, c.category_name
    ORDER BY units_sold DESC, c.category_name ASC
");
$stmtCategory->bind_param("ss", $startDate, $endDate);
$stmtCategory->execute();
$categoryResult = $stmtCategory->get_result();

$totalCategoryUnits = 0;
$tempCategories = [];

while ($row = $categoryResult->fetch_assoc()) {
    $units = (int)$row['units_sold'];
    $tempCategories[] = [
        'name' => $row['category_name'],
        'units_sold' => $units
    ];
    $totalCategoryUnits += $units;
}
$stmtCategory->close();

if (!empty($tempCategories)) {
    foreach ($tempCategories as $item) {
        $percentage = $totalCategoryUnits > 0 ? ($item['units_sold'] / $totalCategoryUnits) * 100 : 0;
        $categoryLabels[] = $item['name'];
        $categorySalesData[] = $item['units_sold'];
        $categoryBreakdown[] = [
            'name' => $item['name'],
            'units_sold' => $item['units_sold'],
            'percentage' => $percentage
        ];
    }
} else {
    $categoryLabels = ['No Category'];
    $categorySalesData = [0];
    $categoryBreakdown[] = [
        'name' => 'No Category',
        'units_sold' => 0,
        'percentage' => 0
    ];
}

/*
|--------------------------------------------------------------------------
| TOP PRODUCTS
|--------------------------------------------------------------------------
*/
$topProductsBySales = [];

$stmtTopProducts = $conn->prepare("
    SELECT 
        p.product_name,
        COALESCE(SUM(si.quantity * si.unit_price), 0) AS total_sales
    FROM sale_items si
    INNER JOIN sales s ON si.sale_id = s.id
    INNER JOIN products p ON si.product_id = p.id
    WHERE s.business_id = {$businessId} AND p.business_id = {$businessId} AND s.sale_date BETWEEN ? AND ?
    GROUP BY p.id, p.product_name
    ORDER BY total_sales DESC, p.product_name ASC
    LIMIT 5
");
$stmtTopProducts->bind_param("ss", $startDate, $endDate);
$stmtTopProducts->execute();
$topProductsResult = $stmtTopProducts->get_result();

while ($row = $topProductsResult->fetch_assoc()) {
    $topProductsBySales[] = [
        'name' => $row['product_name'],
        'sales' => (float)$row['total_sales']
    ];
}
$stmtTopProducts->close();

/*
|--------------------------------------------------------------------------
| NOTIFICATIONS
|--------------------------------------------------------------------------
*/
$notifications = [];
$notificationCount = 0;

/* Sales milestones */
/* Today/Week/Month: live tiered milestones, recorded by                    */
/* nxCheckAndRecordMilestones() right when a sale is saved (see             */
/* process_sale_ajax.php / process_sale.php).                               */
/* Quarter/Semi-Annual/Annual: completed-period achievements only - they    */
/* never fire mid-period no matter how many tiers are crossed, and only     */
/* record once the period is fully over. We also run the completed-period   */
/* check here on page load, so an achievement still surfaces even if no new */
/* sale has happened yet in the period right after the one that just ended. */
// Reconcile live Daily/Weekly/Monthly milestones on page load too.
// This is safe because sales_milestones has a unique key and the helper uses
// INSERT IGNORE, so an already-recorded threshold is never duplicated.
// It also keeps the existing completed Quarter/Semi-Annual/Annual checks.
nxCheckAndRecordMilestones($conn, $businessId, new DateTime());

$periodLabels = [
    'today' => ['title' => 'Daily Sales Milestone Reached!', 'when' => 'today'],
    'week'  => ['title' => 'Weekly Sales Milestone Reached!', 'when' => 'this week'],
    'month' => ['title' => 'Monthly Sales Milestone Reached!', 'when' => 'this month'],
];

$milestoneStmt = $conn->prepare("
    SELECT period_type, period_bucket, threshold, actual_amount, reached_at
    FROM sales_milestones
    WHERE business_id = ?
    ORDER BY reached_at DESC, threshold DESC
");
if ($milestoneStmt) {
    $milestoneStmt->bind_param("i", $businessId);
    $milestoneStmt->execute();
    $milestoneResult = $milestoneStmt->get_result();

    $seenPeriods = [];
    while ($row = $milestoneResult->fetch_assoc()) {
        $periodType = $row['period_type'];

        /* Only show the latest milestone/achievement per period type */
        if (isset($seenPeriods[$periodType])) {
            continue;
        }
        $seenPeriods[$periodType] = true;

        $reached = (int)$row['threshold'];
        $reachedAt = $row['reached_at'];
        $bucket = $row['period_bucket'];
        $isUnread = strtotime($reachedAt) > strtotime($notificationsLastSeen);

        if ($isUnread) {
            $notificationCount++;
        }

        if (in_array($periodType, ['today', 'week', 'month'], true)) {
            /* Live milestone */
            $periodCopy = $periodLabels[$periodType] ?? [
                'title' => 'Sales Milestone Reached!',
                'when' => $periodType
            ];

            $notifications[] = [
                'type' => 'success',
                'icon' => 'bi-trophy-fill',
                'title' => $periodCopy['title'],
                'message' => 'Your store reached ₱' . number_format($reached) . ' in sales ' . $periodCopy['when'] . '.',
                'time' => date('M d, Y h:i A', strtotime($reachedAt)),
                'is_unread' => $isUnread
            ];
        } else {
            /* Completed-period achievement */
            $actual = (float)($row['actual_amount'] ?? 0);
            $actualLabel = nxMilestoneLabel((int)round($actual));

            if ($periodType === 'quarter') {
                preg_match('/^(\d{4})-Q(\d)$/', $bucket, $m);
                $year = $m[1] ?? '';
                $q = $m[2] ?? '';
                $title = 'Quarterly Sales Achievement';
                $message = "Your store completed Q{$q} {$year} with ₱" . number_format($actual) . " in total sales!";
                $icon = 'bi-trophy-fill';
            } elseif ($periodType === 'semi_annual') {
                preg_match('/^(\d{4})-H(\d)$/', $bucket, $m);
                $year = $m[1] ?? '';
                $half = ($m[2] ?? '') === '1' ? 'first half' : 'second half';
                $title = 'Semi-Annual Sales Achievement';
                $message = "Your store generated ₱" . number_format($actual) . " in total sales during the {$half} of {$year}!";
                $icon = 'bi-stars';
            } else {
                /* annual */
                $title = 'Annual Sales Achievement';
                $message = "Congratulations! Your store generated ₱" . number_format($actual) . " in total sales in {$bucket}!";
                $icon = 'bi-trophy-fill';
            }

            $notifications[] = [
                'type' => 'achievement',
                'icon' => $icon,
                'title' => $title,
                'message' => $message,
                'time' => date('M d, Y h:i A', strtotime($reachedAt)),
                'is_unread' => $isUnread
            ];
        }
    }
    $milestoneStmt->close();
}

/* Sales Analytics notifications are intentionally milestone-only.
   Inventory alerts belong in Inventory Management and are not shown here. */

$displayNotifications = $notifications;
if (empty($displayNotifications)) {
    $displayNotifications[] = [
        'type' => 'empty',
        'icon' => 'bi-info-circle-fill',
        'title' => 'No Milestones Yet',
        'message' => 'No sales milestones have been reached yet.',
        'time' => 'Just now',
        'is_unread' => false
    ];
}

/*
|--------------------------------------------------------------------------
| PAGE MESSAGES
|--------------------------------------------------------------------------
*/
$popupMessage = "";
$popupType = "";

if (isset($_SESSION['success'])) {
    $popupMessage = $_SESSION['success'];
    $popupType = "success";
    unset($_SESSION['success']);
} elseif (isset($_SESSION['error'])) {
    $popupMessage = $_SESSION['error'];
    $popupType = "error";
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <?php include __DIR__ . '/theme_init.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales Analytics - NexGen</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/header.css">
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/sales_analytics.css?v=responsive20260907">
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/module_footer.css">
</head>
<body>

<?php if (!empty($popupMessage)): ?>
    <div class="popup-overlay" id="popupOverlay">
        <div class="popup-box <?php echo $popupType; ?>" id="popupBox">
            <div class="popup-icon"><?php echo $popupType === 'success' ? '✓' : '!'; ?></div>
            <h3><?php echo $popupType === 'success' ? 'Success' : 'Error'; ?></h3>
            <p><?php echo htmlspecialchars($popupMessage); ?></p>
        </div>
    </div>
<?php endif; ?>

<?php include 'header.php'; ?>

<div class="sales-analytics-page">
    <main class="main-panel">
        <div class="main-content" id="analyticsCaptureArea">

                    <section id="dashboardSection">
                        <section class="analytics-topbar">
                            <div class="dashboard-toolbar">
                                <div class="toolbar-heading">
                                    <h1>Sales Analytics Dashboard</h1>
                                    <p>Showing data for: <strong><?php echo htmlspecialchars($rangeLabel); ?></strong></p>
                                </div>

                                <div class="toolbar-controls">
                                    <button type="button" class="range-pill-btn" data-bs-toggle="modal" data-bs-target="#customRangeModal">
                                        <i class="bi bi-calendar3"></i>
                                        <span><?php echo htmlspecialchars($rangeLabel); ?></span>
                                        <i class="bi bi-chevron-down"></i>
                                    </button>

                                    <div class="filter-segmented" role="group" aria-label="Date range filter">
                                        <a href="?filter=today" class="segment-btn <?php echo $filter === 'today' ? 'active' : ''; ?>">Today</a>
                                        <a href="?filter=week" class="segment-btn <?php echo $filter === 'week' ? 'active' : ''; ?>">Week</a>
                                        <a href="?filter=month" class="segment-btn <?php echo $filter === 'month' ? 'active' : ''; ?>">Month</a>
                                        <button type="button" class="segment-btn <?php echo $filter === 'custom' ? 'active' : ''; ?>" data-bs-toggle="modal" data-bs-target="#customRangeModal">
                                            Custom <i class="bi bi-chevron-down"></i>
                                        </button>
                                    </div>

                                    <div class="topbar-exports">
                                        <button type="button" class="export-pill pdf-pill" id="exportPdfBtn">
                                            <i class="bi bi-file-earmark-pdf-fill"></i> PDF
                                        </button>
                                        <button type="button" class="export-pill excel-pill" id="exportExcelBtn">
                                            <i class="bi bi-file-earmark-excel-fill"></i> Excel
                                        </button>
                                    </div>

                                    <div class="analytics-title-actions">
                                        <button type="button" class="topbar-icon-btn" data-bs-toggle="modal" data-bs-target="#notificationsModal" aria-label="Notifications">
                                            <i class="bi bi-bell-fill"></i>
                                            <?php if ($notificationCount > 0): ?>
                                                <span class="icon-badge"><?php echo $notificationCount; ?></span>
                                            <?php endif; ?>
                                        </button>

                                        <button type="button" class="topbar-icon-btn" id="appendicesToggleBtn" aria-label="Key Components">
                                            <i class="bi bi-journal-text"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="summary-section">
                            <div class="row g-4">
                                <div class="col-md-6 col-xl">
                                    <div class="summary-card card-blue">
                                        <div class="summary-card-top">
                                            <div>
                                                <div class="summary-label">Net Profit</div>
                                                <div class="summary-value">₱<?php echo number_format($netProfit, 2); ?></div>
                                            </div>
                                            <div class="summary-icon-box">
                                                <i class="bi bi-cash-stack"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6 col-xl">
                                    <div class="summary-card card-blue">
                                        <div class="summary-card-top">
                                            <div>
                                                <div class="summary-label">Gross Revenue</div>
                                                <div class="summary-value">₱<?php echo number_format($grossRevenue, 2); ?></div>
                                            </div>
                                            <div class="summary-icon-box">
                                                <i class="bi bi-wallet2"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6 col-xl">
                                    <div class="summary-card card-blue">
                                        <div class="summary-card-top">
                                            <div>
                                                <div class="summary-label">COGS</div>
                                                <div class="summary-value">₱<?php echo number_format($costOfGoodsSold, 2); ?></div>
                                            </div>
                                            <div class="summary-icon-box">
                                                <i class="bi bi-box-seam-fill"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6 col-xl">
                                    <div class="summary-card card-gray">
                                        <div class="summary-card-top">
                                            <div>
                                                <div class="summary-label">Sales Growth</div>
                                                <div class="summary-value"><?php echo number_format($salesGrowth, 2); ?>%</div>
                                            </div>
                                            <div class="summary-icon-box">
                                                <i class="bi bi-bar-chart-fill"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6 col-xl">
                                    <div class="summary-card card-gray">
                                        <div class="summary-card-top">
                                            <div>
                                                <div class="summary-label">Total Transactions</div>
                                                <div class="summary-value"><?php echo number_format($totalTransactions); ?></div>
                                            </div>
                                            <div class="summary-icon-box">
                                                <i class="bi bi-receipt-cutoff"></i>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="charts-section mt-4">
                            <div class="row g-3">
                                <div class="col-xl-6">
                                    <div class="analytics-card">
                                        <div class="analytics-card-title">Daily Net Profit (Monday to Sunday)</div>
                                        <div class="canvas-holder">
                                            <canvas id="dailySalesChart"></canvas>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-xl-6">
                                    <div class="analytics-card">
                                        <div class="analytics-card-title">Monthly Net Profit (January to December)</div>
                                        <div class="canvas-holder">
                                            <canvas id="monthlyRevenueChart"></canvas>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section class="category-products-section mt-3">
                            <div class="row g-3">
                                <div class="col-xl-6">
                                    <div class="analytics-card analytics-card-light h-100">
                                        <div class="analytics-card-title light-title">Sales by Category</div>

                                        <div class="row g-3 align-items-center">
                                            <div class="col-lg-5">
                                                <div class="chart-wrap donut-wrap">
                                                    <canvas id="categoryChart"></canvas>
                                                </div>
                                            </div>

                                            <div class="col-lg-7">
                                                <div class="mini-table-wrap">
                                                    <div class="table-responsive">
                                                        <table class="table mini-sales-table mb-0">
                                                            <thead>
                                                                <tr>
                                                                    <th>Category</th>
                                                                    <th class="text-center">Units Sold</th>
                                                                    <th class="text-end">Percentage</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                            <?php
                                                            $topCategoryRows = array_slice($categoryBreakdown, 0, 3);
                                                            $catDotColors = ['dot-blue', 'dot-teal', 'dot-purple'];
                                                            foreach ($topCategoryRows as $catIndex => $item):
                                                                $dotClass = $catDotColors[$catIndex % count($catDotColors)];
                                                            ?>
                                                                <tr>
                                                                    <td><span class="cat-dot <?php echo $dotClass; ?>"></span><?php echo htmlspecialchars($item['name']); ?></td>
                                                                    <td class="text-center"><?php echo (int)$item['units_sold']; ?></td>
                                                                    <td class="text-end"><?php echo number_format($item['percentage'], 1); ?>%</td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>

                                                    <div class="table-footnote">Showing top <?php echo count($topCategoryRows); ?> categories</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-xl-6">
                                    <div class="analytics-card analytics-card-light h-100">
                                        <div class="analytics-card-title light-title">Top Products</div>

                                        <?php if (!empty($topProductsBySales)): ?>
                                            <div class="table-responsive">
                                                <table class="table top-product-table align-middle mb-0" id="topProductsTable">
                                                    <thead>
                                                        <tr>
                                                            <th class="col-rank">#</th>
                                                            <th>Product</th>
                                                            <th class="text-end">Total Sales</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($topProductsBySales as $rankIndex => $product): ?>
                                                        <tr>
                                                            <td class="col-rank"><?php echo $rankIndex + 1; ?></td>
                                                            <td><?php echo htmlspecialchars($product['name']); ?></td>
                                                            <td class="text-end">₱<?php echo number_format($product['sales'], 2); ?></td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php else: ?>
                                            <div class="empty-box large-empty light-empty">
                                                <div class="empty-icon"><i class="bi bi-box-seam"></i></div>
                                                <h5>No Top Products Yet</h5>
                                                <p>Top products will appear here after you start adding real sales in Sales Recording.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </section>

                    <section id="appendicesSection" class="d-none">
                        <section class="analytics-topbar">
                            <div class="analytics-title-wrap">
                                <div class="analytics-title-icon">
                                    <i class="bi bi-journal-text"></i>
                                </div>
                                <div class="analytics-title-text">
                                    <h1>Key Components</h1>
                                    <p>Sales analytics feature guide</p>
                                </div>
                            </div>
                            <div class="analytics-divider"></div>
                        </section>

                        <div class="appendices-card appendices-card-light">
                            <div class="appendices-search">
                                <i class="bi bi-search"></i>
                                <input type="text" id="appendicesSearchInput" placeholder="Search components, e.g. &quot;Top Products&quot;">
                            </div>

                            <div class="row g-3 appendices-grid" id="appendicesGrid">
                                <div class="col-md-6 appendix-col">
                                    <div class="appendix-item appendix-item-light accent-blue">
                                        <div class="appendix-icon-badge"><i class="bi bi-box-seam"></i></div>
                                        <h3>Sales Summary</h3>
                                        <p class="appendix-desc">Your top-line numbers at a glance.</p>
                                        <ul>
                                            <li><i class="bi bi-check-circle-fill"></i> Net Profit</li>
                                            <li><i class="bi bi-check-circle-fill"></i> Gross Revenue</li>
                                            <li><i class="bi bi-check-circle-fill"></i> COGS</li>
                                            <li><i class="bi bi-check-circle-fill"></i> Total Transactions</li>
                                        </ul>
                                    </div>
                                </div>

                                <div class="col-md-6 appendix-col">
                                    <div class="appendix-item appendix-item-light accent-teal">
                                        <div class="appendix-icon-badge"><i class="bi bi-calendar3"></i></div>
                                        <h3>Time Filters</h3>
                                        <p class="appendix-desc">Narrow the dashboard to the period you need.</p>
                                        <ul>
                                            <li><i class="bi bi-check-circle-fill"></i> Today / This Week</li>
                                            <li><i class="bi bi-check-circle-fill"></i> This Month</li>
                                            <li><i class="bi bi-check-circle-fill"></i> Custom Date Range</li>
                                        </ul>
                                    </div>
                                </div>

                                <div class="col-md-6 appendix-col">
                                    <div class="appendix-item appendix-item-light accent-purple">
                                        <div class="appendix-icon-badge"><i class="bi bi-bar-chart-line"></i></div>
                                        <h3>Sales Trends</h3>
                                        <p class="appendix-desc">Track performance over time and by category.</p>
                                        <ul>
                                            <li><i class="bi bi-check-circle-fill"></i> Daily Net Profit Chart</li>
                                            <li><i class="bi bi-check-circle-fill"></i> Monthly Net Profit Graph</li>
                                            <li><i class="bi bi-check-circle-fill"></i> Sales by Category</li>
                                        </ul>
                                    </div>
                                </div>

                                <div class="col-md-6 appendix-col">
                                    <div class="appendix-item appendix-item-light accent-gold">
                                        <div class="appendix-icon-badge"><i class="bi bi-trophy-fill"></i></div>
                                        <h3>Performance Analysis</h3>
                                        <p class="appendix-desc">See what's driving your results.</p>
                                        <ul>
                                            <li><i class="bi bi-check-circle-fill"></i> Top Selling Products</li>
                                            <li><i class="bi bi-check-circle-fill"></i> Category Comparison</li>
                                            <li><i class="bi bi-check-circle-fill"></i> Sales Distribution</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="appendices-empty d-none" id="appendicesEmptyState">
                                <div class="empty-icon"><i class="bi bi-search"></i></div>
                                <h5>No matching components</h5>
                                <p>Try a different search term.</p>
                            </div>

                            <div class="appendices-actions">
                                <button type="button" class="btn btn-back-dashboard" id="backToDashboardBtn">
                                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                                </button>
                            </div>
                        </div>
                    </section>

        </div>
    </main>

    <footer class="nx-footer">
        <div class="nx-footer-line"></div>
        <p class="nx-footer-copy">Copyright &copy; 2026 NexGen.</p>
    </footer>
</div>

<div class="modal fade" id="notificationsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content notifications-modal-content">
            <div class="notifications-modal-header">
                <div class="notif-header-left">
                    <div class="notif-header-icon"><i class="bi bi-bell-fill"></i></div>
                    <div>
                        <h4>Notifications</h4>
                        <p id="notifUnreadCount"><?php echo $notificationCount; ?> unread update<?php echo $notificationCount === 1 ? '' : 's'; ?></p>
                    </div>
                </div>
                <button type="button" class="notif-mark-read" id="markAllReadBtn">
                    <i class="bi bi-check2-all"></i> Mark all read
                </button>
            </div>

            <div class="notifications-modal-body">
                <?php if (!empty($displayNotifications)): ?>
                    <?php foreach ($displayNotifications as $item): ?>
                        <?php $cardReadClass = empty($item['is_unread']) ? ' notif-read' : ''; ?>
                        <div class="notification-card notif-<?php echo htmlspecialchars($item['type']); ?><?php echo $cardReadClass; ?>">
                            <div class="notification-card-icon <?php echo htmlspecialchars($item['type']); ?>">
                                <i class="<?php echo htmlspecialchars($item['icon']); ?>"></i>
                            </div>

                            <div class="notification-card-content">
                                <div class="notification-card-top">
                                    <h5><?php echo htmlspecialchars($item['title']); ?></h5>
                                    <span class="notif-time"><?php echo htmlspecialchars($item['time']); ?></span>
                                </div>
                                <p><?php echo htmlspecialchars($item['message']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="notif-empty-state">
                        <div class="notif-empty-icon"><i class="bi bi-bell-slash"></i></div>
                        <h5>You're all caught up</h5>
                        <p>New alerts about your sales activity will show up here.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="notifications-modal-footer">
                <button type="button" class="btn btn-close-notif" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="customRangeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content custom-range-content">
            <form method="GET" action="">
                <div class="modal-header border-0">
                    <h5 class="modal-title">Choose Custom Range</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <input type="hidden" name="filter" value="custom">

                    <div class="mb-3">
                        <label class="form-label">Start Date</label>
                        <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($customStart); ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">End Date</label>
                        <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($customEnd); ?>" required>
                    </div>
                </div>

                <div class="modal-footer border-0">
                    <button type="submit" class="btn btn-primary">Apply Range</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php include 'chatbot.php'; ?>
<script>
window.salesAnalyticsData = {
    dailyLabels: <?php echo json_encode($dailyLabels); ?>,
    dailySalesData: <?php echo json_encode($dailySalesData); ?>,
    monthlyLabels: <?php echo json_encode($monthlyLabels); ?>,
    monthlyRevenueData: <?php echo json_encode($monthlyRevenueData); ?>,
    categoryLabels: <?php echo json_encode($categoryLabels); ?>,
    categorySalesData: <?php echo json_encode($categorySalesData); ?>,
    rangeLabel: <?php echo json_encode($rangeLabel); ?>,
    todaySales: <?php echo json_encode($todayProfit); ?>,
    weekSales: <?php echo json_encode($weekProfit); ?>,
    monthSales: <?php echo json_encode($monthProfit); ?>,
    netProfit: <?php echo json_encode($netProfit); ?>,
    grossRevenue: <?php echo json_encode($grossRevenue); ?>,
    costOfGoodsSold: <?php echo json_encode($costOfGoodsSold); ?>,
    totalTransactions: <?php echo json_encode($totalTransactions); ?>,
    salesGrowth: <?php echo json_encode(round($salesGrowth, 2)); ?>,

    /* legacy compatibility if your JS still references old names */
    netSales: <?php echo json_encode($netProfit); ?>,
    totalProfit: <?php echo json_encode($netProfit); ?>
};

document.addEventListener('DOMContentLoaded', function () {
    // Key Components live search
    var searchInput = document.getElementById('appendicesSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var term = this.value.trim().toLowerCase();
            var cols = document.querySelectorAll('#appendicesGrid .appendix-col');
            var visibleCount = 0;

            cols.forEach(function (col) {
                var text = col.textContent.toLowerCase();
                var matches = text.indexOf(term) !== -1;
                col.classList.toggle('d-none', !matches);
                if (matches) visibleCount++;
            });

            var emptyState = document.getElementById('appendicesEmptyState');
            if (emptyState) {
                emptyState.classList.toggle('d-none', visibleCount !== 0);
            }
        });
    }

    // Mark all notifications as read and persist the timestamp in the database.
    var markAllBtn = document.getElementById('markAllReadBtn');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function () {
            markAllBtn.disabled = true;

            fetch('/NexGen/CODE/PHP/sales_analytics.php?action=mark_notifications_seen', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Failed to update notification read status.');
                    }
                    return response.json();
                })
                .then(function (data) {
                    if (!data.success) {
                        throw new Error(data.message || 'Failed to mark notifications as read.');
                    }

                    document.querySelectorAll('#notificationsModal .notification-card').forEach(function (card) {
                        card.classList.add('notif-read');
                    });

                    document.querySelectorAll('.topbar-icon-btn .icon-badge').forEach(function (badge) {
                        badge.remove();
                    });

                    var unreadCount = document.getElementById('notifUnreadCount');
                    if (unreadCount) {
                        unreadCount.textContent = '0 unread updates';
                    }
                })
                .catch(function (error) {
                    console.error('Failed to mark Sales Analytics notifications as read:', error);
                })
                .finally(function () {
                    markAllBtn.disabled = false;
                });
        });
    }
});
</script>



<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="/NexGen/CODE/JS/header.js"></script>
<script src="/NexGen/CODE/JS/sales_analytics.js?v=responsive20260908"></script>
</body>
</html>
<?php
session_start();
require_once("config.php");
require_once("tenant_helper.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: /NexGen/CODE/PHP/index.php");
    exit();
}

if ((int)($_SESSION['can_inventory'] ?? 0) !== 1) {
    $_SESSION['error'] = 'You do not have access to Inventory Management.';
    header("Location: /NexGen/CODE/PHP/dashboard.php");
    exit();
}

$businessId = nxRequireBusinessId($conn);
$businessTypeStmt = $conn->prepare("SELECT business_type FROM businesses WHERE id = ? LIMIT 1");
$businessTypeStmt->bind_param("i", $businessId);
$businessTypeStmt->execute();
$businessType = $businessTypeStmt->get_result()->fetch_assoc()['business_type'] ?? null;
$businessTypeStmt->close();
$isBatchTracked = nxIsBatchTrackedType($businessType);
$blocksExpiredBatches = nxBlocksExpiredBatches($businessType);

// Near-expiry alert window in days. Configurable in one place.
if (!defined('NX_EXPIRY_ALERT_DAYS')) {
    define('NX_EXPIRY_ALERT_DAYS', 30);
}

// SELF-HEAL: products.stock_quantity is kept in sync by the product_batches
// triggers, but "exclude expired batches" is a time-based condition - a
// batch that quietly crosses its expiry date doesn't fire any trigger by
// itself. Re-sync it here on every page load so the displayed/counted stock
// always reflects only non-expired, non-dropped batches, not just whatever
// was true the last time a batch row was written.
if ($isBatchTracked) {
    $conn->query("
        UPDATE products p
        SET stock_quantity = (
            SELECT COALESCE(SUM(pb.quantity), 0) FROM product_batches pb
            WHERE pb.product_id = p.id AND pb.status = 'active'
              AND (pb.expiry_date IS NULL OR pb.expiry_date >= CURDATE())
        )
        WHERE p.business_id = {$businessId}
          AND EXISTS (SELECT 1 FROM product_batches WHERE product_id = p.id)
    ");
}

$csrfAddProduct = generateCsrfToken('inventory_add_product');
$csrfEditProduct = generateCsrfToken('inventory_edit_product');
$csrfStock = generateCsrfToken('inventory_stock');
$csrfCategory = generateCsrfToken('inventory_category');
$csrfDeleteRestore = generateCsrfToken('inventory_delete_restore');
$csrfBatchDrop = generateCsrfToken('inventory_batch_drop');
$displayName = $_SESSION['username'] ?? 'Client';
$fullName = $_SESSION['full_name'] ?? 'Client';
$userId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'employee';
$isOwner = $role === 'owner';
$isEmployee = $role === 'employee';

$categories = [];
$categoryStmt = $conn->prepare("SELECT id, category_name FROM categories WHERE business_id = ? ORDER BY category_name ASC");
$categoryStmt->bind_param("i", $businessId);
$categoryStmt->execute();
$categoryResult = $categoryStmt->get_result();
while ($row = $categoryResult->fetch_assoc()) { $categories[] = $row; }
$categoryStmt->close();

$search = trim($_GET['search'] ?? '');
$categoryFilter = intval($_GET['category'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

// For batch-tracked products, p.expiry_date is always NULL (expiry lives on
// each batch instead) - "effective" expiry falls back to the soonest batch
// expiry so expired/expiring filters and the row pill still work correctly.
$effectiveExpirySql = "COALESCE(p.expiry_date, (SELECT MIN(pb.expiry_date) FROM product_batches pb WHERE pb.product_id = p.id AND pb.business_id = p.business_id AND pb.status = 'active'))";
$sql = "
    SELECT p.*, c.category_name, {$effectiveExpirySql} AS effective_expiry_date
    FROM products p
    INNER JOIN categories c ON p.category_id = c.id AND c.business_id = p.business_id
    WHERE p.business_id = ?
";
$params = [$businessId];
$types = "i";

if ($search !== '') {
    $sql .= " AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.brand LIKE ?)";
    $searchParam = "%{$search}%";
    array_push($params, $searchParam, $searchParam, $searchParam);
    $types .= "sss";
}
if ($categoryFilter > 0) {
    $sql .= " AND p.category_id = ?";
    $params[] = $categoryFilter;
    $types .= "i";
}
if ($statusFilter === 'active') {
    $sql .= " AND p.is_active = 1";
} elseif ($statusFilter === 'inactive' || $statusFilter === 'archived') {
    $sql .= " AND p.is_active = 0";
} elseif ($statusFilter === 'low') {
    $sql .= " AND p.is_active = 1 AND p.stock_quantity <= p.reorder_level AND p.stock_quantity > 0";
} elseif ($statusFilter === 'out') {
    $sql .= " AND p.is_active = 1 AND p.stock_quantity <= 0";
} elseif ($statusFilter === 'expired') {
    $sql .= " AND {$effectiveExpirySql} IS NOT NULL AND {$effectiveExpirySql} < CURDATE()";
} elseif ($statusFilter === 'expiring') {
    $sql .= " AND p.is_active = 1 AND {$effectiveExpirySql} IS NOT NULL AND {$effectiveExpirySql} >= CURDATE() AND DATEDIFF({$effectiveExpirySql}, CURDATE()) <= " . NX_EXPIRY_ALERT_DAYS;
}
$sql .= " ORDER BY p.created_at DESC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$allProducts = [];
while ($row = $result->fetch_assoc()) { $allProducts[] = $row; }
$stmt->close();

// Expired-but-not-dropped quantity per product, kept separate from the
// sellable stock_quantity so the row can show both numbers without
// confusing one for the other (per-product, not per-batch, since the row
// only has room for one expiry badge).
$expiredQtyByProduct = [];
if ($isBatchTracked) {
    $expStmt = $conn->prepare("
        SELECT product_id, SUM(quantity) AS expired_qty
        FROM product_batches
        WHERE business_id = ? AND status = 'active' AND expiry_date < CURDATE()
        GROUP BY product_id
    ");
    $expStmt->bind_param("i", $businessId);
    $expStmt->execute();
    $expResult = $expStmt->get_result();
    while ($expRow = $expResult->fetch_assoc()) {
        $expiredQtyByProduct[(int)$expRow['product_id']] = (float)$expRow['expired_qty'];
    }
    $expStmt->close();
}

$totalFetchedProducts = count($allProducts);
$productDisplayLimit = 10;
$showAllProducts = isset($_GET['show_all']) && $_GET['show_all'] === '1';
$productsToDisplay = $showAllProducts ? $allProducts : array_slice($allProducts, 0, $productDisplayLimit);
$viewAllParams = $_GET; $viewAllParams['show_all'] = 1;
$viewAllUrl = '/NexGen/CODE/PHP/inventory_management.php?' . http_build_query($viewAllParams);
$showTop10Params = $_GET; unset($showTop10Params['show_all']);
$showTop10Url = '/NexGen/CODE/PHP/inventory_management.php' . (!empty($showTop10Params) ? '?' . http_build_query($showTop10Params) : '');

function nxFetchOne(mysqli $conn, string $sql, string $types, array $params): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}
function nxFetchAll(mysqli $conn, string $sql, string $types, array $params): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result(); $rows=[];
    while ($r=$res->fetch_assoc()) $rows[]=$r;
    $stmt->close(); return $rows;
}

$countRow = nxFetchOne($conn, "SELECT COUNT(*) total_products,
    SUM(is_active=1) active_products, SUM(is_active=0) inactive_products,
    SUM(is_active=1 AND stock_quantity<=reorder_level AND stock_quantity>0) low_stock_count,
    SUM(is_active=1 AND stock_quantity<=0) out_of_stock_count
    FROM products WHERE business_id=?", "i", [$businessId]);
$totalProducts=(int)($countRow['total_products']??0);
$activeProductsCount=(int)($countRow['active_products']??0);
$inactiveProductsCount=(int)($countRow['inactive_products']??0);
$lowStockCount=(int)($countRow['low_stock_count']??0);
$outOfStockCount=(int)($countRow['out_of_stock_count']??0);

$expiryCountRow = nxFetchOne($conn, "SELECT
    SUM(x.effective_expiry_date IS NOT NULL AND x.effective_expiry_date>=CURDATE() AND DATEDIFF(x.effective_expiry_date,CURDATE())<=" . NX_EXPIRY_ALERT_DAYS . ") expiring_soon_count,
    SUM(x.effective_expiry_date IS NOT NULL AND x.effective_expiry_date<CURDATE()) expired_count
    FROM (SELECT p.is_active, {$effectiveExpirySql} AS effective_expiry_date FROM products p WHERE p.business_id=?) x
    WHERE x.is_active=1", "i", [$businessId]);
$expiringSoonCount=(int)($expiryCountRow['expiring_soon_count']??0);
$expiredCount=(int)($expiryCountRow['expired_count']??0);

$lowStockItems = nxFetchAll($conn, "SELECT product_name,product_code,stock_quantity,reorder_level,on_order_level,product_image
    FROM products WHERE business_id=? AND is_active=1 AND stock_quantity<=reorder_level AND stock_quantity>0
    ORDER BY stock_quantity ASC,product_name ASC LIMIT 10", "i", [$businessId]);
$lowStockFeature=$lowStockItems[0]??null;

$expiringSoonItems = nxFetchAll($conn, "SELECT * FROM (
        SELECT p.product_name,p.product_code,p.stock_quantity,p.product_image,
            {$effectiveExpirySql} AS expiry_date
        FROM products p WHERE p.business_id=? AND p.is_active=1
    ) x
    WHERE x.expiry_date IS NOT NULL AND x.expiry_date>=CURDATE() AND DATEDIFF(x.expiry_date,CURDATE())<=" . NX_EXPIRY_ALERT_DAYS . "
    ORDER BY x.expiry_date ASC, x.product_name ASC LIMIT 10", "i", [$businessId]);
foreach ($expiringSoonItems as &$esItem) { $esItem['days_left'] = (int)floor((strtotime($esItem['expiry_date']) - strtotime(date('Y-m-d'))) / 86400); }
unset($esItem);
$expiringSoonFeature=$expiringSoonItems[0]??null;

$outOfStockItems = nxFetchAll($conn, "SELECT p.product_name,p.product_code,p.stock_quantity,p.on_order_level,p.product_image,
    MAX(sm.created_at) latest_stock_out_at FROM products p
    LEFT JOIN stock_movements sm ON sm.product_id=p.id AND sm.business_id=p.business_id AND sm.movement_type='stock_out'
    WHERE p.business_id=? AND p.is_active=1 AND p.stock_quantity<=0
    GROUP BY p.id ORDER BY latest_stock_out_at DESC,p.created_at DESC,p.id DESC LIMIT 10", "i", [$businessId]);
$outOfStockFeature=$outOfStockItems[0]??null;

$latestSoldItem = nxFetchOne($conn, "SELECT sm.quantity,sm.created_at,sm.remarks,p.product_name,p.product_code,p.product_image
    FROM stock_movements sm INNER JOIN products p ON sm.product_id=p.id AND sm.business_id=p.business_id
    WHERE sm.business_id=? AND sm.movement_type='stock_out' ORDER BY sm.created_at DESC LIMIT 1", "i", [$businessId]);
if (!$latestSoldItem) $latestSoldItem=null;

$recentMovements = nxFetchAll($conn, "SELECT sm.*,p.product_name,p.product_code,p.product_image
    FROM stock_movements sm INNER JOIN products p ON sm.product_id=p.id AND sm.business_id=p.business_id
    WHERE sm.business_id=? AND sm.created_at>=NOW()-INTERVAL 7 DAY ORDER BY sm.created_at DESC LIMIT 5", "i", [$businessId]);

// Purchase order history is owner-only: only fetch it for owners so it
// never ends up in the HTML response for employees (the trigger button
// being hidden for employees was not enough - the data itself must not
// be sent to the browser).
$orderHistory = $isOwner ? nxFetchAll($conn, "SELECT sm.id, sm.product_id, sm.quantity, sm.remarks, sm.created_at,
        p.product_name, p.product_code
    FROM stock_movements sm
    INNER JOIN products p ON p.id=sm.product_id AND p.business_id=sm.business_id
    WHERE sm.business_id=? AND sm.movement_type='order_placed'
    ORDER BY sm.created_at DESC, sm.id DESC LIMIT 20", "i", [$businessId]) : [];

$popupMessage = $_SESSION['inventory_success'] ?? $_SESSION['inventory_error'] ?? "";
$popupType = isset($_SESSION['inventory_success']) ? "success" : (isset($_SESSION['inventory_error']) ? "error" : "");
unset($_SESSION['inventory_success'], $_SESSION['inventory_error']);

function getInventoryState(array $product): array {
    $stock = (float)$product['stock_quantity'];
    $reorder = (float)$product['reorder_level'];
    $onOrder = (float)($product['on_order_level'] ?? 0);
    $isActive = (int)$product['is_active'] === 1;

    if (!$isActive) {
        return ['label' => 'Archived', 'class' => 'inactive'];
    }

    if ($stock <= 0 && $onOrder > 0) {
        return ['label' => 'Out / Incoming', 'class' => 'warning'];
    }

    if ($stock <= 0) {
        return ['label' => 'Out of Stock', 'class' => 'danger'];
    }

    if ($stock <= $reorder && $onOrder > 0) {
        return ['label' => 'Low / Incoming', 'class' => 'warning'];
    }

    if ($stock <= $reorder) {
        return ['label' => 'Low Stock', 'class' => 'warning'];
    }

    return ['label' => 'Good Stock', 'class' => 'active'];
}

function expiryUrgency(?string $expiryDate): ?array {
    if (empty($expiryDate)) {
        return null;
    }

    $expiry = strtotime($expiryDate);
    if ($expiry === false) {
        return null;
    }

    $daysLeft = (int)floor(($expiry - strtotime(date('Y-m-d'))) / 86400);

    if ($daysLeft < 0) {
        return ['label' => 'Expired', 'class' => 'expiry-expired', 'days' => $daysLeft];
    }
    if ($daysLeft === 0) {
        return ['label' => 'Expires today', 'class' => 'expiry-critical', 'days' => $daysLeft];
    }
    if ($daysLeft === 1) {
        return ['label' => 'Expires tomorrow', 'class' => 'expiry-critical', 'days' => $daysLeft];
    }
    if ($daysLeft <= 7) {
        return ['label' => "Expires in {$daysLeft}d", 'class' => 'expiry-warning', 'days' => $daysLeft];
    }
    if ($daysLeft <= 15) {
        return ['label' => "Expires in {$daysLeft}d", 'class' => 'expiry-notice', 'days' => $daysLeft];
    }
    if ($daysLeft <= NX_EXPIRY_ALERT_DAYS) {
        return ['label' => "Expires in {$daysLeft}d", 'class' => 'expiry-upcoming', 'days' => $daysLeft];
    }

    return null;
}

// nxFormatLocalDateTime() now lives in config.php (shared across pages
// that display DB timestamps), since sale_view.php / sales_recording.php
// have the identical UTC-stored / Manila-displayed timestamp bug.

function inventoryImagePath(?string $path): string {
    return nxProductImageUrl($path);
}

function isActiveTab(string $current, string $tab): string {
    return $current === $tab ? 'active' : '';
}

// Presentational only: picks a Bootstrap icon for a category chip in the
// filter panel. Falls back to a generic tag icon for anything unmapped.
// Does not affect filtering, matching, or data in any way.
function nxCategoryIcon(string $categoryName): string {
    $key = strtolower(trim($categoryName));
    $map = [
        'beverages'        => 'bi-cup-straw',
        'canned goods'      => 'bi-basket2',
        'food spread'       => 'bi-jar',
        'frozen goods'      => 'bi-snow',
        'instant noodle'    => 'bi-egg-fried',
        'personal hygiene'  => 'bi-droplet',
        'powdered drink'    => 'bi-cup-hot',
    ];
    return $map[$key] ?? 'bi-tag';
}

$currentStatusForTab = $statusFilter;
if ($currentStatusForTab === '') {
    $currentStatusForTab = 'all';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <?php include __DIR__ . '/theme_init.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - NexGen</title>
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/inventory_management.css?v=<?php echo time(); ?>">
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/header.css?v=2">
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/module_footer.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
        .optimized-product-list-header {
            align-items: center;
            gap: 14px;
        }

        .product-list-header-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-wrap: wrap;
            margin-left: auto;
        }

        .po-history-trigger {
            border: 1px solid rgba(255,255,255,.16);
            background: rgba(255,255,255,.08);
            color: #14c3d3;
            min-height: 40px;
            padding: 9px 13px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 700;
            cursor: pointer;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            transition: .2s ease;
        }

        .po-history-trigger:hover {
            background: rgba(255,255,255,.14);
            transform: translateY(-1px);
        }

        .po-history-trigger-count {
            min-width: 24px;
            height: 24px;
            padding: 0 7px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255,255,255,.14);
            font-size: 11px;
        }

        .po-history-overlay {
            position: fixed;
            inset: 0;
            z-index: 99990;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(3, 13, 45, .34);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: .22s ease;
        }

        .po-history-overlay.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }

        .po-history-mini-tab {
            width: min(960px, 96vw);
            max-height: 82vh;
            overflow: hidden;
            border: 1px solid rgba(255,255,255,.18);
            border-radius: 22px;
            background: rgba(10, 35, 100, .82);
            box-shadow: 0 28px 70px rgba(0,0,0,.34);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            transform: translateY(14px) scale(.98);
            transition: .22s ease;
        }

        .po-history-overlay.show .po-history-mini-tab {
            transform: translateY(0) scale(1);
        }

        .po-history-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18px;
            padding: 22px 24px 16px;
            border-bottom: 1px solid rgba(255,255,255,.10);
        }

        .po-history-kicker {
            display: block;
            margin-bottom: 5px;
            color: rgba(255,255,255,.58);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .7px;
            text-transform: uppercase;
        }

        .po-history-modal-header h2 {
            margin: 0;
            color: #fff;
            font-size: 22px;
        }

        .po-history-modal-header p {
            margin: 6px 0 0;
            color: rgba(255,255,255,.72);
            font-size: 13px;
        }

        .po-history-close {
            width: 38px;
            height: 38px;
            flex: 0 0 38px;
            border: 1px solid rgba(255,255,255,.14);
            border-radius: 50%;
            background: rgba(255,255,255,.08);
            color: #fff;
            font-size: 24px;
            cursor: pointer;
        }

        .po-history-modal-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 12px 24px;
            color: rgba(255,255,255,.72);
            font-size: 12px;
        }

        .po-history-total {
            padding: 5px 9px;
            border-radius: 999px;
            background: rgba(255,255,255,.08);
        }

        .po-history-modal-table-wrap {
            overflow: auto;
            max-height: calc(82vh - 150px);
            padding: 0 18px 18px;
        }

        .po-history-modal-table {
            width: 100%;
            min-width: 720px;
            border-collapse: separate;
            border-spacing: 0;
            overflow: hidden;
            border-radius: 14px;
        }

        .po-history-modal-table th,
        .po-history-modal-table td {
            padding: 13px 14px;
            border-bottom: 1px solid rgba(255,255,255,.08);
            text-align: left;
            vertical-align: middle;
        }

        .po-history-modal-table th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: rgba(5, 21, 66, .96);
            color: #f4d56b;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .35px;
        }

        .po-history-modal-table td {
            color: #edf4ff;
            background: rgba(255,255,255,.025);
            font-size: 13px;
        }

        .po-history-modal-table tbody tr:hover td {
            background: rgba(255,255,255,.06);
        }

        .po-history-modal-table td strong,
        .po-history-modal-table td small {
            display: block;
        }

        .po-history-modal-table td small {
            margin-top: 3px;
            color: rgba(255,255,255,.55);
        }

        .po-qty {
            text-align: center !important;
            font-weight: 800;
            white-space: nowrap;
        }

        .po-remarks {
            min-width: 260px;
            line-height: 1.5;
        }

        .po-date {
            white-space: nowrap;
        }

        .po-empty {
            opacity: .6;
            font-style: italic;
        }

        .po-history-empty-state {
            padding: 38px 16px !important;
            text-align: center !important;
        }

        .po-history-empty-state i,
        .po-history-empty-state strong,
        .po-history-empty-state span {
            display: block;
        }

        .po-history-empty-state i {
            font-size: 28px;
            margin-bottom: 8px;
            opacity: .7;
        }

        .po-history-empty-state span {
            margin-top: 5px;
            opacity: .6;
        }

        @media (max-width: 700px) {
            .optimized-product-list-header {
                align-items: stretch;
                flex-direction: column;
            }

            .product-list-header-actions {
                width: 100%;
                justify-content: stretch;
            }

            .po-history-trigger,
            .product-list-header-actions .view-all-link {
                flex: 1 1 auto;
                justify-content: center;
            }

            .po-history-overlay {
                padding: 12px;
            }

            .po-history-mini-tab {
                width: 100%;
                max-height: 88vh;
            }

            .po-history-modal-header {
                padding: 18px;
            }

            .po-history-modal-meta {
                padding: 10px 18px;
            }

            .po-history-modal-table-wrap {
                max-height: calc(88vh - 145px);
                padding: 0 12px 12px;
            }
        }
    </style>

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

<div class="inventory-page">

    <?php include 'header.php'; ?>

    <main class="inventory-wrapper">

        <section class="inventory-header">
            <div class="inventory-header-card">
                <div class="inventory-header-row">
                    <div class="inventory-header-left">
                        <h1>Inventory Management</h1>
                    </div>

                    <div class="inventory-header-tools">
                        <div class="inventory-search-box">
                            <i class="bi bi-search"></i>
                            <input
                                type="text"
                                name="search"
                                id="inventorySearchInput"
                                form="inventoryFilterForm"
                                placeholder="Search products..."
                                value="<?php echo htmlspecialchars($search); ?>"
                                autocomplete="off"
                            >
                        </div>

                        <div class="inventory-filter-wrap">
                            <button type="button" class="inventory-filter-btn" id="toggleFilterTool" aria-haspopup="true" aria-expanded="false" aria-label="Filter products">
                                <i class="bi bi-funnel-fill"></i>
                                <span>Filter</span>
                            </button>

                            <div class="floating-filter-box" id="floatingFilterBox">
                                <input type="hidden" name="category" id="inventoryCategoryFilter" form="inventoryFilterForm" value="<?php echo (int)$categoryFilter; ?>">
                                <input type="hidden" name="status" id="inventoryStatusFilter" form="inventoryFilterForm" value="<?php echo htmlspecialchars($statusFilter); ?>">

                                <div class="filter-panel-head">
                                    <i class="bi bi-funnel"></i>
                                    <span>Filter Products</span>
                                </div>

                                <div class="filter-panel-scroll">
                                    <div class="filter-group">
                                        <span class="filter-group-label"><i class="bi bi-grid"></i>Category</span>
                                        <div class="filter-chip-list">
                                            <button type="button" class="filter-chip <?php echo $categoryFilter === 0 ? 'active' : ''; ?>" data-filter-kind="category" data-filter-value="0"><i class="bi bi-circle"></i>All Categories</button>
                                            <?php foreach ($categories as $category): ?>
                                                <button type="button" class="filter-chip <?php echo ($categoryFilter == $category['id']) ? 'active' : ''; ?>" data-filter-kind="category" data-filter-value="<?php echo (int)$category['id']; ?>">
                                                    <i class="bi <?php echo nxCategoryIcon($category['category_name']); ?>"></i><?php echo htmlspecialchars($category['category_name']); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <div class="filter-group">
                                        <span class="filter-group-label"><i class="bi bi-activity"></i>Status</span>
                                        <div class="filter-chip-list">
                                            <button type="button" class="filter-chip <?php echo $statusFilter === '' ? 'active' : ''; ?>" data-filter-kind="status" data-filter-value=""><i class="bi bi-circle"></i>All Status</button>
                                            <button type="button" class="filter-chip <?php echo $statusFilter === 'active' ? 'active' : ''; ?>" data-filter-kind="status" data-filter-value="active"><i class="bi bi-check-circle"></i>Active</button>
                                            <button type="button" class="filter-chip <?php echo ($statusFilter === 'archived' || $statusFilter === 'inactive') ? 'active' : ''; ?>" data-filter-kind="status" data-filter-value="archived"><i class="bi bi-archive"></i>Archived</button>
                                            <button type="button" class="filter-chip <?php echo $statusFilter === 'low' ? 'active' : ''; ?>" data-filter-kind="status" data-filter-value="low"><i class="bi bi-exclamation-triangle"></i>Low Stock</button>
                                            <button type="button" class="filter-chip <?php echo $statusFilter === 'out' ? 'active' : ''; ?>" data-filter-kind="status" data-filter-value="out"><i class="bi bi-x-circle"></i>Out of Stock</button>
                                            <button type="button" class="filter-chip <?php echo $statusFilter === 'expiring' ? 'active' : ''; ?>" data-filter-kind="status" data-filter-value="expiring"><i class="bi bi-clock-history"></i>Expiring Soon</button>
                                            <button type="button" class="filter-chip <?php echo $statusFilter === 'expired' ? 'active' : ''; ?>" data-filter-kind="status" data-filter-value="expired"><i class="bi bi-calendar-x"></i>Expired</button>
                                        </div>
                                    </div>
                                </div>

                                <div class="filter-panel-foot">
                                    <button type="button" class="clear-filters-link" id="clearFiltersBtn">
                                        <i class="bi bi-x-lg"></i>Clear filters
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="inventory-meta">
                    <i class="bi bi-archive"></i>
                    <span><?php echo $totalProducts; ?> registered products</span>
                    <span class="meta-divider">|</span>
                    <span class="inventory-subtitle">Store, Manage, and Adjust your Inventory Items Easily</span>
                </div>
            </div>

            <div class="header-actions">
                <?php if ($isOwner): ?>
                    <a href="/NexGen/CODE/PHP/inventory_export.php" class="ghost-btn">
                        <i class="bi bi-box-arrow-up-right"></i>
                        <span>Export Report</span>
                    </a>
                <?php endif; ?>

                <button class="ghost-btn" id="openCategoryModal" type="button">
                    <i class="bi bi-folder2"></i>
                    <span>Manage Categories</span>
                </button>
                <button class="primary-btn" id="openProductModal" type="button">
                    <i class="bi bi-plus-lg"></i>
                    <span>Add Product</span>
                </button>
            </div>
        </section>

        <!-- Hidden form: not visually rendered. The search input and category/status
             chips above use the form="inventoryFilterForm" attribute so their values
             are still collected by submitFiltersInstantly() no matter where they sit
             in the page (HTML5 form association doesn't require DOM nesting). -->
        <form method="GET" id="inventoryFilterForm" action="/NexGen/CODE/PHP/inventory_management.php" class="hidden-filter-form"></form>

        <div id="inventoryDynamicArea">
            <section class="inventory-tabs">
                <a href="/NexGen/CODE/PHP/inventory_management.php" class="inventory-tab <?php echo isActiveTab($currentStatusForTab, 'all'); ?>" data-filter-link>
                    All <span><?php echo $totalProducts; ?></span>
                </a>
                <a href="/NexGen/CODE/PHP/inventory_management.php?status=active" class="inventory-tab <?php echo isActiveTab($currentStatusForTab, 'active'); ?>" data-filter-link>
                    Active <span><?php echo $activeProductsCount; ?></span>
                </a>
                <a href="/NexGen/CODE/PHP/inventory_management.php?status=low" class="inventory-tab <?php echo isActiveTab($currentStatusForTab, 'low'); ?>" data-filter-link>
                    Low Stock <span><?php echo $lowStockCount; ?></span>
                </a>
                <a href="/NexGen/CODE/PHP/inventory_management.php?status=out" class="inventory-tab <?php echo isActiveTab($currentStatusForTab, 'out'); ?>" data-filter-link>
                    Out of Stock <span><?php echo $outOfStockCount; ?></span>
                </a>
                <a href="/NexGen/CODE/PHP/inventory_management.php?status=archived" class="inventory-tab <?php echo isActiveTab($currentStatusForTab, 'archived'); ?>" data-filter-link>
                    Archived <span><?php echo $inactiveProductsCount; ?></span>
                </a>
                <a href="/NexGen/CODE/PHP/inventory_management.php?status=expiring" class="inventory-tab <?php echo isActiveTab($currentStatusForTab, 'expiring'); ?>" data-filter-link>
                    Expiring Soon <span><?php echo $expiringSoonCount; ?></span>
                </a>
                <?php if ($expiredCount > 0): ?>
                    <a href="/NexGen/CODE/PHP/inventory_management.php?status=expired" class="inventory-tab tab-danger <?php echo isActiveTab($currentStatusForTab, 'expired'); ?>" data-filter-link>
                        Expired <span><?php echo $expiredCount; ?></span>
                    </a>
                <?php endif; ?>
            </section>

            <section class="inventory-summary-grid">

                <div class="summary-card gradient-card low-stock-card">
                    <div class="summary-card-top">
                        <div class="summary-heading-wrap">
                            <span class="summary-icon"><i class="bi bi-exclamation-triangle-fill"></i></span>
                            <h3>Low Stock Alert</h3>
                        </div>
                        <span class="summary-badge"><?php echo count($lowStockItems); ?></span>
                    </div>

                    <?php if ($lowStockFeature): ?>
                        <div class="summary-card-body with-image">
                            <div class="summary-text">
                                <h4><?php echo htmlspecialchars($lowStockFeature['product_name']); ?></h4>
                                <p>Code: <?php echo htmlspecialchars($lowStockFeature['product_code']); ?></p>
                                <p><?php echo formatQty($lowStockFeature['stock_quantity']); ?> in stock</p>
                                <p>Reorder at <?php echo formatQty($lowStockFeature['reorder_level']); ?></p>

                                <div class="mini-pill">
                                    <?php echo formatQty($lowStockFeature['on_order_level'] ?? 0); ?> incoming
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="summary-empty">No low stock alerts right now.</div>
                    <?php endif; ?>
                </div>

                <div class="summary-card gradient-card out-stock-card">
                    <div class="summary-card-top">
                        <div class="summary-heading-wrap">
                            <span class="summary-icon"><i class="bi bi-box-seam-fill"></i></span>
                            <h3>Out of Stock</h3>
                        </div>
                        <span class="summary-badge blue"><?php echo count($outOfStockItems); ?></span>
                    </div>

                    <?php if ($outOfStockFeature): ?>
                        <div class="summary-card-body with-image">
                            <div class="summary-text">
                                <h4><?php echo htmlspecialchars($outOfStockFeature['product_name']); ?></h4>
                                <p>Code: <?php echo htmlspecialchars($outOfStockFeature['product_code']); ?></p>
                                <p><?php echo formatQty($outOfStockFeature['stock_quantity']); ?> in stock</p>

                                <div class="mini-pill">
                                    <?php echo formatQty($outOfStockFeature['on_order_level'] ?? 0); ?> incoming
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="summary-empty">No out of stock items right now.</div>
                    <?php endif; ?>
                </div>

                <div class="summary-card gradient-card expiring-card">
                    <div class="summary-card-top">
                        <div class="summary-heading-wrap">
                            <span class="summary-icon"><i class="bi bi-hourglass-split"></i></span>
                            <h3>Expiring Soon</h3>
                        </div>
                        <span class="summary-badge <?php echo ($expiringSoonFeature && (int)$expiringSoonFeature['days_left'] <= 7) ? 'red' : 'blue'; ?>"><?php echo $expiringSoonCount; ?></span>
                    </div>

                    <?php if ($expiringSoonFeature): ?>
                        <?php $featureUrgency = expiryUrgency($expiringSoonFeature['expiry_date']); ?>
                        <div class="summary-card-body with-image">
                            <div class="summary-text">
                                <h4><?php echo htmlspecialchars($expiringSoonFeature['product_name']); ?></h4>
                                <p>Code: <?php echo htmlspecialchars($expiringSoonFeature['product_code']); ?></p>
                                <p>Expires: <?php echo date("M d, Y", strtotime($expiringSoonFeature['expiry_date'])); ?></p>

                                <div class="mini-pill <?php echo $featureUrgency ? $featureUrgency['class'] : ''; ?>">
                                    <?php echo $featureUrgency ? htmlspecialchars($featureUrgency['label']) : ''; ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="summary-empty">No products expiring in the next <?php echo NX_EXPIRY_ALERT_DAYS; ?> days.</div>
                    <?php endif; ?>
                </div>

                <div class="summary-card history-card">
                    <div class="summary-card-top">
                        <div class="summary-heading-wrap">
                            <span class="summary-icon"><i class="bi bi-clock-history"></i></span>
                            <h3>Stock History</h3>
                        </div>
                        <div class="history-chip-wrap">
                            <span class="history-chip">Past 7 days</span>
                        </div>
                    </div>

                    <?php if ($latestSoldItem): ?>
                        <div class="history-main">
                            <div class="history-text">
                                <p class="history-caption">Last product sold :</p>
                                <h4><?php echo htmlspecialchars($latestSoldItem['product_name']); ?></h4>
                                <div class="history-row">
                                    <span><?php echo htmlspecialchars(nxFormatLocalDateTime($latestSoldItem['created_at'], 'M d') ?? ''); ?></span>
                                    <strong>-<?php echo formatQty($latestSoldItem['quantity']); ?></strong>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="summary-empty">No recent stock-out history found.</div>
                    <?php endif; ?>

                    <div class="history-line">
                        <span></span><span></span><span></span><span></span><span></span>
                    </div>
                    <div class="history-legend">
                        <span class="legend-dot recent"></span> Gold = today's activity
                        <span class="legend-dot earlier"></span> Blue = earlier this week
                    </div>
                </div>

            </section>

            <section class="inventory-table-shell">
                <div class="product-list-header optimized-product-list-header">
                    <h3>Products</h3>
                    <div class="product-list-header-actions">
                        <?php if ($isOwner): ?>
                            <button type="button" class="po-history-trigger" id="openPoHistoryModal">
                                <i class="bi bi-clock-history"></i>
                                <span>Purchase Order History</span>
                                <span class="po-history-trigger-count"><?php echo count($orderHistory); ?></span>
                            </button>
                        <?php endif; ?>
                    <?php if ($showAllProducts && $totalFetchedProducts > $productDisplayLimit): ?>
                        <a href="<?php echo htmlspecialchars($showTop10Url); ?>" class="view-all-link" data-filter-link>
                            Show Top <?php echo $productDisplayLimit; ?> Products
                        </a>
                    <?php elseif ($totalFetchedProducts > $productDisplayLimit): ?>
                        <a href="<?php echo htmlspecialchars($viewAllUrl); ?>" class="view-all-link" data-filter-link>
                            Show All Products (<?php echo $totalFetchedProducts; ?>) <i class="bi bi-chevron-right"></i>
                        </a>
                    <?php endif; ?>
                    </div>
                </div>
                <div class="table-scroll product-table-scroll">
                    <table class="inventory-table">
                        <tbody>
                        <?php if (!empty($productsToDisplay)): ?>
                            <?php foreach ($productsToDisplay as $product): ?>
                                <?php
                                    $stockClass = 'stock-good';
                                    if ((float)$product['stock_quantity'] <= 0) {
                                        $stockClass = 'stock-out';
                                    } elseif ((float)$product['stock_quantity'] <= (float)$product['reorder_level']) {
                                        $stockClass = 'stock-low';
                                    }

                                    $invState = getInventoryState($product);
                                    $productIsArchived = ((int)$product['is_active'] === 0);
                                    $rowExpiryUrgency = expiryUrgency($product['effective_expiry_date'] ?? null);
                                    $rowExpiredQty = $expiredQtyByProduct[(int)$product['id']] ?? 0;
                                ?>
                                <tr>
                                    <td class="cell-image">
                                        <div class="product-image-cell">
                                            <img src="<?php echo htmlspecialchars(inventoryImagePath($product['product_image'] ?? '')); ?>" alt="Product" class="product-thumb" onerror="this.onerror=null;this.src='<?php echo e(nxProjectUrl('IMAGES/default-product.svg')); ?>';">
                                        </div>
                                    </td>

                                    <td class="cell-main">
                                        <div class="product-main-name"><?php echo htmlspecialchars($product['product_name']); ?></div>
                                        <div class="product-sub-brand">
                                            SKU: <?php echo htmlspecialchars($product['product_code']); ?>
                                            <?php if (!empty($product['brand'])): ?>
                                                &nbsp;&bull;&nbsp;<?php echo htmlspecialchars($product['brand']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="stock-line-row">
                                            <span class="stock-qty-number"><?php echo formatQty($product['stock_quantity']); ?></span>
                                            <span class="stock-dot <?php echo $stockClass; ?>" title="<?php echo htmlspecialchars($invState['label']); ?>"></span>
                                            <span class="stock-line <?php echo $stockClass; ?>">
                                                <?php echo htmlspecialchars($invState['label']); ?> &bull; <?php echo (float)$product['stock_quantity'] == 1 ? 'Left' : 'in stock'; ?>
                                            </span>
                                            <?php if ($isBatchTracked && $rowExpiredQty > 0): ?>
                                                <span class="expiry-pill expiry-expired">
                                                    <i class="bi bi-exclamation-octagon-fill"></i> Expired: <?php echo formatQty($rowExpiredQty); ?>
                                                </span>
                                            <?php elseif ($rowExpiryUrgency): ?>
                                                <span class="expiry-pill <?php echo $rowExpiryUrgency['class']; ?>">
                                                    <i class="bi bi-clock-history"></i> <?php echo htmlspecialchars($rowExpiryUrgency['label']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>

                                    <td class="cell-actions actions-cell">
                                        <div class="action-menu-wrap">
                                            <button type="button" class="action-dots-btn reorder-style-btn" data-action-menu-toggle>
                                                <i class="bi bi-arrow-repeat"></i>
                                                <span>Manage</span>
                                            </button>

                                            <div class="action-dropdown" data-action-menu>
                                                <button
                                                    class="action-item edit-btn"
                                                    type="button"
                                                    data-id="<?php echo (int)$product['id']; ?>"
                                                    data-code="<?php echo htmlspecialchars($product['product_code']); ?>"
                                                    data-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                                    data-category="<?php echo (int)$product['category_id']; ?>"
                                                    data-brand="<?php echo htmlspecialchars($product['brand']); ?>"
                                                    data-unit="<?php echo htmlspecialchars($product['unit']); ?>"
                                                    data-cost="<?php echo htmlspecialchars($product['cost_price']); ?>"
                                                    data-selling="<?php echo htmlspecialchars($product['selling_price']); ?>"
                                                    data-discount="<?php echo htmlspecialchars($product['discount_percent'] ?? 0); ?>"
                                                    data-stock="<?php echo htmlspecialchars($product['stock_quantity']); ?>"
                                                    data-reorder="<?php echo htmlspecialchars($product['reorder_level']); ?>"
                                                    data-onorder="<?php echo htmlspecialchars($product['on_order_level'] ?? 0); ?>"
                                                    data-expiry="<?php echo htmlspecialchars($product['expiry_date'] ?? ''); ?>"
                                                    data-description="<?php echo htmlspecialchars($product['description'] ?? ''); ?>"
                                                    data-active="<?php echo (int)$product['is_active']; ?>"
                                                    data-image="<?php echo htmlspecialchars(inventoryImagePath($product['product_image'] ?? '')); ?>"
                                                >
                                                    <i class="bi bi-pencil-square"></i>
                                                    <span>Edit</span>
                                                </button>

                                                <button
                                                    class="action-item stock-btn"
                                                    type="button"
                                                    data-stock-id="<?php echo (int)$product['id']; ?>"
                                                    data-stock-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                                    data-current-onorder="<?php echo htmlspecialchars($product['on_order_level'] ?? 0); ?>"
                                                >
                                                    <i class="bi bi-arrow-left-right"></i>
                                                    <span>Adjust Stock</span>
                                                </button>

                                                <?php if ($isBatchTracked): ?>
                                                    <button
                                                        class="action-item batches-btn"
                                                        type="button"
                                                        data-batches-id="<?php echo (int)$product['id']; ?>"
                                                        data-batches-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                                    >
                                                        <i class="bi bi-layers"></i>
                                                        <span>View Batches</span>
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($isOwner): ?>
                                                    <button
                                                        class="action-item place-order-btn"
                                                        type="button"
                                                        data-order-id="<?php echo (int)$product['id']; ?>"
                                                        data-order-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                                        data-order-current="<?php echo htmlspecialchars($product['on_order_level'] ?? 0); ?>"
                                                    >
                                                        <i class="bi bi-cart-plus"></i>
                                                        <span>Place Order</span>
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ((float)($product['on_order_level'] ?? 0) > 0): ?>
                                                    <button
                                                        class="action-item receive-btn"
                                                        type="button"
                                                        data-receive-id="<?php echo (int)$product['id']; ?>"
                                                        data-receive-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                                        data-receive-onorder="<?php echo htmlspecialchars($product['on_order_level'] ?? 0); ?>"
                                                    >
                                                        <i class="bi bi-box-seam"></i>
                                                        <span>Receive Shipment</span>
                                                    </button>
                                                <?php endif; ?>

                                                <?php if ($productIsArchived): ?>
                                                    <form method="POST" action="/NexGen/CODE/PHP/inventory_restore.php" style="display:contents;">
                                                        <input type="hidden" name="id" value="<?php echo (int)$product['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfDeleteRestore); ?>">
                                                        <button type="submit" class="action-item restore-item">
                                                            <i class="bi bi-arrow-counterclockwise"></i>
                                                            <span>Restore Product</span>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" action="/NexGen/CODE/PHP/inventory_delete.php" style="display:contents;">
                                                        <input type="hidden" name="id" value="<?php echo (int)$product['id']; ?>">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfDeleteRestore); ?>">
                                                        <button type="submit" class="action-item delete-item">
                                                            <i class="bi bi-trash3-fill"></i>
                                                            <span>Archive Product</span>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="3" class="empty-state">No products found.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

                    </div>
    </main>


    <?php if ($isOwner): ?>
    <!-- PURCHASE ORDER HISTORY MINI TAB -->
    <div class="po-history-overlay" id="poHistoryModal" aria-hidden="true">
        <div class="po-history-mini-tab" role="dialog" aria-modal="true" aria-labelledby="poHistoryTitle">
            <div class="po-history-modal-header">
                <div>
                    <span class="po-history-kicker">Inventory Purchasing</span>
                    <h2 id="poHistoryTitle">Purchase Order History</h2>
                    <p>Review quantities and remarks recorded when products were placed on order.</p>
                </div>
                <button type="button" class="po-history-close" id="closePoHistoryModal" aria-label="Close Purchase Order History">&times;</button>
            </div>

            <div class="po-history-modal-meta">
                <span><i class="bi bi-clock-history"></i> Latest 20 orders</span>
                <span class="po-history-total"><?php echo count($orderHistory); ?> shown</span>
            </div>

            <div class="po-history-modal-table-wrap">
                <table class="po-history-modal-table">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Remarks</th>
                            <th>Placed On</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!empty($orderHistory)): ?>
                        <?php foreach ($orderHistory as $order): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($order['product_name']); ?></strong>
                                    <small>SKU: <?php echo htmlspecialchars($order['product_code']); ?></small>
                                </td>
                                <td class="po-qty">+<?php echo formatQty($order['quantity']); ?></td>
                                <td class="po-remarks">
                                    <?php if (trim((string)($order['remarks'] ?? '')) !== ''): ?>
                                        <?php echo nl2br(htmlspecialchars($order['remarks'])); ?>
                                    <?php else: ?>
                                        <span class="po-empty">No remarks provided.</span>
                                    <?php endif; ?>
                                </td>
                                <td class="po-date">
                                    <?php echo !empty($order['created_at'])
                                        ? htmlspecialchars(nxFormatLocalDateTime($order['created_at']) ?? '—')
                                        : '—'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="po-history-empty-state">
                                <i class="bi bi-receipt"></i>
                                <strong>No purchase-order history yet.</strong>
                                <span>New orders will appear here after you place them.</span>
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isBatchTracked): ?>
    <!-- PRODUCT BATCHES MODAL -->
    <div class="modal-overlay" id="batchesModal">
        <div class="batches-modal">
            <div class="modal-header">
                <h2>Batches &mdash; <span id="batchesProductName">Product</span></h2>
                <button type="button" class="close-modal-btn" id="closeBatchesModal">&times;</button>
            </div>

            <p class="batches-modal-hint">FEFO deduction always consumes the earliest-expiring valid batch first. Only expired batches can be dropped.</p>

            <div class="table-scroll">
                <table class="batches-modal-table">
                    <thead>
                        <tr>
                            <th>Batch #</th>
                            <th>Quantity</th>
                            <th>Expiry Date</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="batchesModalBody">
                        <tr><td colspan="5" class="batches-empty-state">Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <form method="POST" action="/NexGen/CODE/PHP/inventory_batch_drop.php" id="batchDropForm" class="visually-hidden-form">
        <input type="hidden" name="batch_id" id="batchDropId" value="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfBatchDrop); ?>">
    </form>

    <!-- DROP BATCH CONFIRMATION -->
    <div class="drop-batch-confirm-overlay" id="dropBatchConfirmOverlay">
        <div class="drop-batch-confirm-card">
            <div class="drop-batch-confirm-icon">
                <i class="bi bi-exclamation-triangle-fill"></i>
            </div>
            <h3>Drop this batch?</h3>
            <p>Batch <strong id="dropBatchConfirmNumber"></strong> will be set to 0 quantity and marked as dropped. This cannot be undone, but the record is kept for history.</p>
            <div class="drop-batch-confirm-actions">
                <button type="button" class="drop-batch-cancel-btn" id="dropBatchCancelBtn">Cancel</button>
                <button type="button" class="drop-batch-confirm-btn" id="dropBatchConfirmBtn">Drop Batch</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ADD PRODUCT MODAL -->
    <div class="modal-overlay" id="productModal">
        <div class="product-modal">
            <div class="modal-header">
                <h2>Add New Product</h2>
                <button type="button" class="close-modal-btn" id="closeProductModal" onclick="document.getElementById('productModal').classList.remove('show'); document.body.style.overflow='';">&times;</button>
            </div>

            <div class="wizard-stepper" id="productWizardStepper">
                <div class="step-node is-active" data-step="1">
                    <span class="step-circle">1</span>
                    <span class="step-label">Basic Info</span>
                </div>
                <div class="step-line"></div>
                <div class="step-node" data-step="2">
                    <span class="step-circle">2</span>
                    <span class="step-label">Pricing</span>
                </div>
                <div class="step-line"></div>
                <div class="step-node" data-step="3">
                    <span class="step-circle">3</span>
                    <span class="step-label">Stock</span>
                </div>
                <div class="step-line"></div>
                <div class="step-node" data-step="4">
                    <span class="step-circle">4</span>
                    <span class="step-label">Details</span>
                </div>
            </div>

            <form action="/NexGen/CODE/PHP/inventory_save.php" method="POST" enctype="multipart/form-data" class="product-form wizard-form" id="addProductWizardForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfAddProduct); ?>">

                <!-- STEP 1: BASIC INFO -->
                <div class="wizard-step is-active" data-step="1">
                    <div class="wizard-step-basic">
                        <div class="form-image-preview">
                            <img src="<?php echo e(nxProjectUrl('IMAGES/default-product.svg')); ?>" alt="Preview" id="previewImage">
                            <label for="product_image" class="image-upload-label">Upload Product Image</label>
                            <input type="file" name="product_image" id="product_image" accept="image/*">
                        </div>

                        <div class="form-fields">
                            <div class="form-group">
                                <label>Product Code</label>
                                <input type="text" name="product_code" required>
                            </div>

                            <div class="form-group">
                                <label>Product Name</label>
                                <input type="text" name="product_name" required>
                            </div>

                            <div class="form-group">
                                <label>Category</label>
                                <select name="category_id" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Brand</label>
                                <input type="text" name="brand">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: PRICING -->
                <div class="wizard-step" data-step="2">
                    <div class="form-fields">
                        <div class="form-group full-width">
                            <label>Unit</label>
                            <input type="text" name="unit" placeholder="e.g. pcs, bottle, pack, kilo" required>
                        </div>

                        <div class="form-group">
                            <label>Cost Price</label>
                            <input type="number" step="0.01" min="0" name="cost_price" required>
                        </div>

                        <div class="form-group">
                            <label>Selling Price</label>
                            <input type="number" step="0.01" min="0" name="selling_price" required>
                        </div>

                        <div class="form-group">
                            <label>Discount %</label>
                            <input type="number" step="0.01" min="0" max="100" name="discount_percent" value="0">
                        </div>
                    </div>
                </div>

                <!-- STEP 3: STOCK -->
                <div class="wizard-step" data-step="3">
                    <div class="form-fields">
                        <?php if ($isBatchTracked): ?>
                            <div class="form-group full-width">
                                <label class="field-help">This business tracks stock per batch. Set up the product's first batch below - the batch number is generated automatically, more batches can be added later via Adjust Stock.</label>
                            </div>
                            <div class="form-group">
                                <label>Initial Quantity</label>
                                <input type="number" min="0.001" step="0.001" name="stock_quantity" required>
                            </div>
                            <div class="form-group">
                                <label>Expiry Date <span class="field-optional">(optional)</span></label>
                                <input type="date" name="expiry_date">
                            </div>
                        <?php else: ?>
                            <div class="form-group">
                                <label>Stock Quantity</label>
                                <input type="number" min="0" step="0.001" name="stock_quantity" required>
                            </div>

                            <div class="form-group">
                                <label>Expiry Date</label>
                                <input type="date" name="expiry_date">
                            </div>
                        <?php endif; ?>

                        <?php if ($isOwner): ?>
                            <div class="form-group">
                                <label>Reorder Level</label>
                                <input type="number" min="0" step="0.001" name="reorder_level" value="5" required>
                            </div>

                            <div class="form-group">
                                <label>On Order Level</label>
                                <input type="number" min="0" step="0.001" name="on_order_level" value="0" required>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- STEP 4: DETAILS -->
                <div class="wizard-step" data-step="4">
                    <div class="form-fields">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="is_active" required>
                                <option value="1">Active</option>
                                <option value="0">Archived</option>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" rows="4"></textarea>
                        </div>
                    </div>
                </div>

                <div class="wizard-footer">
                    <div class="step-progress-text">Step <span id="wizardCurrentStepNum">1</span> of 4</div>
                    <div class="step-dots" id="wizardStepDots">
                        <span class="step-dot is-active" data-dot="1"></span>
                        <span class="step-dot" data-dot="2"></span>
                        <span class="step-dot" data-dot="3"></span>
                        <span class="step-dot" data-dot="4"></span>
                    </div>
                </div>

                <div class="wizard-nav">
                    <button type="button" class="wizard-back-btn" id="wizardBackBtn">Back</button>
                    <button type="button" class="save-btn wizard-next-btn is-visible" id="wizardNextBtn">Next Step &rarr;</button>
                    <button type="submit" class="save-btn wizard-submit-btn" id="wizardSubmitBtn">Save Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- EDIT PRODUCT MODAL -->
    <div class="modal-overlay" id="editProductModal">
        <div class="product-modal">
            <div class="modal-header">
                <h2>Edit Product</h2>
                <button type="button" class="close-modal-btn" id="closeEditProductModal" onclick="document.getElementById('editProductModal').classList.remove('show'); document.body.style.overflow='';">&times;</button>
            </div>

            <div class="wizard-stepper" id="editProductWizardStepper">
                <div class="step-node is-active" data-step="1">
                    <span class="step-circle">1</span>
                    <span class="step-label">Basic Info</span>
                </div>
                <div class="step-line"></div>
                <div class="step-node" data-step="2">
                    <span class="step-circle">2</span>
                    <span class="step-label">Pricing</span>
                </div>
                <div class="step-line"></div>
                <div class="step-node" data-step="3">
                    <span class="step-circle">3</span>
                    <span class="step-label">Stock</span>
                </div>
                <div class="step-line"></div>
                <div class="step-node" data-step="4">
                    <span class="step-circle">4</span>
                    <span class="step-label">Details</span>
                </div>
            </div>

            <form action="/NexGen/CODE/PHP/inventory_update.php" method="POST" enctype="multipart/form-data" class="product-form wizard-form" id="editProductWizardForm" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfEditProduct); ?>">
                <input type="hidden" name="id" id="edit_id">
                <input type="hidden" name="old_image" id="edit_old_image">

                <!-- STEP 1: BASIC INFO -->
                <div class="wizard-step is-active" data-step="1">
                    <div class="wizard-step-basic">
                        <div class="form-image-preview">
                            <img src="<?php echo e(nxProjectUrl('IMAGES/default-product.svg')); ?>" alt="Preview" id="editPreviewImage">
                            <label for="edit_product_image" class="image-upload-label">Change Product Image</label>
                            <input type="file" name="product_image" id="edit_product_image" accept="image/*">
                        </div>

                        <div class="form-fields">
                            <div class="form-group">
                                <label>Product Code</label>
                                <input type="text" name="product_code" id="edit_product_code" required>
                            </div>

                            <div class="form-group">
                                <label>Product Name</label>
                                <input type="text" name="product_name" id="edit_product_name" required>
                            </div>

                            <div class="form-group">
                                <label>Category</label>
                                <select name="category_id" id="edit_category_id" required>
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['id']; ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Brand</label>
                                <input type="text" name="brand" id="edit_brand">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- STEP 2: PRICING -->
                <div class="wizard-step" data-step="2">
                    <div class="form-fields">
                        <div class="form-group full-width">
                            <label>Unit</label>
                            <input type="text" name="unit" id="edit_unit" required>
                        </div>

                        <div class="form-group">
                            <label>Cost Price</label>
                            <input type="number" step="0.01" min="0" name="cost_price" id="edit_cost_price" required>
                        </div>

                        <div class="form-group">
                            <label>Selling Price</label>
                            <input type="number" step="0.01" min="0" name="selling_price" id="edit_selling_price" required>
                        </div>

                        <div class="form-group">
                            <label>Discount %</label>
                            <input type="number" step="0.01" min="0" max="100" name="discount_percent" id="edit_discount_percent" value="0">
                        </div>
                    </div>
                </div>

                <!-- STEP 3: STOCK -->
                <div class="wizard-step" data-step="3">
                    <div class="form-fields">
                        <?php if ($isBatchTracked): ?>
                            <div class="form-group">
                                <label>Current Stock (all batches)</label>
                                <input type="number" id="edit_stock_quantity" readonly>
                            </div>
                            <div class="form-group full-width">
                                <label class="field-help">This business tracks stock per batch. Use Adjust Stock on the product's menu to add a new batch or record a Stock Out - stock quantity here is read-only.</label>
                            </div>
                        <?php else: ?>
                            <div class="form-group">
                                <label>Stock Quantity</label>
                                <input type="number" min="0" step="0.001" name="stock_quantity" id="edit_stock_quantity" required>
                            </div>

                            <div class="form-group">
                                <label>Expiry Date</label>
                                <input type="date" name="expiry_date" id="edit_expiry_date">
                            </div>
                        <?php endif; ?>

                        <?php if ($isOwner): ?>
                            <div class="form-group">
                                <label>Reorder Level</label>
                                <input type="number" min="0" step="0.001" name="reorder_level" id="edit_reorder_level" required>
                            </div>

                            <div class="form-group">
                                <label>On Order Level</label>
                                <input type="number" min="0" step="0.001" name="on_order_level" id="edit_on_order_level" required>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- STEP 4: DETAILS -->
                <div class="wizard-step" data-step="4">
                    <div class="form-fields">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="is_active" id="edit_is_active" required>
                                <option value="1">Active</option>
                                <option value="0">Archived</option>
                            </select>
                        </div>

                        <div class="form-group full-width">
                            <label>Description</label>
                            <textarea name="description" rows="4" id="edit_description"></textarea>
                        </div>
                    </div>
                </div>

                <div class="wizard-footer">
                    <div class="step-progress-text">Step <span id="editWizardCurrentStepNum">1</span> of 4</div>
                    <div class="step-dots" id="editWizardStepDots">
                        <span class="step-dot is-active" data-dot="1"></span>
                        <span class="step-dot" data-dot="2"></span>
                        <span class="step-dot" data-dot="3"></span>
                        <span class="step-dot" data-dot="4"></span>
                    </div>
                </div>

                <div class="wizard-nav">
                    <button type="button" class="wizard-back-btn" id="editWizardBackBtn">Back</button>
                    <button type="button" class="save-btn wizard-next-btn is-visible" id="editWizardNextBtn">Next Step &rarr;</button>
                    <button type="submit" class="save-btn wizard-submit-btn" id="editWizardSubmitBtn">Update Product</button>
                </div>
            </form>
        </div>
    </div>

    <!-- STOCK MODAL -->
    <div class="modal-overlay" id="stockModal">
        <div class="stock-modal">
            <div class="modal-header">
                <h2>Stock Movement</h2>
                <button type="button" class="close-modal-btn" id="closeStockModal" onclick="document.getElementById('stockModal').classList.remove('show'); document.body.style.overflow='';">&times;</button>
            </div>

            <form action="/NexGen/CODE/PHP/inventory_stock.php" method="POST" class="stock-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfStock); ?>">
                <input type="hidden" name="product_id" id="stock_product_id">

                <div class="form-group full-width">
                    <label>Product</label>
                    <input type="text" id="stock_product_name" readonly>
                </div>

                <div class="form-group">
                    <label>Movement Type</label>
                    <select name="movement_type" id="stock_movement_type" required>
                        <option value="stock_in">Stock In</option>
                        <option value="stock_out">Stock Out</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Quantity</label>
                    <input type="number" name="quantity" min="0.001" step="0.001" required>
                </div>

                <?php if ($isBatchTracked): ?>
                <div class="form-group full-width" id="stockBatchFieldsWrap">
                    <label class="field-help">This business tracks stock per batch. For <strong>Stock In</strong>, a new batch is created automatically below (batch number is system-generated) - a Stock Out is drawn automatically from the soonest-expiring batch first.</label>
                </div>
                <div class="form-group">
                    <label>Expiry Date <span class="field-optional">(optional)</span></label>
                    <input type="date" name="batch_expiry_date" id="stock_batch_expiry_date">
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label>Current On Order</label>
                    <input type="number" id="stock_current_on_order" readonly>
                </div>

                <div class="form-group full-width" id="deductOnOrderWrap">
                    <label class="checkbox-row">
                        <input type="checkbox" name="deduct_from_on_order" id="deduct_from_on_order" value="1">
                        Deduct this stock-in quantity from On Order
                    </label>
                </div>

                <div class="form-group full-width">
                    <label>Remarks</label>
                    <textarea name="remarks" rows="3" placeholder="Optional remarks"></textarea>
                </div>

                <div class="form-group form-actions full-width">
                    <button type="submit" class="save-btn">Save Movement</button>
                </div>
            </form>
        </div>
    </div>

    <!-- PLACE ORDER MODAL -->
    <div class="modal-overlay" id="placeOrderModal">
        <div class="stock-modal">
            <div class="modal-header">
                <h2>Place Order</h2>
                <button type="button" class="close-modal-btn" id="closePlaceOrderModal" onclick="document.getElementById('placeOrderModal').classList.remove('show'); document.body.style.overflow='';">&times;</button>
            </div>

            <form action="/NexGen/CODE/PHP/inventory_stock.php" method="POST" class="stock-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfStock); ?>">
                <input type="hidden" name="product_id" id="order_product_id">
                <input type="hidden" name="place_order" value="1">

                <div class="form-group full-width">
                    <label>Product</label>
                    <input type="text" id="order_product_name" readonly>
                </div>

                <div class="form-group">
                    <label>Current On Order</label>
                    <input type="number" id="order_current_on_order" readonly>
                </div>

                <div class="form-group">
                    <label>Quantity to Order</label>
                    <input type="number" name="on_order_add" id="order_quantity" min="0.001" step="0.001" required>
                </div>

                <div class="form-group full-width">
                    <label>Remarks</label>
                    <textarea name="remarks" id="order_remarks" rows="3" placeholder="Optional - supplier, PO number, expected date, etc."></textarea>
                </div>

                <div class="form-group form-actions full-width">
                    <button type="submit" class="save-btn">Confirm Order Placed</button>
                </div>
            </form>
        </div>
    </div>

    <!-- RECEIVE SHIPMENT MODAL -->
    <div class="modal-overlay" id="receiveShipmentModal">
        <div class="stock-modal">
            <div class="modal-header">
                <h2>Receive Shipment</h2>
                <button type="button" class="close-modal-btn" id="closeReceiveShipmentModal" onclick="document.getElementById('receiveShipmentModal').classList.remove('show'); document.body.style.overflow='';">&times;</button>
            </div>

            <form action="/NexGen/CODE/PHP/inventory_stock.php" method="POST" class="stock-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfStock); ?>">
                <input type="hidden" name="product_id" id="receive_product_id">
                <input type="hidden" name="movement_type" value="stock_in">
                <input type="hidden" name="receive_shipment" value="1">
                <input type="hidden" name="deduct_from_on_order" value="1">

                <div class="form-group full-width">
                    <label>Product</label>
                    <input type="text" id="receive_product_name" readonly>
                </div>

                <div class="form-group">
                    <label>Expected (On Order)</label>
                    <input type="number" id="receive_expected_qty" readonly>
                </div>

                <div class="form-group">
                    <label>Quantity Received</label>
                    <input type="number" name="quantity" id="receive_quantity" min="0.001" step="0.001" required>
                </div>

                <?php if ($isBatchTracked): ?>
                <div class="form-group">
                    <label>Expiry Date <span class="field-optional">(optional)</span></label>
                    <input type="date" name="batch_expiry_date" id="receive_batch_expiry_date">
                </div>
                <?php endif; ?>

                <div class="form-group full-width">
                    <label>Remarks</label>
                    <textarea name="remarks" id="receive_remarks" rows="3" placeholder="Optional - supplier, PO number, partial shipment, etc."></textarea>
                </div>

                <div class="form-group form-actions full-width">
                    <button type="submit" class="save-btn">Confirm Shipment Received</button>
                </div>
            </form>
        </div>
    </div>

    <!-- CATEGORY MODAL -->
    <div class="modal-overlay" id="categoryModal">
        <div class="category-modal">
            <div class="modal-header">
                <h2>Manage Categories</h2>
                <button type="button" class="close-modal-btn" id="closeCategoryModal" onclick="document.getElementById('categoryModal').classList.remove('show'); document.body.style.overflow='';">&times;</button>
            </div>

            <form action="/NexGen/CODE/PHP/category_save.php" method="POST" class="category-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfCategory); ?>">
                <div class="form-group full-width">
                    <label>New Category Name</label>
                    <input type="text" name="category_name" required>
                </div>

                <div class="form-group form-actions full-width">
                    <button type="submit" class="save-btn">Add Category</button>
                </div>
            </form>

            <div class="category-list-wrap">
                <h3>Existing Categories</h3>
                <ul class="category-list">
                    <?php foreach ($categories as $category): ?>
                        <li><?php echo htmlspecialchars($category['category_name']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <footer class="nx-footer">
        <div class="nx-footer-line"></div>
        <p class="nx-footer-copy">Copyright &copy; 2026 NexGen.</p>
    </footer>
</div>

<?php include 'chatbot.php'; ?>
<script src="/NexGen/CODE/JS/header.js?v=2"></script>
<script src="/NexGen/CODE/JS/inventory_management.js?v=<?php echo time(); ?>"></script>

</body>
</html>
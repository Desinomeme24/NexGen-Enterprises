<?php

function nxcb_system_guide(array $args, array $ctx = []): array {
    $topic = $args['topic'] ?? 'general';

    $guides = [
        'general' => [
            'NexGen manages inventory, sales recording, sales analytics, accounts receivable, alerts, and role-based access.',
            'Ask the assistant for live business data or for steps on using a module.'
        ],
        'inventory' => [
            'Open Inventory Management to add or edit products.',
            "Use '+ Add Product' to enter code, name, category, unit, prices, stock quantity, reorder level, on-order level, and expiry date when applicable.",
            "Use 'Stock In/Out' on a product to record inventory movement.",
            'The system can identify low-stock, out-of-stock, expired, and soon-to-expire products from live records.'
        ],
        'sales' => [
            "Open Sales and choose 'New Sale'.",
            'Complete Sale Info, add products and quantities in Items, then check Review and save the sale.',
            nxcb_permission($ctx, 'accounts_receivable')
                ? 'Enter customer details when needed. Unpaid or partially paid sales can be monitored in Accounts Receivable.'
                : 'This account records paid sales. Customer credit and partial/unpaid options require Accounts Receivable access.'
        ],
        'analytics' => [
            'Open Sales Analytics and select a period such as Today, This Week, This Month, or a custom range.',
            'Analytics can summarize gross revenue, COGS, net profit, transactions, top products, and category performance.',
            'COGS means cost of goods sold. This summary calculates it using product cost prices and quantities sold.',
            'In this summary, net profit means revenue minus COGS; operating expenses are excluded.'
        ],
        'receivables' => [
            'Open Accounts Receivable to review outstanding balances and overdue accounts.',
            "Use 'Update Payment' when a customer makes a payment.",
            'Follow-up priority should focus on overdue balances first, then other remaining balances.'
        ],
        'categories' => [
            "Open Inventory Management and use 'Manage Categories' to add or update categories."
        ],
        'navigation' => [
            'The assistant can prepare confirmation actions to open Dashboard, Inventory, Sales, Sales Analytics, Accounts Receivable, Settings, or About Us.'
        ],
        'security' => [
            'NexGen uses the logged-in user session and module permissions to restrict chatbot data access.',
            'The chatbot tool layer also rechecks permissions before reading live data.'
        ],
    ];

    return ['ok' => true, 'topic' => $topic, 'guide' => $guides[$topic] ?? $guides['general']];
}

function nxcb_open_module(array $args, array $ctx): array {
    $module = $args['module'] ?? 'dashboard';
    $map = [
        'dashboard' => ['label' => 'Dashboard', 'url' => 'dashboard.php', 'permission' => null],
        'inventory' => ['label' => 'Inventory Management', 'url' => 'inventory_management.php', 'permission' => 'inventory'],
        'sales' => ['label' => 'Sales', 'url' => 'sales_recording.php', 'permission' => 'sales'],
        'analytics' => ['label' => 'Sales Analytics', 'url' => 'sales_analytics.php', 'permission' => 'sales_analytics'],
        'receivables' => ['label' => 'Accounts Receivable', 'url' => 'accounts_receivable.php', 'permission' => 'accounts_receivable'],
        'settings' => ['label' => 'Settings', 'url' => 'settings.php', 'permission' => null],
        'about' => ['label' => 'About Us', 'url' => 'about_us.php', 'permission' => null],
    ];

    if (!isset($map[$module])) return ['ok' => false, 'error' => 'unknown_module'];
    $item = $map[$module];
    $item['url'] = ($ctx['php_base'] ?? '') . $item['url'];

    if ($item['permission'] && !nxcb_permission($ctx, $item['permission'])) {
        return ['ok' => false, 'error' => 'permission_denied', 'message' => "You do not have access to {$item['label']}."];
    }

    return [
        'ok' => true,
        'module' => $module,
        'label' => $item['label'],
        'url' => $item['url'],
        'open_marker' => "[OPEN_CONFIRM]|{$item['label']}|{$item['url']}",
    ];
}

function nxcb_alerts(mysqli $conn, array $ctx): array {
    $businessId = (int)$ctx['business_id'];
    $alerts = [];

    if (nxcb_permission($ctx, 'inventory')) {
        $stmt = $conn->prepare("SELECT
            SUM(CASE WHEN is_active = 1 AND stock_quantity <= 0 THEN 1 ELSE 0 END) AS out_count,
            SUM(CASE WHEN is_active = 1 AND stock_quantity > 0 AND stock_quantity <= reorder_level THEN 1 ELSE 0 END) AS low_count,
            SUM(CASE WHEN is_active = 1 AND effective_expiry IS NOT NULL AND effective_expiry < CURDATE() THEN 1 ELSE 0 END) AS expired_count,
            SUM(CASE WHEN is_active = 1 AND effective_expiry IS NOT NULL AND effective_expiry >= CURDATE() AND effective_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS expiring_count
            FROM (
                SELECT p.is_active, p.stock_quantity, p.reorder_level,
                       COALESCE(p.expiry_date, (SELECT MIN(pb.expiry_date)
                           FROM product_batches pb
                           WHERE pb.product_id = p.id
                             AND pb.business_id = p.business_id
                             AND pb.status = 'active')) AS effective_expiry
                FROM products p
                WHERE p.business_id = ?
            ) AS inventory_expiry");
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        if ((int)($row['out_count'] ?? 0) > 0) $alerts[] = ['type' => 'out_of_stock', 'count' => (int)$row['out_count']];
        if ((int)($row['low_count'] ?? 0) > 0) $alerts[] = ['type' => 'low_stock', 'count' => (int)$row['low_count']];
        if ((int)($row['expired_count'] ?? 0) > 0) $alerts[] = ['type' => 'expired_products', 'count' => (int)$row['expired_count']];
        if ((int)($row['expiring_count'] ?? 0) > 0) $alerts[] = ['type' => 'expiring_within_30_days', 'count' => (int)$row['expiring_count']];
    }

    if (nxcb_permission($ctx, 'accounts_receivable')) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM accounts_receivable
                                WHERE business_id = ? AND balance_due > 0
                                  AND due_date IS NOT NULL AND due_date < CURDATE()");
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $count = (int)(($stmt->get_result()->fetch_assoc()['cnt'] ?? 0));
        $stmt->close();
        if ($count > 0) $alerts[] = ['type' => 'overdue_receivables', 'count' => $count];
    }

    if (nxcb_permission($ctx, 'sales_analytics')) {
        $now = new DateTime();
        $yesterday = (clone $now)->modify('-1 day');
        $todayStart = $now->format('Y-m-d 00:00:00');
        $todayEnd = $now->format('Y-m-d H:i:s');
        $yStart = $yesterday->format('Y-m-d 00:00:00');
        $yEnd = $yesterday->format('Y-m-d H:i:s');

        $stmt = $conn->prepare("SELECT
            COALESCE(SUM(CASE WHEN sale_date BETWEEN ? AND ? THEN total_amount ELSE 0 END),0) AS today_revenue,
            COALESCE(SUM(CASE WHEN sale_date BETWEEN ? AND ? THEN total_amount ELSE 0 END),0) AS yesterday_revenue
            FROM sales WHERE business_id = ?");
        $stmt->bind_param('ssssi', $todayStart, $todayEnd, $yStart, $yEnd, $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $todayRevenue = (float)($row['today_revenue'] ?? 0);
        $yRevenue = (float)($row['yesterday_revenue'] ?? 0);
        if ($yRevenue > 0 && $todayRevenue < $yRevenue) {
            $alerts[] = [
                'type' => 'sales_below_yesterday_same_time',
                'today_revenue' => nxcb_money_number($todayRevenue),
                'yesterday_revenue' => nxcb_money_number($yRevenue),
                'difference' => nxcb_money_number($yRevenue - $todayRevenue),
                'percent_below' => round((($yRevenue - $todayRevenue) / $yRevenue) * 100, 2),
            ];
        }
    }

    return ['ok' => true, 'count' => count($alerts), 'alerts' => $alerts, 'checked_at' => date('Y-m-d H:i:s')];
}
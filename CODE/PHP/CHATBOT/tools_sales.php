<?php

function nxcb_sales_summary(mysqli $conn, array $args, array $ctx): array {
    if (!nxcb_permission($ctx, 'sales_analytics')) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    $businessId = (int)$ctx['business_id'];
    $range = nxcb_range($args, 'today');
    if (!empty($range['error'])) return ['ok' => false, 'error' => $range['error']];

    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) AS gross_revenue, COUNT(*) AS transactions
                            FROM sales WHERE business_id = ? AND sale_date BETWEEN ? AND ?");
    $stmt->bind_param('iss', $businessId, $range['start'], $range['end']);
    $stmt->execute();
    $revenue = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(p.cost_price * si.quantity),0) AS cogs
                            FROM sale_items si
                            INNER JOIN sales s ON s.id = si.sale_id
                            INNER JOIN products p ON p.id = si.product_id AND p.business_id = s.business_id
                            WHERE s.business_id = ? AND s.sale_date BETWEEN ? AND ?");
    $stmt->bind_param('iss', $businessId, $range['start'], $range['end']);
    $stmt->execute();
    $cogsRow = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    $gross = nxcb_money_number($revenue['gross_revenue'] ?? 0);
    $cogs = nxcb_money_number($cogsRow['cogs'] ?? 0);

    return [
        'ok' => true,
        'range' => $range,
        'gross_revenue' => $gross,
        'cogs' => $cogs,
        'net_profit' => nxcb_money_number($gross - $cogs),
        'cogs_percent_of_revenue' => $gross > 0 ? round(($cogs / $gross) * 100, 2) : 0.0,
        'transactions' => (int)($revenue['transactions'] ?? 0),
    ];
}


function nxcb_sales_activity(mysqli $conn, array $args, array $ctx): array {
    if (!nxcb_permission($ctx, 'sales') && !nxcb_permission($ctx, 'sales_analytics')) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }

    $businessId = (int)$ctx['business_id'];
    $limit = nxcb_clamp_int($args['limit'] ?? 10, 1, 20, 10);
    $period = $args['period'] ?? 'recent';

    if ($period === 'recent') {
        $stmt = $conn->prepare("SELECT sales_no, sale_date, total_amount
                                FROM sales
                                WHERE business_id = ?
                                ORDER BY sale_date DESC, id DESC
                                LIMIT ?");
        if (!$stmt) return ['ok' => false, 'error' => 'database_error'];
        $stmt->bind_param('ii', $businessId, $limit);
        $label = 'Recent Sales';
    } else {
        $range = nxcb_range($args, 'today');
        if (!empty($range['error'])) return ['ok' => false, 'error' => $range['error']];
        $stmt = $conn->prepare("SELECT sales_no, sale_date, total_amount
                                FROM sales
                                WHERE business_id = ? AND sale_date BETWEEN ? AND ?
                                ORDER BY sale_date DESC, id DESC
                                LIMIT ?");
        if (!$stmt) return ['ok' => false, 'error' => 'database_error'];
        $stmt->bind_param('issi', $businessId, $range['start'], $range['end'], $limit);
        $label = $range['label'];
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'error' => 'database_error'];
    }

    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($items as &$row) {
        $row['total_amount'] = nxcb_money_number($row['total_amount'] ?? 0);
    }

    return [
        'ok' => true,
        'period' => $period,
        'label' => $label,
        'count' => count($items),
        'items' => $items,
    ];
}

function nxcb_product_performance(mysqli $conn, array $args, array $ctx): array {
    if (!nxcb_permission($ctx, 'sales_analytics')) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }

    $businessId = (int)$ctx['business_id'];
    $metric = $args['metric'] ?? 'top_quantity';
    $limit = nxcb_clamp_int($args['limit'] ?? 5, 1, 10, 5);
    $range = nxcb_range($args, 'this_month');
    if (!empty($range['error'])) return ['ok' => false, 'error' => $range['error']];

    if ($metric === 'no_recent_sales') {
        $days = nxcb_clamp_int($args['days_without_sales'] ?? 30, 1, 365, 30);
        $stmt = $conn->prepare("SELECT p.product_name, p.product_code
                                FROM products p
                                LEFT JOIN sale_items si ON p.id = si.product_id
                                LEFT JOIN sales s ON s.id = si.sale_id
                                    AND s.business_id = ?
                                    AND s.sale_date >= (NOW() - INTERVAL ? DAY)
                                WHERE p.business_id = ? AND p.is_active = 1
                                GROUP BY p.id, p.product_name, p.product_code
                                HAVING COUNT(s.id) = 0
                                ORDER BY p.product_name ASC LIMIT ?");
        $stmt->bind_param('iiii', $businessId, $days, $businessId, $limit);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return ['ok' => true, 'metric' => $metric, 'days_without_sales' => $days, 'count' => count($items), 'items' => $items];
    }

    switch ($metric) {
        case 'top_revenue':
            $select = "COALESCE(SUM(si.quantity * si.unit_price),0) AS value";
            $order = "value DESC";
            break;
        case 'highest_cogs':
            $select = "COALESCE(SUM(p.cost_price * si.quantity),0) AS value";
            $order = "value DESC";
            break;
        case 'slow_moving':
            $stmt = $conn->prepare("SELECT p.product_name, p.product_code,
                                           COALESCE(SUM(CASE WHEN s.sale_date BETWEEN ? AND ? THEN si.quantity ELSE 0 END),0) AS value
                                    FROM products p
                                    LEFT JOIN sale_items si ON p.id = si.product_id
                                    LEFT JOIN sales s ON s.id = si.sale_id AND s.business_id = ?
                                    WHERE p.business_id = ? AND p.is_active = 1
                                    GROUP BY p.id, p.product_name, p.product_code
                                    ORDER BY value ASC, p.product_name ASC LIMIT ?");
            $stmt->bind_param('ssiii', $range['start'], $range['end'], $businessId, $businessId, $limit);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($items as &$row) $row['value'] = (float)$row['value'];
            return ['ok' => true, 'metric' => $metric, 'range' => $range, 'count' => count($items), 'items' => $items];
        case 'top_quantity':
        default:
            $select = "COALESCE(SUM(si.quantity),0) AS value";
            $order = "value DESC";
            break;
    }

    $sql = "SELECT p.product_name, p.product_code, {$select}
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            INNER JOIN products p ON p.id = si.product_id
            WHERE s.business_id = ? AND p.business_id = ? AND s.sale_date BETWEEN ? AND ?
            GROUP BY p.id, p.product_name, p.product_code
            ORDER BY {$order}, p.product_name ASC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iissi', $businessId, $businessId, $range['start'], $range['end'], $limit);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($items as &$row) $row['value'] = (float)$row['value'];

    return ['ok' => true, 'metric' => $metric, 'range' => $range, 'count' => count($items), 'items' => $items];
}

function nxcb_category_performance(mysqli $conn, array $args, array $ctx): array {
    if (!nxcb_permission($ctx, 'sales_analytics')) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }

    $businessId = (int)$ctx['business_id'];
    $metric = $args['metric'] ?? 'units';
    $order = $args['order'] ?? 'highest';
    $limit = nxcb_clamp_int($args['limit'] ?? 10, 1, 20, 10);
    $range = nxcb_range($args, 'this_month');
    if (!empty($range['error'])) return ['ok' => false, 'error' => $range['error']];

    $valueSql = $metric === 'revenue'
        ? "COALESCE(SUM(si.quantity * si.unit_price),0)"
        : "COALESCE(SUM(si.quantity),0)";

    $sql = "SELECT c.category_name, {$valueSql} AS value
            FROM sale_items si
            INNER JOIN sales s ON s.id = si.sale_id
            INNER JOIN products p ON p.id = si.product_id
            INNER JOIN categories c ON c.id = p.category_id
            WHERE s.business_id = ? AND p.business_id = ? AND c.business_id = ?
              AND s.sale_date BETWEEN ? AND ?
            GROUP BY c.id, c.category_name
            ORDER BY value " . ($order === 'lowest' ? 'ASC' : 'DESC') . ", c.category_name ASC LIMIT ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiissi', $businessId, $businessId, $businessId, $range['start'], $range['end'], $limit);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($items as &$row) $row['value'] = (float)$row['value'];

    return ['ok' => true, 'metric' => $metric, 'order' => $order, 'range' => $range, 'count' => count($items), 'items' => $items];
}

function nxcb_linear_forecast(array $values, int $horizon): ?array {
    $n = count($values);
    if ($n < 3) return null;

    $xs = range(0, $n - 1);
    $meanX = array_sum($xs) / $n;
    $meanY = array_sum($values) / $n;

    $num = 0.0;
    $den = 0.0;
    foreach ($xs as $i => $x) {
        $num += ($x - $meanX) * ($values[$i] - $meanY);
        $den += ($x - $meanX) ** 2;
    }

    $slope = $den != 0 ? $num / $den : 0.0;
    $intercept = $meanY - ($slope * $meanX);
    $future = [];
    for ($d = 0; $d < $horizon; $d++) {
        $future[] = max(0, $intercept + $slope * ($n + $d));
    }

    return [
        'history_days' => $n,
        'daily_average' => round($meanY, 2),
        'slope_per_day' => round($slope, 2),
        'trend' => $slope > 0.01 ? 'increasing' : ($slope < -0.01 ? 'decreasing' : 'flat'),
        'projected_total' => round(array_sum($future), 2),
        'projected_daily_average' => round(array_sum($future) / max(1, count($future)), 2),
        'horizon_days' => $horizon,
    ];
}

function nxcb_forecast_business(mysqli $conn, array $args, array $ctx): array {
    $kind = $args['kind'] ?? 'sales';
    $horizon = nxcb_clamp_int($args['horizon_days'] ?? 7, 1, 30, 7);
    $businessId = (int)$ctx['business_id'];

    if ($kind === 'sales') {
        if (!nxcb_permission($ctx, 'sales_analytics')) return ['ok' => false, 'error' => 'permission_denied'];
        $stmt = $conn->prepare("SELECT DATE(sale_date) AS d, COALESCE(SUM(total_amount),0) AS value
                                FROM sales
                                WHERE business_id = ? AND sale_date >= (CURDATE() - INTERVAL 60 DAY)
                                GROUP BY DATE(sale_date) ORDER BY d ASC");
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $values = array_map(fn($r) => (float)$r['value'], $rows);
        $forecast = nxcb_linear_forecast($values, $horizon);
        return ['ok' => true, 'kind' => 'sales', 'forecast' => $forecast, 'has_enough_history' => $forecast !== null];
    }

    if (!nxcb_permission($ctx, 'inventory')) return ['ok' => false, 'error' => 'permission_denied'];
    $query = trim((string)($args['product_query'] ?? ''));
    if ($query === '') return ['ok' => false, 'error' => 'missing_product_query'];

    $product = nxcb_product_lookup($conn, $businessId, $query);
    if (!$product) return ['ok' => true, 'kind' => 'product', 'found' => false, 'query' => $query];

    $stmt = $conn->prepare("SELECT DATE(s.sale_date) AS d, COALESCE(SUM(si.quantity),0) AS value
                            FROM sale_items si
                            INNER JOIN sales s ON s.id = si.sale_id
                            WHERE s.business_id = ? AND si.product_id = ?
                              AND s.sale_date >= (CURDATE() - INTERVAL 60 DAY)
                            GROUP BY DATE(s.sale_date) ORDER BY d ASC");
    $stmt->bind_param('ii', $businessId, $product['id']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $values = array_map(fn($r) => (float)$r['value'], $rows);
    $forecast = nxcb_linear_forecast($values, $horizon);

    return [
        'ok' => true,
        'kind' => 'product',
        'found' => true,
        'product' => $product,
        'forecast' => $forecast,
        'has_enough_history' => $forecast !== null,
    ];
}
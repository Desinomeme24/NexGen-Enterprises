<?php

// Defensive dependency load: prevents a missing include from becoming a fatal undefined-function error.
if (!function_exists('nxcb_execute_tool')) {
    $executorPath = __DIR__ . '/tool_executor.php';
    if (is_file($executorPath)) require_once $executorPath;
}

/** Deterministic formatting for authoritative NexGen tool results. */
function nxcb_money($value): string {
    return '₱' . number_format((float)$value, 2);
}

function nxcb_pretty_date(?string $date): string {
    if (!$date) return 'no due date';
    $ts = strtotime($date);
    return $ts ? date('M j, Y', $ts) : $date;
}

function nxcb_tool_error_reply(array $result): string {
    if (!empty($result['message'])) return (string)$result['message'];
    $error = $result['error'] ?? 'tool_error';

    $map = [
        'invalid_workspace' => 'Please select an active business branch and try again.',
        'permission_denied' => 'You do not have permission to access that NexGen data.',
        'missing_query' => 'Please tell me which product you want to search for.',
        'missing_product_query' => 'Please tell me which product you mean.',
        'missing_customer_query' => 'Please tell me which customer you mean.',
        'database_error' => 'I could not read that data from NexGen right now.',
        'tool_exception' => 'I could not read that NexGen data right now. The technical error was logged for troubleshooting.',
        'tool_unavailable' => 'That NexGen chatbot component is not loaded correctly. The technical error was logged for troubleshooting.',
        'unknown_tool' => 'I could not match that request to a NexGen tool.',
    ];

    return $map[$error] ?? 'I could not complete that NexGen request.';
}

function nxcb_format_inventory_result(array $result): string {
    $filter = $result['filter'] ?? 'search';
    $items = $result['items'] ?? [];
    $count = (int)($result['count'] ?? count($items));

    if ($count === 0) {
        $empty = [
            'expired' => 'You have no expired products.',
            'expiring' => 'You have no products expiring in the requested period.',
            'low_stock' => 'You have no low-stock products.',
            'out_of_stock' => 'You have no out-of-stock products.',
            'near_reorder' => 'No products are currently near their reorder level.',
            'on_order' => 'No products currently have an on-order quantity.',
            'recently_added' => 'No recently added products were found.',
            'search' => 'I could not find a matching product.',
        ];
        return $empty[$filter] ?? 'No matching inventory records were found.';
    }

    $labels = [
        'expired' => "I found {$count} expired product" . ($count === 1 ? '' : 's') . ':',
        'expiring' => "I found {$count} product" . ($count === 1 ? '' : 's') . ' expiring soon:',
        'low_stock' => "I found {$count} low-stock product" . ($count === 1 ? '' : 's') . ':',
        'out_of_stock' => "I found {$count} out-of-stock product" . ($count === 1 ? '' : 's') . ':',
        'near_reorder' => "I found {$count} product" . ($count === 1 ? '' : 's') . ' near the reorder level:',
        'on_order' => "I found {$count} product" . ($count === 1 ? '' : 's') . ' with stock on order:',
        'recently_added' => "Here " . ($count === 1 ? 'is' : 'are') . " {$count} recently added product" . ($count === 1 ? '' : 's') . ':',
        'search' => "I found {$count} matching product" . ($count === 1 ? '' : 's') . ':',
    ];

    $lines = [$labels[$filter] ?? "I found {$count} product" . ($count === 1 ? '' : 's') . ':'];
    foreach (array_slice($items, 0, 20) as $item) {
        $name = (string)($item['product_name'] ?? $item['product_code'] ?? 'Product');
        $stock = isset($item['stock_quantity']) ? (int)$item['stock_quantity'] : null;
        $detail = $stock !== null ? "{$stock} in stock" : '';

        if (in_array($filter, ['low_stock', 'near_reorder'], true) && isset($item['reorder_level'])) {
            $detail .= ($detail ? ', ' : '') . 'reorder level ' . (int)$item['reorder_level'];
        } elseif ($filter === 'on_order' && isset($item['on_order_level'])) {
            $detail .= ($detail ? ', ' : '') . (int)$item['on_order_level'] . ' on order';
        } elseif (in_array($filter, ['expired', 'expiring'], true) && !empty($item['expiry_date'])) {
            $detail .= ($detail ? ', ' : '') . 'expiry ' . nxcb_pretty_date($item['expiry_date']);
        }

        $lines[] = '• ' . $name . ($detail ? ' — ' . $detail : '');
    }

    if ($count >= 20) $lines[] = 'Showing up to 20 records. Open the module for the full list.';
    return implode("\n", $lines);
}

function nxcb_format_product_history(array $result): string {
    if (isset($result['found']) && !$result['found']) {
        return 'I could not find that product in the active inventory.';
    }

    $product = $result['product'] ?? [];
    $record = $result['record'] ?? null;
    $name = $product['product_name'] ?? 'That product';
    $event = $result['event'] ?? 'last_stock_in';

    if (!$record) {
        return $event === 'last_sale'
            ? "I found {$name}, but there is no recorded sale for it yet."
            : "I found {$name}, but there is no recorded Stock In event for it yet.";
    }

    if ($event === 'last_sale') {
        $date = nxcb_pretty_date($record['sale_date'] ?? null);
        $qty = (int)($record['quantity'] ?? 0);
        $salesNo = $record['sales_no'] ?? '';
        return "The most recent sale for {$name} was {$qty} unit" . ($qty === 1 ? '' : 's') . " on {$date}" . ($salesNo ? " ({$salesNo})" : '') . '.';
    }

    $date = nxcb_pretty_date($record['created_at'] ?? null);
    $qty = (int)($record['quantity'] ?? 0);
    return "The most recent Stock In for {$name} was {$qty} unit" . ($qty === 1 ? '' : 's') . " on {$date}.";
}

function nxcb_format_stock_activity(array $result): string {
    $items = $result['items'] ?? [];
    $count = (int)($result['count'] ?? count($items));
    $label = $result['range']['label'] ?? 'the requested period';
    if ($count === 0) return "No stock movement was found for {$label}.";

    $lines = ["I found {$count} stock movement" . ($count === 1 ? '' : 's') . " for {$label}:"];
    foreach (array_slice($items, 0, 20) as $item) {
        $type = ucwords(str_replace('_', ' ', (string)($item['movement_type'] ?? 'movement')));
        $name = $item['product_name'] ?? 'Product';
        $qty = (int)($item['quantity'] ?? 0);
        $date = nxcb_pretty_date($item['created_at'] ?? null);
        $lines[] = "• {$type}: {$name} — {$qty} unit" . ($qty === 1 ? '' : 's') . " on {$date}";
    }
    if ($count >= 20) $lines[] = 'Showing up to 20 records. Open the module for the full list.';
    return implode("\n", $lines);
}

function nxcb_format_sales_activity(array $result): string {
    $items = $result['items'] ?? [];
    $count = (int)($result['count'] ?? count($items));
    $label = (string)($result['label'] ?? 'Recent Sales');

    if ($count === 0) return "No sales transactions were found for {$label}.";

    $lines = ["{$label}: I found {$count} sales transaction" . ($count === 1 ? '' : 's') . ':'];
    foreach (array_slice($items, 0, 20) as $item) {
        $salesNo = (string)($item['sales_no'] ?? 'Sale');
        $amount = nxcb_money($item['total_amount'] ?? 0);
        $date = nxcb_pretty_date($item['sale_date'] ?? null);
        $lines[] = "• {$salesNo} — {$amount} on {$date}";
    }
    if ($count >= 20) $lines[] = 'Showing up to 20 records. Open the module for the full list.';
    return implode("\n", $lines);
}

function nxcb_format_sales_summary(array $result): string {
    $label = $result['range']['label'] ?? 'Requested period';
    $gross = nxcb_money($result['gross_revenue'] ?? 0);
    $cogs = nxcb_money($result['cogs'] ?? 0);
    $net = nxcb_money($result['net_profit'] ?? 0);
    $transactions = (int)($result['transactions'] ?? 0);

    return "{$label}: {$gross} gross revenue, {$net} net profit, {$cogs} COGS, across {$transactions} transaction" . ($transactions === 1 ? '' : 's') . '.';
}

function nxcb_format_product_performance(array $result): string {
    $metric = $result['metric'] ?? 'top_quantity';
    $items = $result['items'] ?? [];
    $count = (int)($result['count'] ?? count($items));
    $label = $result['range']['label'] ?? null;

    if ($count === 0) {
        return $metric === 'no_recent_sales'
            ? 'All active products have recent sales within that period.'
            : 'No product performance data was found for that period.';
    }

    $titles = [
        'top_quantity' => 'Top products by units sold',
        'top_revenue' => 'Top products by revenue',
        'highest_cogs' => 'Products with the highest COGS',
        'slow_moving' => 'Slow-moving products',
        'no_recent_sales' => 'Products with no recent sales',
    ];
    $title = $titles[$metric] ?? 'Product performance';
    if ($label && $metric !== 'no_recent_sales') $title .= " ({$label})";

    $lines = [$title . ':'];
    foreach (array_slice($items, 0, 20) as $i => $item) {
        $name = $item['product_name'] ?? $item['product_code'] ?? 'Product';
        if ($metric === 'no_recent_sales') {
            $lines[] = '• ' . $name;
            continue;
        }

        $value = (float)($item['value'] ?? 0);
        if (in_array($metric, ['top_revenue', 'highest_cogs'], true)) {
            $valueText = nxcb_money($value);
        } else {
            $valueText = number_format($value, 0) . ' unit' . ((int)$value === 1 ? '' : 's');
        }
        $lines[] = ($i + 1) . ". {$name} — {$valueText}";
    }
    return implode("\n", $lines);
}

function nxcb_format_category_performance(array $result): string {
    $items = $result['items'] ?? [];
    $count = (int)($result['count'] ?? count($items));
    $metric = $result['metric'] ?? 'units';
    $order = $result['order'] ?? 'highest';
    $label = $result['range']['label'] ?? 'the requested period';

    if ($count === 0) return "No category performance data was found for {$label}.";

    $lines = [ucfirst($order) . " category performance by {$metric} ({$label}):"];
    foreach (array_slice($items, 0, 20) as $i => $item) {
        $name = $item['category_name'] ?? 'Category';
        $value = (float)($item['value'] ?? 0);
        $valueText = $metric === 'revenue' ? nxcb_money($value) : number_format($value, 0) . ' units';
        $lines[] = ($i + 1) . ". {$name} — {$valueText}";
    }
    return implode("\n", $lines);
}

function nxcb_format_receivables(array $result): string {
    $view = $result['view'] ?? 'summary';

    if ($view === 'summary') {
        return 'Accounts Receivable: ' . nxcb_money($result['total_balance_due'] ?? 0)
            . ' current balance due. There are ' . (int)($result['total_receivables'] ?? 0) . ' receivable record(s); '
            . (int)($result['overdue_count'] ?? 0) . ' overdue, '
            . (int)($result['unpaid_count'] ?? 0) . ' unpaid, and '
            . (int)($result['partial_count'] ?? 0) . ' partially paid.';
    }

    $items = $result['items'] ?? [];
    $count = (int)($result['count'] ?? count($items));
    if ($count === 0) {
        if ($view === 'overdue') return 'You currently have no overdue receivables.';
        if ($view === 'followup_priority') return 'There are no outstanding customer accounts that need follow-up.';
        if ($view === 'largest_balance') return 'There are no outstanding receivable balances.';
        return 'No matching receivable records were found.';
    }

    if ($view === 'followup_priority') {
        $first = $items[0];
        $name = $first['customer_name'] ?? 'Customer';
        $balance = nxcb_money($first['balance_due'] ?? 0);
        $dueDate = $first['due_date'] ?? null;
        $due = nxcb_pretty_date($dueDate);
        $days = isset($first['days_overdue']) ? (int)$first['days_overdue'] : 0;
        if ($days > 0) {
            $why = "{$days} day" . ($days === 1 ? '' : 's') . ' overdue';
        } elseif ($dueDate) {
            $why = "due {$due}";
        } else {
            $why = 'an outstanding balance';
        }
        return "Follow up with {$name} first — {$balance} outstanding and {$why}.";
    }

    if ($view === 'largest_balance') {
        $item = $items[0];
        $name = $item['customer_name'] ?? 'Customer';
        return "{$name} has the largest outstanding balance at " . nxcb_money($item['balance_due'] ?? 0) . ', due ' . nxcb_pretty_date($item['due_date'] ?? null) . '.';
    }

    $title = $view === 'overdue' ? 'Overdue receivables:' : ($view === 'customer_search' ? 'Matching customer receivables:' : 'Outstanding receivables:');
    $lines = [$title];
    foreach (array_slice($items, 0, 20) as $item) {
        $name = $item['customer_name'] ?? 'Customer';
        $balance = nxcb_money($item['balance_due'] ?? 0);
        $status = $item['live_status'] ?? ucfirst($view);
        $due = nxcb_pretty_date($item['due_date'] ?? null);
        $salesNo = $item['sales_no'] ?? '';
        $lines[] = "• {$name}" . ($salesNo ? " ({$salesNo})" : '') . " — {$balance}, {$status}, due {$due}";
    }
    if ($count >= 20) $lines[] = 'Showing up to 20 records. Open the module for the full list.';
    return implode("\n", $lines);
}

function nxcb_format_alerts(array $result): string {
    $alerts = $result['alerts'] ?? [];
    if (!$alerts) return 'You have no current NexGen alerts.';

    $lines = ['Current NexGen alerts:'];
    foreach ($alerts as $alert) {
        $type = $alert['type'] ?? '';
        switch ($type) {
            case 'out_of_stock':
                $lines[] = '• ' . (int)$alert['count'] . ' out-of-stock product(s)';
                break;
            case 'low_stock':
                $lines[] = '• ' . (int)$alert['count'] . ' low-stock product(s)';
                break;
            case 'expired_products':
                $lines[] = '• ' . (int)$alert['count'] . ' expired product(s)';
                break;
            case 'expiring_within_30_days':
                $lines[] = '• ' . (int)$alert['count'] . ' product(s) expiring within 30 days';
                break;
            case 'overdue_receivables':
                $lines[] = '• ' . (int)$alert['count'] . ' overdue receivable(s)';
                break;
            case 'sales_below_yesterday_same_time':
                $lines[] = '• Sales are ' . (float)($alert['percent_below'] ?? 0) . '% below yesterday at the same time ('
                    . nxcb_money($alert['today_revenue'] ?? 0) . ' vs ' . nxcb_money($alert['yesterday_revenue'] ?? 0) . ')';
                break;
        }
    }
    return implode("\n", $lines);
}

function nxcb_format_forecast(array $result): string {
    if (isset($result['found']) && !$result['found']) {
        return 'I could not find that product for forecasting.';
    }
    if (empty($result['has_enough_history']) || empty($result['forecast'])) {
        return 'There is not enough recent sales history to create a reliable forecast yet.';
    }

    $forecast = $result['forecast'];
    $days = (int)($forecast['horizon_days'] ?? 7);
    $trend = $forecast['trend'] ?? 'flat';

    if (($result['kind'] ?? 'sales') === 'sales') {
        return "{$days}-day sales forecast: " . nxcb_money($forecast['projected_total'] ?? 0)
            . ' projected total, about ' . nxcb_money($forecast['projected_daily_average'] ?? 0)
            . " per day, with a {$trend} trend.";
    }

    $product = $result['product']['product_name'] ?? 'This product';
    return "{$product}: {$days}-day demand forecast is about "
        . number_format((float)($forecast['projected_total'] ?? 0), 0)
        . ' units total (' . number_format((float)($forecast['projected_daily_average'] ?? 0), 1)
        . " per day), with a {$trend} trend.";
}

function nxcb_format_system_guide(array $result): string {
    $guide = $result['guide'] ?? [];
    if (!is_array($guide) || !$guide) return 'I could not find a NexGen guide for that topic.';
    return implode("\n", array_map(static fn($line) => '• ' . $line, $guide));
}

function nxcb_format_open_module(array $result): string {
    $label = $result['label'] ?? 'that module';
    $marker = $result['open_marker'] ?? '';
    $reply = "I can open {$label} for you.";
    if ($marker !== '') $reply .= "\n{$marker}";
    return $reply;
}

function nxcb_format_tool_result(string $tool, array $result): string {
    if (empty($result['ok'])) return nxcb_tool_error_reply($result);

    switch ($tool) {
        case 'get_inventory_products':
            return nxcb_format_inventory_result($result);
        case 'get_product_history':
            return nxcb_format_product_history($result);
        case 'get_stock_activity':
            return nxcb_format_stock_activity($result);
        case 'get_sales_activity':
            return nxcb_format_sales_activity($result);
        case 'get_sales_summary':
            return nxcb_format_sales_summary($result);
        case 'get_product_performance':
            return nxcb_format_product_performance($result);
        case 'get_category_performance':
            return nxcb_format_category_performance($result);
        case 'get_receivables':
            return nxcb_format_receivables($result);
        case 'get_alerts':
            return nxcb_format_alerts($result);
        case 'forecast_business':
            return nxcb_format_forecast($result);
        case 'get_system_guide':
            return nxcb_format_system_guide($result);
        case 'open_module':
            return nxcb_format_open_module($result);
        default:
            return 'The NexGen request completed successfully.';
    }
}

function nxcb_execute_and_format(string $tool, array $args, mysqli $conn, array $ctx, array &$toolLog): array {
    $toolStarted = microtime(true);

    if (!function_exists('nxcb_execute_tool')) {
        $executorPath = __DIR__ . '/tool_executor.php';
        if (is_file($executorPath)) require_once $executorPath;
    }

    if (!function_exists('nxcb_execute_tool')) {
        error_log('[NexGen Chatbot] nxcb_execute_tool() is unavailable after dependency reload.');
        $result = ['ok' => false, 'error' => 'tool_unavailable', 'tool' => $tool];
    } else {
        $result = nxcb_execute_tool($tool, $args, $conn, $ctx);
    }

    $actualOk = !empty($result['ok']);
    $error = (string)($result['error'] ?? '');
    $softErrors = ['permission_denied', 'missing_query', 'missing_product_query', 'missing_customer_query', 'invalid_filter', 'unknown_module', 'invalid_workspace'];
    $handledOk = $actualOk || in_array($error, $softErrors, true);

    $toolLog[] = [
        'tool' => $tool,
        'ms' => round((microtime(true) - $toolStarted) * 1000, 1),
        'ok' => $actualOk,
    ];

    return [
        'ok' => $handledOk,
        'reply' => nxcb_format_tool_result($tool, $result),
        'raw' => $result,
    ];
}

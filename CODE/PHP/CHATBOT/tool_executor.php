<?php

/**
 * Central, defensive tool dispatcher.
 * Tool failures are converted to structured results instead of crashing chatbot.php.
 */
function nxcb_execute_tool(string $name, array $args, mysqli $conn, array $ctx): array {
    $handlerMap = [
        'get_inventory_products' => 'nxcb_inventory_products',
        'get_product_history' => 'nxcb_product_history',
        'get_stock_activity' => 'nxcb_stock_activity',
        'get_sales_activity' => 'nxcb_sales_activity',
        'get_sales_summary' => 'nxcb_sales_summary',
        'get_product_performance' => 'nxcb_product_performance',
        'get_category_performance' => 'nxcb_category_performance',
        'get_receivables' => 'nxcb_receivables',
        'get_alerts' => 'nxcb_alerts',
        'forecast_business' => 'nxcb_forecast_business',
        'get_system_guide' => 'nxcb_system_guide',
        'open_module' => 'nxcb_open_module',
    ];

    if (!isset($handlerMap[$name])) {
        return ['ok' => false, 'error' => 'unknown_tool', 'tool' => $name];
    }

    // Recheck permissions for every route, including follow-ups and direct calls.
    if (!nxcb_tool_allowed($name, $args, $ctx)) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }
    if (!in_array($name, ['get_system_guide', 'open_module'], true)
        && (int)($ctx['business_id'] ?? 0) <= 0) {
        return ['ok' => false, 'error' => 'invalid_workspace'];
    }

    $handler = $handlerMap[$name];
    if (!function_exists($handler)) {
        error_log("[NexGen Tool] handler missing tool={$name} function={$handler}");
        return ['ok' => false, 'error' => 'tool_unavailable', 'tool' => $name];
    }

    try {
        switch ($name) {
            case 'get_inventory_products':
                return nxcb_inventory_products($conn, $args, $ctx);
            case 'get_product_history':
                return nxcb_product_history($conn, $args, $ctx);
            case 'get_stock_activity':
                return nxcb_stock_activity($conn, $args, $ctx);
            case 'get_sales_activity':
                return nxcb_sales_activity($conn, $args, $ctx);
            case 'get_sales_summary':
                return nxcb_sales_summary($conn, $args, $ctx);
            case 'get_product_performance':
                return nxcb_product_performance($conn, $args, $ctx);
            case 'get_category_performance':
                return nxcb_category_performance($conn, $args, $ctx);
            case 'get_receivables':
                return nxcb_receivables($conn, $args, $ctx);
            case 'get_alerts':
                return nxcb_alerts($conn, $ctx);
            case 'forecast_business':
                return nxcb_forecast_business($conn, $args, $ctx);
            case 'get_system_guide':
                return nxcb_system_guide($args, $ctx);
            case 'open_module':
                return nxcb_open_module($args, $ctx);
        }
    } catch (Throwable $e) {
        error_log("[NexGen Tool] tool={$name} exception=" . $e->getMessage());
        return [
            'ok' => false,
            'error' => 'tool_exception',
            'tool' => $name,
        ];
    }

    return ['ok' => false, 'error' => 'unknown_tool', 'tool' => $name];
}

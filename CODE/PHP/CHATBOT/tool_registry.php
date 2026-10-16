<?php

/** One allowlist for PHP execution and permission-aware help. No model schemas. */
function nxcb_tool_allowed(string $tool, array $args, array $ctx): bool {
    $inventory = nxcb_permission($ctx, 'inventory');
    $sales = nxcb_permission($ctx, 'sales') || nxcb_permission($ctx, 'sales_analytics');
    $analytics = nxcb_permission($ctx, 'sales_analytics');
    $ar = nxcb_permission($ctx, 'accounts_receivable');
    switch ($tool) {
        case 'get_inventory_products':
        case 'get_stock_activity': return $inventory;
        case 'get_product_history': return $inventory && (($args['event'] ?? '') !== 'last_sale' || $sales);
        case 'get_sales_activity': return $sales;
        case 'get_sales_summary':
        case 'get_product_performance':
        case 'get_category_performance': return $analytics;
        case 'get_receivables': return $ar;
        case 'get_alerts': return $inventory || $analytics || $ar;
        case 'forecast_business': return ($args['kind'] ?? 'sales') === 'product' ? ($inventory && $sales) : $analytics;
        case 'get_system_guide':
            $permissions = ['inventory' => 'inventory', 'categories' => 'inventory', 'sales' => 'sales',
                'analytics' => 'sales_analytics', 'receivables' => 'accounts_receivable'];
            $topic = $args['topic'] ?? 'general';
            return !isset($permissions[$topic]) || nxcb_permission($ctx, $permissions[$topic]);
        case 'open_module':
            $permissions = ['inventory' => 'inventory', 'sales' => 'sales', 'analytics' => 'sales_analytics', 'receivables' => 'accounts_receivable'];
            $module = $args['module'] ?? 'dashboard';
            return !isset($permissions[$module]) || nxcb_permission($ctx, $permissions[$module]);
        default: return false;
    }
}

/** Examples double as quick-action buttons; <name> examples are input templates. */
function nxcb_suggestions(array $ctx, ?string $area = null): array {
    $groups = [
        'inventory' => ['Which items are low stock?', 'Show expired products', 'Find product <name>', 'Show stock history this month'],
        'sales' => ['Show my recent sales', 'How do I record a sale?'],
        'sales_analytics' => ['What are my sales today?', 'Top products this month', 'Forecast sales for 7 days'],
        'accounts_receivable' => ['Show overdue accounts', 'AR summary', 'Balance of customer <name>'],
    ];
    $suggestions = [];
    foreach ($groups as $permission => $questions) {
        if (($area === null || $area === $permission) && nxcb_permission($ctx, $permission)) {
            $suggestions = array_merge($suggestions, $questions);
        }
    }
    if ($area === null) {
        if (nxcb_tool_allowed('get_alerts', [], $ctx)) $suggestions[] = 'What alerts do I have today?';
        $suggestions[] = 'How do I use NexGen?';
    }
    return $suggestions;
}

function nxcb_help_reply(array $ctx, ?string $area = null): string {
    $questions = nxcb_suggestions($ctx, $area);
    if (!$questions) return 'This account does not have access to that module. Ask your owner or administrator if you need access.';
    return "You can ask:\n" . implode("\n", array_map(static fn($q) => '• ' . $q, $questions))
        . "\nReplace <name> with a product or customer name. I check the active business branch only.";
}

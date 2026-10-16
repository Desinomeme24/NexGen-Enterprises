<?php
/** CLI checks; no connection to your database and no changes to application data. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ob_start();
date_default_timezone_set('Asia/Manila');
$base = dirname(__DIR__) . '/CODE/PHP/CHATBOT/';
foreach (['data_helpers','tool_registry','conversation','fast_router','tools_inventory','tools_sales','tools_receivables','tools_system','tool_executor','response_formatter','agent'] as $file) require_once $base . $file . '.php';
$checks = 0;
function check($ok, string $description): void {
    global $checks;
    $checks++;
    if (!$ok) throw new RuntimeException('FAILED: ' . $description);
}
$ctx = ['business_id' => 11, 'user_id' => 7, 'role' => 'owner', 'php_base' => '/NexGen/CODE/PHP/',
    'permissions' => ['inventory' => true, 'sales' => true, 'sales_analytics' => true, 'accounts_receivable' => true]];
$cases = [
    ['What are my sales today?', 'get_sales_summary', ['period' => 'today']],
    ['Magkano ang benta kahapon?', 'get_sales_summary', ['period' => 'yesterday']],
    ['Magkano ang nabenta ko kahapon?', 'get_sales_summary', ['period' => 'yesterday']],
    ['What is my revenue this month?', 'get_sales_summary', ['period' => 'this_month']],
    ['What is COGS?', 'get_system_guide', ['topic' => 'analytics']],
    ['How do I record a sale?', 'get_system_guide', ['topic' => 'sales']],
    ['Paano mag record ng sales?', 'get_system_guide', ['topic' => 'sales']],
    ['Paano mag-record ng benta?', 'get_system_guide', ['topic' => 'sales']],
    ['How do I add a product?', 'get_system_guide', ['topic' => 'inventory']],
    ['Show my recent sales', 'get_sales_activity', ['period' => 'recent']],
    ['Show last 3 sales', 'get_sales_activity', ['limit' => 3]],
    ['Sales from 2026-09-01 to 2026-09-05', 'get_sales_summary', ['period' => 'custom', 'start_date' => '2026-09-01', 'end_date' => '2026-09-05']],
    ['Sales on 2026-08-01', 'get_sales_summary', ['period' => 'custom', 'start_date' => '2026-08-01', 'end_date' => '2026-08-01']],
    ['Show stock in this month', 'get_stock_activity', ['movement_type' => 'stock_in', 'period' => 'this_month']],
    ['Show stock-out kahapon', 'get_stock_activity', ['movement_type' => 'stock_out', 'period' => 'yesterday']],
    ['Show stock history', 'get_stock_activity', ['movement_type' => 'all', 'period' => 'recent']],
    ['Last stock in of Coffee', 'get_product_history', ['event' => 'last_stock_in', 'product_query' => 'Coffee']],
    ['Show last sale of Coffee', 'get_product_history', ['event' => 'last_sale', 'product_query' => 'Coffee']],
    ['Show expired products', 'get_inventory_products', ['filter' => 'expired']],
    ['What products are expired?', 'get_inventory_products', ['filter' => 'expired']],
    ['Alin ang expired na produkto?', 'get_inventory_products', ['filter' => 'expired']],
    ['Ano ang mga produktong paso na?', 'get_inventory_products', ['filter' => 'expired']],
    ['Mga produktong lumampas na sa expiration', 'get_inventory_products', ['filter' => 'expired']],
    ['What products are about to expire?', 'get_inventory_products', ['filter' => 'expiring', 'days' => 30]],
    ['Which items are low stock?', 'get_inventory_products', ['filter' => 'low_stock']],
    ['Alin ang mababa ang stock?', 'get_inventory_products', ['filter' => 'low_stock']],
    ['Do I have out-of-stock products?', 'get_inventory_products', ['filter' => 'out_of_stock']],
    ['Alin ang walang stock?', 'get_inventory_products', ['filter' => 'out_of_stock']],
    ['Ano ang mga ubos na produkto?', 'get_inventory_products', ['filter' => 'out_of_stock']],
    ['Products expiring in 7 days', 'get_inventory_products', ['filter' => 'expiring', 'days' => 7]],
    ['Show products near reorder level', 'get_inventory_products', ['filter' => 'near_reorder']],
    ['Show on order products', 'get_inventory_products', ['filter' => 'on_order']],
    ['Show latest 3 products', 'get_inventory_products', ['filter' => 'recently_added', 'limit' => 3]],
    ['Find product Coffee', 'get_inventory_products', ['filter' => 'search', 'query' => 'Coffee']],
    ['Hanapin ang produktong Coffee', 'get_inventory_products', ['filter' => 'search', 'query' => 'Coffee']],
    ['Find product Sales Soap', 'get_inventory_products', ['filter' => 'search', 'query' => 'Sales Soap']],
    ['Find product Expired Label', 'get_inventory_products', ['filter' => 'search', 'query' => 'Expired Label']],
    ['Stock of Coffee', 'get_inventory_products', ['filter' => 'search', 'query' => 'Coffee']],
    ['How many Coffee do I have?', 'get_inventory_products', ['filter' => 'search', 'query' => 'Coffee']],
    ['Ilan ang stock ng Coffee?', 'get_inventory_products', ['filter' => 'search', 'query' => 'Coffee']],
    ['AR summary', 'get_receivables', ['view' => 'summary']],
    ['Show overdue accounts', 'get_receivables', ['view' => 'overdue']],
    ['Show unpaid accounts', 'get_receivables', ['view' => 'unpaid']],
    ['Which customer account should I follow up first?', 'get_receivables', ['view' => 'followup_priority']],
    ['Sino ang dapat kong i-follow up muna?', 'get_receivables', ['view' => 'followup_priority']],
    ['Largest receivable', 'get_receivables', ['view' => 'largest_balance']],
    ['Balance of customer Ana Cruz', 'get_receivables', ['view' => 'customer_search', 'customer_query' => 'Ana Cruz']],
    ['Utang ni Ana Cruz', 'get_receivables', ['view' => 'customer_search', 'customer_query' => 'Ana Cruz']],
    ['Find customer Overdue Store', 'get_receivables', ['view' => 'customer_search', 'customer_query' => 'Overdue Store']],
    ['Top 10 products this month by revenue', 'get_product_performance', ['metric' => 'top_revenue', 'limit' => 10]],
    ['Pinakamabenta ngayong buwan', 'get_product_performance', ['metric' => 'top_quantity', 'period' => 'this_month']],
    ['Pinakamabentang produkto ngayong buwan', 'get_product_performance', ['metric' => 'top_quantity', 'period' => 'this_month']],
    ['Slow-moving products', 'get_product_performance', ['metric' => 'slow_moving']],
    ['Products with no sales in 30 days', 'get_product_performance', ['metric' => 'no_recent_sales', 'days_without_sales' => 30]],
    ['Products with highest COGS', 'get_product_performance', ['metric' => 'highest_cogs']],
    ['Lowest category performance by revenue', 'get_category_performance', ['metric' => 'revenue', 'order' => 'lowest']],
    ['Ipakita ang pinakamababang performance ng category', 'get_category_performance', ['metric' => 'units', 'order' => 'lowest', 'period' => 'this_month']],
    ['What alerts do I have today?', 'get_alerts', []],
    ['Forecast sales for 7 days', 'forecast_business', ['kind' => 'sales', 'horizon_days' => 7]],
    ['Forecast demand for Coffee for 7 days', 'forecast_business', ['kind' => 'product', 'product_query' => 'Coffee', 'horizon_days' => 7]],
    ['I-forecast ang demand ng Coffee sa loob ng 7 araw', 'forecast_business', ['kind' => 'product', 'product_query' => 'Coffee', 'horizon_days' => 7]],
    ['Forecast sales sa loob ng 2 linggo', 'forecast_business', ['kind' => 'sales', 'horizon_days' => 14]],
    ['Open inventory', 'open_module', ['module' => 'inventory']],
    ['Buksan ang AR', 'open_module', ['module' => 'receivables']],
];
foreach ($cases as [$question, $tool, $args]) {
    $actual = nxcb_fast_route($question, $ctx);
    check(($actual['tool'] ?? '') === $tool, $question . ' tool: ' . json_encode($actual));
    foreach ($args as $key => $expected) check(($actual['args'][$key] ?? null) === $expected, $question . ' argument ' . $key . ': ' . json_encode($actual));
}
foreach ([
    ['Magandang umaga', 'rule-direct'],
    ['Ano ang pwede mong gawin?', 'rule-direct'],
    ['Maraming salamat', 'rule-direct'],
] as [$question, $expectedRoute]) {
    $result = nxcb_run_agent(new mysqli(), $question, [], $ctx);
    check(($result['route'] ?? '') === $expectedRoute, 'Direct reply: ' . $question);
}
$suggestion = nxcb_run_agent(new mysqli(), 'Mababa?', [], $ctx);
check($suggestion['route'] === 'rule-clarify'
    && $suggestion['reply'] === "Did you mean: 'Ipakita ang mga produktong mababa ang stock'?"
    && !$suggestion['tool_log'], 'Ambiguous inventory wording gets a suggestion');
$suggestion = nxcb_run_agent(new mysqli(), 'Expired?', [], $ctx);
check($suggestion['reply'] === "Did you mean: 'Ipakita ang mga expired na produkto'?", 'Ambiguous expiry wording gets a suggestion');
$safety = nxcb_run_agent(new mysqli(), 'Delete all sales', [], $ctx);
check($safety['route'] === 'rule-clarify'
    && str_contains($safety['reply'], 'To change records')
    && !$safety['tool_log'], 'Destructive requests are refused without a tool call');
foreach (['Sales on 2026-02-30', 'Sales from 2026-09-05 to 2026-09-01', 'Sales today and yesterday', 'Sales in August 2026', 'Sales on 9/5/2026', 'Sales tomorrow', 'Sales since 2026-09-01', 'Compare sales this month vs last month', 'Delete all sales', 'Low stock last month', 'Overdue accounts yesterday', 'Show sales for branch East', 'Forecast sales next month'] as $q) {
    check(isset(nxcb_fast_route($q, $ctx)['reply']), 'Clarify without data query: ' . $q);
}
$history = [['role' => 'user', 'content' => 'Top products this month', 'last_route' => nxcb_fast_route('Top products this month', $ctx)]];
$r = nxcb_fast_followup_route('What about last month?', $history, $ctx);
check(($r['tool'] ?? '') === 'get_product_performance' && $r['args']['period'] === 'last_month', 'Follow-up preserves metric');
$history[] = ['role' => 'user', 'content' => 'What about last month?', 'last_route' => $r];
$r = nxcb_fast_followup_route('And yesterday?', $history, $ctx);
check(($r['args']['period'] ?? '') === 'yesterday', 'Chained follow-up');
check(nxcb_fast_followup_route('What is the weather today?', $history, $ctx) === null, 'Unrelated dated question is not a follow-up');
$history[] = ['role' => 'user', 'content' => 'hello', 'last_route' => null];
check(isset(nxcb_fast_followup_route('What about last month?', $history, $ctx)['reply']), 'Do not jump past a topic change');
$history = [['role' => 'user', 'last_route' => nxcb_fast_route('Sales on 2026-08-01', $ctx)]];
$r = nxcb_fast_followup_route('And today?', $history, $ctx);
check(!isset($r['args']['start_date']) && $r['args']['period'] === 'today', 'Remove obsolete custom dates');

$conn = new mysqli(); // Unconnected: denied/direct paths must not query a database.
$none = $ctx; $none['permissions'] = [];
foreach (['get_inventory_products','get_product_history','get_stock_activity','get_sales_activity','get_sales_summary','get_product_performance','get_category_performance','get_receivables','get_alerts','forecast_business'] as $tool) {
    check((nxcb_execute_tool($tool, [], $conn, $none)['error'] ?? '') === 'permission_denied', 'Deny ' . $tool . ' without DB access');
}
check((nxcb_execute_tool('DROP TABLE sales', [], $conn, $ctx)['error'] ?? '') === 'unknown_tool', 'Only allow named tools');
$inv = $none; $inv['permissions']['inventory'] = true;
check(!nxcb_tool_allowed('get_product_history', ['event' => 'last_sale'], $inv), 'Inventory-only account cannot read sale history');
check(!nxcb_tool_allowed('forecast_business', ['kind' => 'product'], $inv), 'Inventory-only account cannot infer sales from forecasts');
check(!str_contains(implode(' ', nxcb_suggestions($inv)), 'overdue'), 'No AR suggestions without AR access');
check(!str_contains(nxcb_fast_direct_reply('help', $none), 'Top products'), 'Help respects permissions');
check((nxcb_execute_tool('get_sales_summary', [], $conn, array_merge($ctx, ['business_id' => 0]))['error'] ?? '') === 'invalid_workspace', 'Reject missing workspace');
$employee = $none; $employee['permissions']['sales'] = true;
$guide = nxcb_execute_tool('get_system_guide', ['topic' => 'sales'], $conn, $employee);
check(str_contains(implode(' ', $guide['guide']), 'records paid sales'), 'Sales FAQ without AR');
$guide = nxcb_execute_tool('get_system_guide', ['topic' => 'sales'], $conn, $ctx);
check(str_contains(implode(' ', $guide['guide']), 'Unpaid or partially paid'), 'Sales FAQ with AR');
$reply = nxcb_run_agent($conn, 'How is the weather today?', [], $ctx);
check($reply['ok'] && $reply['route'] === 'rule-clarify' && !$reply['tool_log'], 'Unsupported wording returns immediately');
$reply = nxcb_run_agent($conn, 'Show overdue accounts', [], $inv);
check($reply['ok'] && str_contains($reply['reply'], 'permission') && $reply['last_route'] === null, 'Denied request cannot seed history');
$module = nxcb_open_module(['module' => 'sales'], $ctx);
check($module['url'] === '/NexGen/CODE/PHP/sales_recording.php', 'XAMPP navigation URL');
$module = nxcb_open_module(['module' => 'sales'], array_merge($ctx, ['php_base' => '/']));
check($module['url'] === '/sales_recording.php', 'Root-hosted navigation URL');

session_save_path(sys_get_temp_dir());
session_start();
$_SESSION = ['user_id' => 7, 'business_id' => 11, 'role' => 'owner'];
foreach ($ctx['permissions'] as $key => $value) $_SESSION['can_' . $key] = (int)$value;
nxcb_save_turn('Sales today', 'Fixture response', 6, $ctx, nxcb_fast_route('Sales today', $ctx));
session_start();
check(count(nxcb_load_history(6, $ctx)) === 2, 'Scoped history saved');
$branch2 = array_merge($ctx, ['business_id' => 22]);
$_SESSION['business_id'] = 22;
check(nxcb_load_history(6, $branch2) === [], 'Branch switch resets history');
nxcb_save_turn('Late answer', 'Old branch', 6, $ctx, null);
session_start();
check(nxcb_load_history(6, $branch2) === [], 'Late save cannot overwrite new branch history');
check(nxcb_context_key($ctx) !== nxcb_context_key($inv), 'Permissions participate in history scope');
check(nxcb_context_key($ctx) !== nxcb_context_key(array_merge($ctx, ['user_id' => 8])), 'User participates in history scope');
session_destroy();
ob_end_clean();
echo "PASS: {$checks} routing, permissions, history and navigation assertions.\n";

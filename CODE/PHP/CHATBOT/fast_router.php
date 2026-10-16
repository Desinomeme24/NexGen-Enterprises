<?php

/** Deterministic intent matching. Database access belongs exclusively to tools. */
function nxcb_text_lower(string $text): string {
    return trim(preg_replace('/\s+/u', ' ', mb_strtolower($text, 'UTF-8')) ?? $text);
}

function nxcb_normalize_question(string $text): string {
    $text = nxcb_text_lower($text);
    $text = str_replace(['–', '—', '_'], '-', $text);
    $text = preg_replace('/\b(?:please|pakisuyo|paki)\s+/u', '', $text) ?? $text;
    $text = preg_replace('/\bmag[- ]?record\b/u', 'record', $text) ?? $text;
    $text = preg_replace('/\b(?:mag[- ]?forecast|i[- ]?forecast)\b/u', 'forecast', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function nxcb_contains_any(string $text, array $needles): bool {
    foreach ($needles as $needle) {
        if ($needle !== '' && preg_match('/(?<![\pL\pN])' . preg_quote($needle, '/') . '(?![\pL\pN])/ui', $text)) return true;
    }
    return false;
}

function nxcb_period_phrases(): array {
    return [
        'yesterday' => ['yesterday', 'kahapon'],
        'last_week' => ['last week', 'nakaraang linggo'],
        'this_week' => ['this week', 'ngayong linggo'],
        'last_month' => ['last month', 'nakaraang buwan'],
        'this_month' => ['this month', 'ngayong buwan'],
        'last_year' => ['last year', 'nakaraang taon'],
        'this_year' => ['this year', 'ngayong taon'],
        'today' => ['today', 'ngayon', 'ngayong araw'],
    ];
}

function nxcb_period_from_question(string $question): ?string {
    foreach (nxcb_period_phrases() as $period => $phrases) {
        if (nxcb_contains_any(nxcb_text_lower($question), $phrases)) return $period;
    }
    return null;
}

function nxcb_extract_days(string $question, int $default, int $min = 1, int $max = 365): int {
    if (preg_match('/\b(\d+)\s*(?:days?|araw|linggo|weeks?)\b/ui', $question, $m)) {
        $days = preg_match('/(?:linggo|weeks?)$/ui', $m[0]) ? (int)$m[1] * 7 : (int)$m[1];
        return max($min, min($max, $days));
    }
    return $default;
}

function nxcb_extract_limit(string $question, int $default = 10, int $max = 20): int {
    if (preg_match('/\b(?:top|last|latest|recent|show|list|first)\s+(\d+)\b/ui', $question, $m)) {
        return max(1, min($max, (int)$m[1]));
    }
    return $default;
}

function nxcb_clean_query(string $query): string {
    $query = trim($query, " \t\n\r\0\x0B?!.,\"'");
    $query = preg_replace('/^(?:ang|mga|the)\s+(?:produktong?|product)\s+/ui', '', $query) ?? $query;
    return $query !== '' && mb_strlen($query, 'UTF-8') <= 100 && !str_contains($query, '<name>') ? $query : '';
}

function nxcb_extract_product_query(string $question): string {
    $patterns = [
        '/\b(?:last\s+(?:stock[ -]?in|restock|sale)\s+(?:of|for)\s+)(.+)$/ui',
        '/\b(?:stock|quantity)\s+(?:of|for|ng)\s+(.+)$/ui',
        '/\b(?:find|search(?:\s+for)?|look\s+up|hanapin)\s+(?:product\s+)?(.+)$/ui',
        '/\bhow\s+many\s+(.+?)\s+(?:do\s+i\s+have|are\s+in\s+stock|in\s+stock)[?.! ]*$/ui',
        '/\bilan(?:g)?\s+(?:ang\s+)?(?:stock\s+(?:ng|natin\s+na)\s+)?(.+?)(?:\s+ang\s+(?:natitira|meron))?[?.! ]*$/ui',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $question, $m)) return nxcb_clean_query($m[1]);
    }
    return '';
}

/** Validate periods before attaching them to a live-data request. */
function nxcb_date_args(string $question): array {
    $q = nxcb_text_lower($question);
    $named = [];
    foreach (nxcb_period_phrases() as $period => $phrases) {
        if (nxcb_contains_any($q, $phrases)) $named[] = $period;
    }
    preg_match_all('/\b\d{4}-\d{1,2}-\d{1,2}\b/u', $q, $matches);
    $dates = $matches[0];
    if (count($named) > 1 || ($named && $dates) || count($dates) > 2) {
        return ['reply' => 'Please ask for one period at a time, such as sales this month or sales from 2026-09-01 to 2026-09-05.'];
    }
    if ($dates) {
        $start = nxcb_valid_date($dates[0]);
        $end = nxcb_valid_date($dates[1] ?? $dates[0]);
        if (!$start || !$end || $start > $end) return ['reply' => 'Use valid dates in YYYY-MM-DD format, with the start date on or before the end date.'];
        if (count($dates) === 1 && preg_match('/\b(since|before|after|until|from|between)\b/ui', $q)) {
            return ['reply' => 'Please provide both dates, for example: sales from 2026-09-01 to 2026-09-05.'];
        }
        return ['period' => 'custom', 'start_date' => $start, 'end_date' => $end];
    }
    // Never silently answer for today when an unsupported date was requested.
    if (preg_match('/\b(?:january|february|march|april|may|june|july|august|september|october|november|december|jan|feb|mar|apr|jun|jul|aug|sep|sept|oct|nov|dec)\s+\d|\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b|\b(?:19|20)\d{2}\b|\b(?:tomorrow|bukas|next week|next month|next year|last\s+\d+\s+days?)\b/ui', $q)) {
        return ['reply' => 'For dated records, use today, yesterday, this/last week, month or year, or YYYY-MM-DD dates. For future estimates, ask for a sales forecast.'];
    }
    return $named ? ['period' => $named[0]] : [];
}

function nxcb_fast_direct_reply(string $question, array $ctx = []): ?string {
    $q = nxcb_normalize_question($question);
    if (preg_match('/^(hi|hello|hey|hello there|good morning|good afternoon|good evening|kumusta|kamusta|magandang umaga|magandang hapon|magandang gabi)[!. ]*$/ui', $q)) {
        return "Hi! I’m your NexGen Assistant.\n" . nxcb_help_reply($ctx);
    }
    if (preg_match('/^(thanks|thank you|salamat|maraming salamat|ty)[!. ]*$/ui', $q)) return 'You’re welcome!';
    if (preg_match('/^(help|menu|what can you do|ano ang kaya mo|ano kaya mo|ano ang pwede mong gawin)[?.! ]*$/ui', $q)) return nxcb_help_reply($ctx);
    $areas = [
        'inventory' => '/^(inventory|stock|products?|items?|imbentaryo)[?.! ]*$/ui',
        'sales' => '/^(sales|sale|benta)[?.! ]*$/ui',
        'sales_analytics' => '/^(analytics|sales analytics|reports?|ulat)[?.! ]*$/ui',
        'accounts_receivable' => '/^(receivables?|accounts receivable|ar|utang|singilan)[?.! ]*$/ui',
    ];
    foreach ($areas as $area => $pattern) {
        if (preg_match($pattern, $q)) return nxcb_help_reply($ctx, $area);
    }
    return null;
}

function nxcb_clarification_suggestion(string $question): ?string {
    $q = nxcb_normalize_question($question);
    $suggestions = [
        '/^(?:mababa|mababa ang stock|low|low stock)[?.! ]*$/ui'
            => 'Ipakita ang mga produktong mababa ang stock',
        '/^(?:expired|paso|paso na|nag-expire)[?.! ]*$/ui'
            => 'Ipakita ang mga expired na produkto',
        '/^(?:ubos|out of stock|walang stock)[?.! ]*$/ui'
            => 'Ipakita ang mga produktong walang stock',
        '/^(?:benta|sales)[?.! ]*$/ui'
            => 'Magkano ang benta ngayong araw?',
        '/^(?:utang|receivable|receivables|ar)[?.! ]*$/ui'
            => 'Ipakita ang mga overdue accounts',
    ];
    foreach ($suggestions as $pattern => $example) {
        if (preg_match($pattern, $q)) {
            return "Did you mean: '{$example}'?";
        }
    }
    return null;
}

function nxcb_domain_clarification(string $question, array $ctx = []): string {
    $q = nxcb_normalize_question($question);
    $suggestion = nxcb_clarification_suggestion($q);
    if ($suggestion !== null) return $suggestion;
    $areas = [
        'accounts_receivable' => ['receivable', 'receivables', 'customer', 'overdue', 'unpaid', 'utang', 'singil', 'balance', 'payment'],
        'sales_analytics' => ['analytics', 'profit', 'cogs', 'forecast', 'tantya', 'performance'],
        'inventory' => ['stock', 'inventory', 'imbentaryo', 'product', 'products', 'produkto', 'item', 'items', 'restock'],
        'sales' => ['sales', 'sale', 'transaction', 'transactions', 'benta'],
    ];
    foreach ($areas as $area => $words) {
        if (nxcb_contains_any($q, $words)) return "I need a more specific request.\n" . nxcb_help_reply($ctx, $area);
    }
    return "I can answer supported NexGen questions. Choose a suggestion or try one of these:\n" . nxcb_help_reply($ctx);
}

/** Identify one supported operation; ambiguous/unsupported requests never execute SQL. */
function nxcb_intent_route(string $question, array $ctx): ?array {
    $q = nxcb_normalize_question($question);
    $period = nxcb_period_from_question($q);
    $limit = nxcb_extract_limit($q);
    $route = static fn(string $tool, array $args = []) => ['tool' => $tool, 'args' => $args];

    if (preg_match('/\b(open|go to|goto|navigate to|take me to|buksan|puntahan)\b/ui', $q)) {
        $modules = ['analytics' => ['sales analytics', 'analytics'], 'receivables' => ['accounts receivable', 'receivables', 'receivable', 'ar'],
            'inventory' => ['inventory'], 'sales' => ['sales', 'sale'], 'settings' => ['settings'], 'about' => ['about us'], 'dashboard' => ['dashboard', 'home']];
        foreach ($modules as $module => $words) if (nxcb_contains_any($q, $words)) return $route('open_module', ['module' => $module]);
    }

    // How-to questions are distinct from "What is my revenue today?".
    $how = preg_match('/\b(how do i|how to|how can i|where do i|where can i|steps? to|paano|saan ko|explain|meaning of|what does)\b/ui', $q)
        || preg_match('/^(?:what is|what are|ano ang|ano yung)\s+(?:the\s+)?(?:cogs|net profit|gross revenue|accounts receivable|nexgen|inventory|reorder level)[?.! ]*$/ui', $q);
    if ($how) {
        $topics = ['analytics' => ['analytics', 'report', 'reports', 'cogs', 'profit', 'revenue'],
            'receivables' => ['receivable', 'receivables', 'payment', 'customer balance', 'utang'],
            'categories' => ['category', 'categories'], 'sales' => ['sale', 'sales', 'benta'],
            'inventory' => ['inventory', 'stock', 'product', 'products'], 'security' => ['security', 'permission', 'permissions', 'login', 'password'],
            'navigation' => ['navigate', 'module', 'menu', 'branch', 'branches', 'workspace'], 'general' => ['nexgen', 'system', 'assistant']];
        foreach ($topics as $topic => $words) if (nxcb_contains_any($q, $words)) return $route('get_system_guide', ['topic' => $topic]);
    }
    if (preg_match('/^(?:please\s+)?(?:add|delete|remove|update|edit|save|record|create|pay|approve|reject|change|transfer|switch|magdagdag|burahin|baguhin)\b/ui', $q)) {
        return ['reply' => 'I can look up records and explain the steps. To change records or switch branches, use the corresponding NexGen module.'];
    }
    if (preg_match('/\b(compare|versus|vs\.?|difference between)\b/ui', $q)) {
        return ['reply' => 'Please request each period separately, for example “sales this month” followed by “what about last month?”.'];
    }

    // Specific names are extracted before broad words such as expired, sales or overdue.
    if (preg_match('/\b(?:balance\s+(?:of|for)|receivables?\s+for|utang\s+ni)\s+(?:customer\s+)?(.+)$/ui', $question, $m)
        || preg_match('/\b(?:find|search)\s+customer\s+(.+)$/ui', $question, $m)) {
        return $route('get_receivables', ['view' => 'customer_search', 'customer_query' => nxcb_clean_query($m[1]), 'limit' => $limit]);
    }
    if (preg_match('/\b(?:last stock[ -]?in|last restock|last sale)\s+(?:of|for)\b/ui', $q)) {
        return $route('get_product_history', ['event' => str_contains($q, 'last sale') ? 'last_sale' : 'last_stock_in', 'product_query' => nxcb_extract_product_query($question)]);
    }
    if (preg_match('/^(?:please\s+)?(?:find|search(?: for)?|look up|hanapin)\s+(?:product\s+)?/ui', $q)
        || preg_match('/\b(?:stock|quantity)\s+(?:of|for|ng)\s+/ui', $q)
        || preg_match('/\bhow many .+ (?:do i have|are in stock|in stock)[?.! ]*$/ui', $q)
        || preg_match('/^ilan(?:g)?\b/ui', $q)) {
        return $route('get_inventory_products', ['filter' => 'search', 'query' => nxcb_extract_product_query($question), 'limit' => min($limit, 20)]);
    }
    if (nxcb_contains_any($q, ['alert', 'alerts', 'warning', 'warnings', 'notifications', 'what needs attention', 'what should i focus on', 'anything urgent', 'urgent today', 'what should i prioritize today'])) {
        return $route('get_alerts');
    }
    if (nxcb_contains_any($q, ['stock history', 'stock movement', 'stock movements', 'inventory history', 'stock activity', 'inventory movement', 'movement history', 'stock in', 'stock-in', 'stock out', 'stock-out', 'restock activity'])) {
        $movement = nxcb_contains_any($q, ['stock out', 'stock-out', 'outgoing']) ? 'stock_out'
            : (nxcb_contains_any($q, ['stock in', 'stock-in', 'restock', 'incoming']) ? 'stock_in' : 'all');
        return $route('get_stock_activity', ['movement_type' => $movement, 'period' => $period ?? 'recent', 'limit' => $limit]);
    }
    $filters = [
        'expiring' => ['expiring', 'expire soon', 'soon to expire', 'about to expire', 'malapit mag-expire', 'malapit ma-expire', 'malapit mag expire', 'malapit nang mag-expire', 'malapit nang ma-expire'],
        'expired' => ['expired', 'expire na', 'expired na', 'nag-expire na', 'paso na', 'paso nang', 'lumampas na sa expiration', 'lampas na sa expiration'],
        'out_of_stock' => ['out of stock', 'out-of-stock', 'no stock', 'zero stock', 'walang stock', 'ubos na stock', 'ubos na ang stock', 'ubos na'],
        'low_stock' => ['low stock', 'low-stock', 'mababang stock', 'mababa ang stock', 'kulang stock', 'kaunti na ang stock', 'konti na ang stock'],
        'near_reorder' => ['near reorder', 'reorder level'], 'on_order' => ['on order', 'on-order', 'incoming stock', 'ordered stock'],
        'recently_added' => ['recently added products', 'recently added product', 'newly added products', 'new products', 'latest products', 'newest products'],
    ];
    foreach ($filters as $filter => $words) {
        if (nxcb_contains_any($q, $words) || ($filter === 'recently_added' && preg_match('/\b(?:latest|newest|last)\s+\d+\s+products?\b/ui', $q))) {
            $args = ['filter' => $filter, 'limit' => $limit];
            if ($filter === 'expiring') $args['days'] = nxcb_extract_days($q, 30);
            return $route('get_inventory_products', $args);
        }
    }
    $views = [
        'followup_priority' => ['follow up first', 'follow-up first', 'followup first', 'follow up priority', 'collection priority', 'priority account', 'which customer account should i follow', 'sino ang uunahin', 'sino ang dapat kong i-follow up', 'dapat i-follow up'],
        'largest_balance' => ['largest balance', 'highest balance', 'biggest balance', 'largest receivable', 'pinakamalaking utang'],
        'overdue' => ['overdue', 'past due'],
        'summary' => ['receivable summary', 'accounts receivable summary', 'ar summary', 'receivables summary', 'total receivables'],
        'unpaid' => ['unpaid', 'outstanding receivables', 'outstanding accounts', 'outstanding balances', 'hindi pa bayad', 'may utang'],
    ];
    foreach ($views as $view => $words) {
        if (nxcb_contains_any($q, $words)) return $route('get_receivables', ['view' => $view, 'limit' => $view === 'largest_balance' ? 1 : $limit]);
    }
    if (nxcb_contains_any($q, ['forecast', 'predict', 'projection', 'projected', 'tantya', 'tantiya'])) {
        if (preg_match('/\b(?:next month|next week|next year|tomorrow|bukas|\d{4}-\d{1,2}-\d{1,2})\b/ui', $q)) {
            return ['reply' => 'Please specify a forecast horizon from 1 to 30 days, for example “forecast sales for 7 days”.'];
        }
            if (preg_match('/\b(?:forecast|predict)\s+(?:demand\s+for|product)\s+(.+?)(?:\s+for\s+(?:the\s+next\s+)?\d+\s+days?)?[?.! ]*$/ui', $question, $m)
                || preg_match('/\b(?:i-forecast|forecast)\s+(?:ang\s+)?demand\s+ng\s+(.+?)(?:\s+sa\s+loob\s+ng\s+\d+\s+araw)?[?.! ]*$/ui', $question, $m)) {
                return $route('forecast_business', ['kind' => 'product', 'product_query' => nxcb_clean_query($m[1]), 'horizon_days' => nxcb_extract_days($q, 7, 1, 30)]);
            }
        if (nxcb_contains_any($q, ['sales', 'revenue', 'business', 'benta', 'kita'])) {
            return $route('forecast_business', ['kind' => 'sales', 'horizon_days' => nxcb_extract_days($q, 7, 1, 30)]);
        }
        return ['reply' => 'Try “forecast sales for 7 days” or “forecast demand for <product name> for 7 days”.'];
    }
    if (nxcb_contains_any($q, ['category', 'categories']) && nxcb_contains_any($q, ['performance', 'top', 'best', 'highest', 'lowest', 'weakest', 'least', 'pinakamababa', 'pinakamababang', 'revenue', 'units', 'sold'])) {
        return $route('get_category_performance', ['metric' => nxcb_contains_any($q, ['revenue', 'sales amount', 'peso']) ? 'revenue' : 'units',
            'order' => nxcb_contains_any($q, ['lowest', 'weakest', 'least', 'worst', 'pinakamababa', 'pinakamababang']) ? 'lowest' : 'highest',
            'period' => $period ?? 'this_month', 'limit' => nxcb_extract_limit($q, 5)]);
    }
    if (nxcb_contains_any($q, ['top products', 'top product', 'best selling', 'best-selling', 'top selling', 'pinakamabenta', 'pinakamabentang', 'mabentang produkto']) || preg_match('/\btop\s+\d+\s+products?\b/ui', $q)) {
        return $route('get_product_performance', ['metric' => nxcb_contains_any($q, ['revenue', 'sales amount', 'peso']) ? 'top_revenue' : 'top_quantity', 'period' => $period ?? 'this_month', 'limit' => nxcb_extract_limit($q, 5, 10)]);
    }
    if (nxcb_contains_any($q, ['slow moving', 'slow-moving', 'mabagal mabenta', 'mabagal ang benta', 'hindi mabenta'])) return $route('get_product_performance', ['metric' => 'slow_moving', 'period' => $period ?? 'this_month', 'limit' => nxcb_extract_limit($q, 5, 10)]);
    if (nxcb_contains_any($q, ['no recent sales', 'not selling', 'no sales recently', 'no sales in', 'walang benta', 'walang nabebenta'])) return $route('get_product_performance', ['metric' => 'no_recent_sales', 'days_without_sales' => nxcb_extract_days($q, 30), 'limit' => nxcb_extract_limit($q, 5, 10)]);
    if (nxcb_contains_any($q, ['highest cogs', 'most cogs'])) return $route('get_product_performance', ['metric' => 'highest_cogs', 'period' => $period ?? 'this_month', 'limit' => nxcb_extract_limit($q, 5, 10)]);
    if (nxcb_contains_any($q, ['sales movement', 'sales history', 'sale history', 'recent sales', 'recent transactions', 'sales activity', 'transaction history', 'show my sales', 'show sales', 'list sales']) || preg_match('/\b(?:latest|recent|last)\s+(?:\d+\s+)?(?:sales|transactions)\b/ui', $q)) {
        return $route('get_sales_activity', ['period' => $period ?? 'recent', 'limit' => $limit]);
    }
    if (nxcb_contains_any($q, ['sales', 'revenue', 'profit', 'cogs', 'transaction count', 'transactions today', 'how much did i sell', 'benta', 'nabenta', 'kita'])) {
        return $route('get_sales_summary', ['period' => $period ?? 'today']);
    }
    return null;
}

function nxcb_fast_route(string $question, array $ctx): ?array {
    $suggestion = nxcb_clarification_suggestion($question);
    if ($suggestion !== null) return ['reply' => $suggestion];
    $route = nxcb_intent_route($question, $ctx);
    if (!$route || isset($route['reply'])) return $route;
    $tool = $route['tool'];
    $isNamedLookup = ($tool === 'get_inventory_products' && ($route['args']['filter'] ?? '') === 'search')
        || ($tool === 'get_receivables' && ($route['args']['view'] ?? '') === 'customer_search')
        || in_array($tool, ['get_product_history', 'forecast_business'], true);
    if (!$isNamedLookup && !in_array($tool, ['get_system_guide', 'open_module'], true)
        && preg_match('/\b(?:for|in|from|across|all)\s+(?:the\s+)?(?:branch(?:es)?|businesses|business)\b/ui', $question)) {
        return ['reply' => 'I check the active business branch only. Select the branch in NexGen’s workspace selector, then ask your question again.'];
    }
    if (!$isNamedLookup && in_array($tool, ['get_inventory_products', 'get_receivables', 'get_alerts'], true)) {
        $dates = nxcb_date_args($question);
        if (isset($dates['reply'])) return $dates;
        if (isset($dates['period']) && $dates['period'] !== 'today') {
            return ['reply' => 'That request shows current records only. For past activity, ask for sales history or stock history with a date range.'];
        }
    }
    if (in_array($tool, ['get_sales_summary', 'get_sales_activity', 'get_product_performance', 'get_category_performance', 'get_stock_activity'], true)) {
        if (($route['args']['metric'] ?? '') === 'no_recent_sales') {
            if (nxcb_period_from_question($question) !== null || preg_match('/\b\d{4}-\d{2}-\d{2}\b/u', $question)) {
                return ['reply' => 'For products without recent sales, specify a rolling number of days, for example “products with no sales in 30 days”.'];
            }
        } else {
            $dates = nxcb_date_args($question);
            if (isset($dates['reply'])) return $dates;
            $route['args'] = array_merge($route['args'], $dates);
        }
    }
    return $route;
}

/** Only an explicit period-only follow-up may reuse the immediately preceding tool. */
function nxcb_fast_followup_route(string $question, array $history, array $ctx): ?array {
    $q = trim(nxcb_text_lower($question), " ?!.");
    $q = preg_replace('/^(?:what about|how about|and|also|paano naman|paano kung|eh kung|at)\s+/ui', '', $q);
    $phrases = array_merge(...array_values(nxcb_period_phrases()));
    if (!in_array($q, $phrases, true) && !preg_match('/^(?:on\s+)?\d{4}-\d{1,2}-\d{1,2}$/u', $q)
        && !preg_match('/^(?:from\s+)?\d{4}-\d{1,2}-\d{1,2}\s+to\s+\d{4}-\d{1,2}-\d{1,2}$/u', $q)) return null;
    $dates = nxcb_date_args($q);
    if (isset($dates['reply'])) return $dates;
    foreach (array_reverse($history) as $message) {
        if (($message['role'] ?? '') !== 'user') continue;
        $previous = $message['last_route'] ?? null;
        if (!is_array($previous) || !in_array($previous['tool'] ?? '', ['get_sales_summary', 'get_sales_activity', 'get_product_performance', 'get_category_performance', 'get_stock_activity'], true)
            || ($previous['args']['metric'] ?? '') === 'no_recent_sales') break;
        unset($previous['args']['start_date'], $previous['args']['end_date']);
        $previous['args'] = array_merge($previous['args'], $dates);
        return $previous; // Executor checks current permissions again.
    }
    return ['reply' => 'Which records do you want for that period? For example, ask “sales last month” or “stock history last month”.'];
}

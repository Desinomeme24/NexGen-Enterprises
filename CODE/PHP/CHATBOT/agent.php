<?php

/** Rule-based orchestration. Live answers come from the existing PHP tools. */
function nxcb_run_agent(mysqli $conn, string $question, array $history, array $ctx): array {
    $toolLog = [];
    $direct = nxcb_fast_direct_reply($question, $ctx);
    if ($direct !== null) {
        return ['ok' => true, 'reply' => $direct, 'route' => 'rule-direct', 'tool_log' => [], 'last_route' => null];
    }
    $route = nxcb_fast_route($question, $ctx);
    if ($route === null) $route = nxcb_fast_followup_route($question, $history, $ctx);
    if ($route !== null && isset($route['reply'])) {
        return ['ok' => true, 'reply' => $route['reply'], 'route' => 'rule-clarify', 'tool_log' => [], 'last_route' => null];
    }
    if ($route !== null) {
        $handled = nxcb_execute_and_format($route['tool'], $route['args'], $conn, $ctx, $toolLog);
        return [
            'ok' => $handled['ok'], 'reply' => $handled['reply'], 'tool_log' => $toolLog,
            'route' => 'rule-tool',
            // A denied/failed request must not become a successful follow-up topic.
            'last_route' => !empty($handled['raw']['ok']) ? $route : null,
        ];
    }
    return ['ok' => true, 'reply' => nxcb_domain_clarification($question, $ctx),
        'tool_log' => [], 'route' => 'rule-clarify', 'last_route' => null];
}

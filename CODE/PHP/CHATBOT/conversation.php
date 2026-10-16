<?php

/** History belongs to one user, active branch, role and permission set. */
function nxcb_context_key(array $ctx): string {
    $permissions = [];
    foreach (['inventory', 'sales', 'sales_analytics', 'accounts_receivable'] as $key) {
        $permissions[$key] = nxcb_permission($ctx, $key);
    }
    return hash('sha256', json_encode([(int)($ctx['user_id'] ?? 0), (int)($ctx['business_id'] ?? 0),
        (string)($ctx['role'] ?? ''), $permissions]));
}

function nxcb_session_context(): array {
    $permissions = [];
    foreach (['inventory', 'sales', 'sales_analytics', 'accounts_receivable'] as $key) {
        $permissions[$key] = (int)($_SESSION['can_' . $key] ?? 0) === 1;
    }
    return ['user_id' => (int)($_SESSION['user_id'] ?? 0), 'business_id' => (int)($_SESSION['business_id'] ?? 0),
        'role' => (string)($_SESSION['role'] ?? ''), 'permissions' => $permissions];
}

function nxcb_load_history(int $maxMessages = 6, array $ctx = []): array {
    if (session_status() !== PHP_SESSION_ACTIVE) return [];
    $scope = nxcb_context_key($ctx);
    // Do not import unscoped text or AI routing decisions from the old version.
    unset($_SESSION['nx_chatbot_ai_history']);
    if (($_SESSION['nx_chatbot_scope'] ?? '') !== $scope) {
        $_SESSION['nx_chatbot_history'] = [];
        $_SESSION['nx_chatbot_scope'] = $scope;
    }
    $history = $_SESSION['nx_chatbot_history'] ?? [];
    return is_array($history) ? array_slice($history, -max(2, $maxMessages)) : [];
}

function nxcb_save_turn(string $userMessage, string $assistantMessage, int $maxMessages = 6,
    array $ctx = [], ?array $lastRoute = null): void {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    // An in-flight request cannot save into a newly selected workspace/login.
    if (nxcb_context_key(nxcb_session_context()) !== nxcb_context_key($ctx)) {
        session_write_close();
        return;
    }
    $history = nxcb_load_history($maxMessages, $ctx);
    $history[] = ['role' => 'user', 'content' => mb_substr($userMessage, 0, 500, 'UTF-8'), 'last_route' => $lastRoute];
    $history[] = ['role' => 'assistant', 'content' => mb_substr($assistantMessage, 0, 700, 'UTF-8')];
    $_SESSION['nx_chatbot_history'] = array_slice($history, -max(2, $maxMessages));
    session_write_close();
}

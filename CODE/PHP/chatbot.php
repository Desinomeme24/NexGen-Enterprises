<?php
// A page may include this partial through both its layout and its own footer.
if (defined('NXCB_WIDGET_RENDERED') && ($_GET['action'] ?? '') !== 'ask') {
    return;
}

// Load config FIRST so it can apply session security settings before session_start().
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/tenant_helper.php";

// Load chatbot modules defensively so a missing/old file becomes a clear logged
// bootstrap error instead of an undefined-function crash during a user request.
$nxcbBootstrapErrors = [];
$nxcbModules = [
    'data_helpers.php',
    'conversation.php',
    'tool_registry.php',
    'tools_inventory.php',
    'tools_sales.php',
    'tools_receivables.php',
    'tools_system.php',
    'tool_executor.php',
    'fast_router.php',
    'response_formatter.php',
    'agent.php',
];

foreach ($nxcbModules as $nxcbModule) {
    $nxcbPath = __DIR__ . '/CHATBOT/' . $nxcbModule;
    if (!is_file($nxcbPath)) {
        $nxcbBootstrapErrors[] = 'Missing file: CHATBOT/' . $nxcbModule;
        continue;
    }
    require_once $nxcbPath;
}

$nxcbRequiredFunctions = [
    'nxcb_load_history',
    'nxcb_save_turn',
    'nxcb_permission',
    'nxcb_fast_direct_reply',
    'nxcb_fast_route',
    'nxcb_fast_followup_route',
    'nxcb_execute_tool',
    'nxcb_execute_and_format',
    'nxcb_run_agent',
    'nxcb_tool_allowed',
    'nxcb_suggestions',
    'nxcb_context_key',
];
foreach ($nxcbRequiredFunctions as $nxcbFunction) {
    if (!function_exists($nxcbFunction)) {
        $nxcbBootstrapErrors[] = 'Missing function: ' . $nxcbFunction . '()';
    }
}
if ($nxcbBootstrapErrors) {
    error_log('[NexGen Chatbot Bootstrap] ' . implode(' | ', $nxcbBootstrapErrors));
}

date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id'])) {
    if (isset($_GET['action']) && $_GET['action'] === 'ask') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['success' => false, 'reply' => 'Session expired. Please log in again.']);
        exit();
    }
    return;
}

$chatbotRole = $_SESSION['role'] ?? '';
$chatbotIsOwner = $chatbotRole === 'owner';

$permissions = [
    'inventory' => (int)($_SESSION['can_inventory'] ?? 0) === 1,
    'sales' => (int)($_SESSION['can_sales'] ?? 0) === 1,
    'sales_analytics' => (int)($_SESSION['can_sales_analytics'] ?? 0) === 1,
    'accounts_receivable' => (int)($_SESSION['can_accounts_receivable'] ?? 0) === 1,
];

$businessId = nxRequireBusinessId($conn);
$chatbotContext = [
    'business_id' => (int)$businessId,
    'user_id' => (int)$_SESSION['user_id'],
    'role' => $chatbotRole,
    'permissions' => $permissions,
    'php_base' => nxAppUrl(),
];

if (isset($_GET['action']) && $_GET['action'] === 'ask') {
    while (ob_get_level() > 0) ob_end_clean();
    ob_start();

    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    mysqli_report(MYSQLI_REPORT_OFF);
    header('Content-Type: application/json; charset=utf-8');

    // Rule-based requests perform only local PHP/database work.
    set_time_limit(30);
    header('Cache-Control: no-store');

    if ($nxcbBootstrapErrors) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'reply' => 'The chatbot backend is incomplete. Please check the Apache error log for the missing chatbot file or function.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode(['success' => false, 'reply' => 'Please send your question using the chatbot.']);
        exit();
    }
    $pageContext = $_POST['context'] ?? '';
    if (!is_string($pageContext) || !hash_equals(nxcb_context_key($chatbotContext), $pageContext)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'reply' => 'Your account, permissions or active branch changed. Refresh this page before asking again.']);
        exit();
    }
    if (!is_string($_POST['message'] ?? null)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'reply' => 'Please enter a text question.']);
        exit();
    }
    $question = trim($_POST['message']);
    if ($question === '') {
        echo json_encode(['success' => true, 'reply' => 'Please type your question first.']);
        exit();
    }
    if (mb_strlen($question, 'UTF-8') > 500) {
        echo json_encode([
            'success' => true,
            'reply' => 'Please keep your question under 500 characters so I can process it reliably.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $history = nxcb_load_history(6, $chatbotContext);

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    try {
        $result = nxcb_run_agent($conn, $question, $history, $chatbotContext);
        $reply = trim((string)($result['reply'] ?? ''));
        if ($reply === '') $reply = 'I could not generate a response for that request.';

        nxcb_save_turn($question, $reply, 6, $chatbotContext, $result['last_route'] ?? null);

        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        if (empty($result['ok'])) http_response_code(503);

        echo json_encode([
            'success' => !empty($result['ok']),
            'reply' => $reply,
            'route' => $result['route'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
        exit();
    } catch (Throwable $e) {
        error_log('[NexGen Chatbot] uncaught=' . $e->getMessage() . ' file=' . $e->getFile() . ' line=' . $e->getLine());
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'reply' => 'Sorry, I hit a system error while processing that request. The technical error was logged.'
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
}
// Keep the widget renderable if a backend module was missed during copying.
// The ask endpoint above will return its bootstrap error before running a query.
$chatbotPageContext = $nxcbBootstrapErrors ? '' : nxcb_context_key($chatbotContext);
$chatbotSuggestions = $nxcbBootstrapErrors ? ['help'] : nxcb_suggestions($chatbotContext);
define('NXCB_WIDGET_RENDERED', true);
?>

<div class="nx-chatbot-widget" id="nxChatbotWidget"
     data-endpoint="<?php echo htmlspecialchars(nxAppUrl('chatbot.php?action=ask'), ENT_QUOTES, 'UTF-8'); ?>"
     data-context="<?php echo htmlspecialchars($chatbotPageContext, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="button" class="nx-chatbot-toggle" id="nxChatbotToggle" aria-label="Open NexGen chatbot">
        <span class="nx-chatbot-toggle-icon-wrap">
            <img src="<?php echo htmlspecialchars(nxProjectUrl('IMAGES/chatbot.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="Chatbot" class="nx-chatbot-toggle-logo">
        </span>
    </button>

    <div class="nx-chatbot-box" id="nxChatbotBox">
        <div class="nx-chatbot-header">
            <div class="nx-chatbot-title">
                <img src="<?php echo htmlspecialchars(nxProjectUrl('IMAGES/chatbot.png'), ENT_QUOTES, 'UTF-8'); ?>" alt="Bot">
                <div>
                    <h4>NexGen Assistant</h4>
                    <small><?php echo $chatbotIsOwner ? 'Business Assistant' : 'System Helper'; ?></small>
                </div>
            </div>
            <button type="button" class="nx-chatbot-close" id="nxChatbotClose" aria-label="Close chatbot">&times;</button>
        </div>

        <div class="nx-chatbot-body" id="nxChatbotMessages" role="log" aria-live="polite" aria-relevant="additions">
            <div class="nx-msg bot">
                Hi! I’m your NexGen Assistant. Choose a suggestion below or type a supported question.
                I check records for your active business branch.
                <br><br>
                Type <strong>help</strong> to see available questions.
            </div>
        </div>

        <div class="nx-chatbot-suggestions">
            <?php foreach ($chatbotSuggestions as $chatbotSuggestion): ?>
                <button type="button" class="nx-chip"><?php echo htmlspecialchars($chatbotSuggestion, ENT_QUOTES, 'UTF-8'); ?></button>
            <?php endforeach; ?>
        </div>

        <form class="nx-chatbot-input-wrap" id="nxChatbotForm">
            <input type="text" id="nxChatbotInput" aria-label="Chatbot question" placeholder="Type a question or help..." autocomplete="off" maxlength="500">
            <button type="submit">Send</button>
        </form>
    </div>
</div>

<link rel="stylesheet" href="<?php echo htmlspecialchars(nxCodeUrl('STYLE/chatbot.css?v=rules4'), ENT_QUOTES, 'UTF-8'); ?>">
<script src="<?php echo htmlspecialchars(nxCodeUrl('JS/chatbot.js?v=rules3'), ENT_QUOTES, 'UTF-8'); ?>"></script>

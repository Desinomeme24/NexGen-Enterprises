<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function nxCaptchaChallengeRespond(int $status, array $response): void
{
    http_response_code($status);
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    nxCaptchaChallengeRespond(405, [
        'success' => false,
        'message' => 'Invalid CAPTCHA refresh request. Please try again.',
    ]);
}

$submittedFormKey = $_POST['form_key'] ?? '';
$formKey = is_string($submittedFormKey) ? trim($submittedFormKey) : '';
$submittedCsrfToken = $_POST['csrf_token'] ?? null;
$csrfFormKey = [
    'client_login_form' => 'captcha_refresh_client_login',
    'signup_form' => 'captcha_refresh_signup',
][$formKey] ?? '';

if (
    $csrfFormKey === ''
    || !is_string($submittedCsrfToken)
    || !validateCsrfToken($csrfFormKey, $submittedCsrfToken)
) {
    nxCaptchaChallengeRespond(403, [
        'success' => false,
        'message' => 'Your page session expired. Reload the page and try again.',
    ]);
}

$nextCsrfToken = generateCsrfToken($csrfFormKey);
$challenge = generateImageCaptcha($formKey);
if (empty($challenge['success'])) {
    nxCaptchaChallengeRespond(503, [
        'success' => false,
        'message' => (string)($challenge['message'] ?? 'A new CAPTCHA challenge could not be created.'),
        'csrf_token' => $nextCsrfToken,
    ]);
}

$publicItems = [];
foreach ($challenge['items'] as $item) {
    $publicItems[] = [
        'id' => (string)$item['id'],
        'relative_path' => (string)$item['relative_path'],
    ];
}

nxCaptchaChallengeRespond(200, [
    'success' => true,
    'target_label' => (string)$challenge['target_label'],
    'items' => $publicItems,
    'csrf_token' => $nextCsrfToken,
]);

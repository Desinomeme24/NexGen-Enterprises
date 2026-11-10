<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/document_upload_security.php';
require_once __DIR__ . '/tenant_helper.php';

function nxDocumentDeliveryFail(int $status, int $adminId = 0, int $requestId = 0, string $slot = 'unknown'): void
{
    nxDocumentUploadLog('document.access_denied', [
        'user_id' => $adminId,
        'role' => $adminId > 0 ? (string)($_SESSION['role'] ?? 'system_admin') : 'unauthenticated',
        'request_id' => $requestId,
        'slot' => $slot,
    ]);
    http_response_code($status);
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    exit;
}

$adminId = (int)($_SESSION['user_id'] ?? 0);
$isOwnerReviewer = (string)($_SESSION['role'] ?? '') === 'owner';
if (
    $adminId <= 0
    || (!$isOwnerReviewer && !nxIsAdminRole((string)($_SESSION['role'] ?? '')))
) {
    nxDocumentDeliveryFail(403, $adminId);
}

enforceSessionTimeout();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    nxDocumentDeliveryFail(405, $adminId);
}

$requestId = filter_var($_GET['request_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$slot = (string)($_GET['slot'] ?? 'valid_id');
$source = (string)($_GET['source'] ?? 'registration');
if (
    !is_int($requestId)
    || !in_array($slot, ['valid_id', 'business_permit'], true)
    || !in_array($source, ['registration', 'workspace'], true)
    || ($source === 'workspace' && $slot !== 'business_permit')
) {
    nxDocumentDeliveryFail(404, $adminId, is_int($requestId) ? $requestId : 0, $slot);
}
$isSuperAdmin = nxIsSuperAdminRole((string)$_SESSION['role']);
if (($source === 'workspace' && ($isSuperAdmin || $isOwnerReviewer)) || ($isOwnerReviewer && $slot !== 'valid_id')) {
    nxDocumentDeliveryFail(403, $adminId, $requestId, $slot);
}

try {
    $reviewerSql = $isOwnerReviewer
        ? "SELECT id FROM users WHERE id = ? AND role = 'owner' AND account_status = 'active' LIMIT 1"
        : "SELECT id FROM users WHERE id = ? AND role IN ('system_admin', 'super_admin') AND account_status = 'active' LIMIT 1";
    $adminStmt = $conn->prepare($reviewerSql);
    if (!$adminStmt) {
        throw new RuntimeException('Reviewer authorization lookup failed.');
    }
    $adminStmt->bind_param('i', $adminId);
    $adminStmt->execute();
    $activeAdmin = (bool)$adminStmt->get_result()->fetch_assoc();
    $adminStmt->close();
    if (!$activeAdmin) {
        nxDocumentDeliveryFail(403, $adminId, $requestId, $slot);
    }

    $documentColumn = $slot === 'valid_id' ? 'valid_id_path' : 'business_permit_path';
    $requestTable = $source === 'workspace' ? 'workspace_requests' : 'registration_requests';
    $ownerRequestFields = $isOwnerReviewer && $source === 'registration'
        ? ', requested_role, business_id, business_entity_id, business_code, branch_code'
        : '';
    $stmt = $conn->prepare(
        "SELECT {$documentColumn} AS document_reference, request_status{$ownerRequestFields}
         FROM {$requestTable}
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Document authorization lookup failed.');
    }
    $stmt->bind_param('i', $requestId);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (
        $isOwnerReviewer
        && $source === 'registration'
        && $request
        && !nxOwnerCanReviewEmployeeRequest($conn, $adminId, $request)
    ) {
        nxDocumentDeliveryFail(404, $adminId, $requestId, $slot);
    }
} catch (Throwable $error) {
    error_log('NexGen private document lookup failed: ' . $error->getMessage());
    nxDocumentDeliveryFail(404, $adminId, $requestId, $slot);
}

if (
    !$request
    || ($source === 'registration' && (string)$request['request_status'] === 'rejected')
    || ($isOwnerReviewer && (
        $source !== 'registration'
        || !in_array((string)$request['request_status'], ['pending', 'resubmit'], true)
    ))
    || ($source === 'registration' && $isSuperAdmin && (string)$request['request_status'] !== 'pending')
    || ($source === 'registration' && !$isOwnerReviewer && !$isSuperAdmin && (string)$request['request_status'] === 'pending')
    || empty($request['document_reference'])
) {
    nxDocumentDeliveryFail(404, $adminId, $requestId, $slot);
}

$temporaryDocument = null;
try {
    $temporaryDocument = nxReadSensitiveDocument((string)$request['document_reference'], $slot);
    if ($temporaryDocument === null) {
        nxDocumentDeliveryFail(404, $adminId, $requestId, $slot);
    }

    nxDocumentUploadLog('document.accessed', [
        'user_id' => $adminId,
        'role' => (string)$_SESSION['role'],
        'request_id' => $requestId,
        'slot' => $slot,
    ]);

    $downloadName = ($source === 'workspace' ? 'workspace_request_' : 'registration_')
        . $requestId . '_' . $slot . '.' . $temporaryDocument['extension'];
    session_write_close();
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');

    if (($_GET['preview'] ?? '') === '1') {
        $documentContents = file_get_contents($temporaryDocument['path']);
        if (!is_string($documentContents)) {
            throw new RuntimeException('Private document preview could not be read.');
        }

        $documentData = base64_encode($documentContents);
        $scriptNonce = base64_encode(random_bytes(18));
        $label = $slot === 'valid_id' ? 'Valid ID' : 'Business permit';
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Frame-Options: SAMEORIGIN');
        header(
            "Content-Security-Policy: default-src 'none'; img-src data:; frame-src data:; " .
            "style-src 'unsafe-inline'; script-src 'nonce-{$scriptNonce}'; " .
            "base-uri 'none'; form-action 'none'; frame-ancestors 'self'"
        );

        echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>' . e($label) . ' preview</title>';
        echo '<style>
            html,body{width:100%;height:100%;margin:0;background:transparent}
            body{display:grid;place-items:center;overflow:auto;color:#fff;font-family:Arial,sans-serif}
            main{box-sizing:border-box;width:100%;height:100%;display:grid;place-items:center;padding:24px}
            img{display:block;max-width:100%;max-height:calc(100vh - 48px);max-height:calc(100dvh - 48px);object-fit:contain;filter:drop-shadow(0 12px 30px rgba(0,0,0,.4))}
            .pdf{display:block;width:min(100%,1100px);height:calc(100vh - 48px);height:calc(100dvh - 48px);border:0;border-radius:8px;background:#fff}
            button{position:fixed;top:14px;right:14px;z-index:2;width:42px;height:42px;border:1px solid rgba(255,255,255,.55);border-radius:50%;background:rgba(8,13,24,.68);color:white;font-size:28px;line-height:1;cursor:pointer}
            button:hover,button:focus-visible{background:rgba(8,13,24,.95);outline:2px solid white;outline-offset:2px}
        </style></head><body>';
        echo '<button id="closePreview" type="button" aria-label="Close document preview">&times;</button>';
        echo '<main>';
        if ($temporaryDocument['mime'] === 'application/pdf') {
            echo '<iframe class="pdf" title="' . e($label) . '" src="data:application/pdf;base64,'
                . $documentData . '"></iframe>';
        } else {
            echo '<img alt="' . e($label) . '" src="data:' . e($temporaryDocument['mime'])
                . ';base64,' . $documentData . '">';
        }
        echo '</main><script nonce="' . e($scriptNonce) . '">
            document.getElementById("closePreview").addEventListener("click",function(){
                if(window.parent!==window){
                    window.parent.postMessage({type:"nexgen-close-document-preview"},window.location.origin);
                }else if(window.history.length>1){
                    window.history.back();
                }else{
                    window.location.href="' . e(nxAppUrl('pending_requests.php')) . '";
                }
            });
        </script></body></html>';
    } else {
        header('Content-Type: ' . $temporaryDocument['mime']);
        header('Content-Length: ' . (string)filesize($temporaryDocument['path']));
        header('Content-Disposition: ' . $temporaryDocument['disposition'] . '; filename="' . $downloadName . '"');
        header('Pragma: no-cache');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($temporaryDocument['path']);
    }
} catch (Throwable $error) {
    error_log('NexGen private document delivery failed: ' . $error->getMessage());
    nxDocumentUploadLog('document.access_denied', [
        'user_id' => $adminId,
        'role' => (string)$_SESSION['role'],
        'request_id' => $requestId,
        'slot' => $slot,
    ]);
    if (!headers_sent()) {
        http_response_code(404);
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }
} finally {
    if ($temporaryDocument !== null && is_file((string)$temporaryDocument['path'])) {
        unlink((string)$temporaryDocument['path']);
    }
}
exit;

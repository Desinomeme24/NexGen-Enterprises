<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /NexGen/CODE/PHP/index.php');
    exit();
}

if (($_SESSION['role'] ?? '') !== 'system_admin') {
    header('Location: /NexGen/CODE/PHP/dashboard.php');
    exit();
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!nxWorkspaceRequestSchemaReady($conn)) {
    $_SESSION['flash'] = [
        'type' => 'notice-error',
        'message' => 'The Multi-Tenancy v3 approval migration has not been installed.',
    ];
    header('Location: pending_requests.php');
    exit();
}

$requestId = (int)($_GET['id'] ?? 0);
if ($requestId <= 0) {
    header('Location: pending_requests.php');
    exit();
}

$stmt = $conn->prepare(
    "SELECT wr.*,
            u.full_name, u.username, u.email, u.phone, u.role AS requester_role,
            u.account_status,
            be.business_code AS parent_business_code,
            be.status AS parent_business_status,
            EXISTS(
                SELECT 1
                FROM user_business_assignments uba
                WHERE uba.user_id = wr.requested_by
                  AND uba.business_entity_id = wr.business_entity_id
                  AND uba.assignment_role = 'owner'
                  AND uba.status = 'active'
            ) AS owns_parent_business
     FROM workspace_requests wr
     INNER JOIN users u ON u.id = wr.requested_by
     LEFT JOIN business_entities be ON be.id = wr.business_entity_id
     WHERE wr.id = ?
     LIMIT 1"
);
if (!$stmt) {
    $_SESSION['flash'] = ['type' => 'notice-error', 'message' => 'Unable to load the workspace request safely.'];
    header('Location: pending_requests.php');
    exit();
}
$stmt->bind_param('i', $requestId);
$stmt->execute();
$request = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$request) {
    $_SESSION['flash'] = ['type' => 'notice-error', 'message' => 'Workspace request not found.'];
    header('Location: pending_requests.php');
    exit();
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$currentStatus = (string)$request['request_status'];
$isFinalized = in_array($currentStatus, ['approved', 'rejected'], true);
$isBranchRequest = $request['request_type'] === 'branch';
$requesterValid = $request['requester_role'] === 'owner'
    && $request['account_status'] === 'active';
$parentValid = !$isBranchRequest || (
    (int)$request['business_entity_id'] > 0
    && $request['parent_business_status'] === 'active'
    && (int)$request['owns_parent_business'] === 1
);
$integrityOk = $requesterValid && $parentValid;
$isEmbeddedView = ($_GET['embedded'] ?? '') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php include __DIR__ . '/nexgen_favicon/favicon.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workspace Request - NexGen</title>
    <script>
        (function () {
            try {
                var saved = localStorage.getItem('nexgen-theme');
                if (saved === 'light' || saved === 'dark') {
                    document.documentElement.setAttribute('data-theme', saved);
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="/NexGen/CODE/STYLE/admin_module.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .request-summary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .request-section-heading {
            min-width: 0;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .request-section-heading h2 { margin: 0; }
        .request-code-inline {
            display: inline-flex;
            align-items: center;
            max-width: 100%;
            padding: 5px 9px;
            border: 1px solid rgba(247, 200, 115, 0.38);
            border-radius: 999px;
            background: rgba(247, 200, 115, 0.12);
            color: var(--gold);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.35px;
            overflow-wrap: anywhere;
        }
        body.embedded-request-view {
            overflow-x: hidden;
        }
        body.embedded-request-view .admin-shell {
            display: block;
            min-height: 100vh;
        }
        body.embedded-request-view .admin-content {
            width: 100%;
            max-width: none;
            min-height: 100vh;
            margin: 0 !important;
            padding: 22px;
        }
        .request-summary-item {
            min-width: 0;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--card);
        }
        .request-summary-item.full { grid-column: 1 / -1; }
        .request-summary-item label {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 0.45px;
            text-transform: uppercase;
        }
        .request-summary-item .value {
            color: var(--text);
            font-size: 13.5px;
            font-weight: 650;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }
        .workspace-action-form {
            max-width: 420px;
            display: grid;
            gap: 12px;
        }
        .workspace-action-form label {
            display: block;
            margin-bottom: 5px;
            color: var(--muted);
            font-size: 11px;
            font-weight: 750;
        }
        .workspace-action-form select,
        .workspace-action-form textarea {
            width: 100%;
            box-sizing: border-box;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--card);
            color: var(--text);
            font: inherit;
            font-size: 13px;
            padding: 10px 12px;
        }
        .workspace-action-form textarea { min-height: 74px; resize: vertical; }
        .workspace-action-form select:focus,
        .workspace-action-form textarea:focus {
            outline: none;
            border-color: var(--gold);
            box-shadow: 0 0 0 3px var(--gold-soft);
        }
        .workspace-action-form select option {
            background: var(--panel-2);
            color: var(--text);
        }
        #workspaceRemarksField { display: none; }
        #workspaceRemarksField.show { display: block; }
        .workspace-review-submit:disabled { opacity: 0.55; cursor: not-allowed; }
        .review-confirm-overlay {
            position: fixed;
            inset: 0;
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(2, 4, 15, 0.58);
            backdrop-filter: blur(7px);
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transition: 0.2s ease;
        }
        .review-confirm-overlay.show {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .review-confirm-box {
            width: min(360px, 100%);
            padding: 24px;
            border: 1px solid var(--glass-border);
            border-radius: 18px;
            background: var(--panel-2);
            color: var(--text);
            text-align: center;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.35);
        }
        .review-confirm-actions {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 18px;
        }
        @media (max-width: 700px) {
            body.embedded-request-view .admin-content { padding: 14px; }
            .request-summary-grid { grid-template-columns: 1fr; }
            .request-summary-item.full { grid-column: auto; }
        }
    </style>
</head>
<body<?php echo $isEmbeddedView ? ' class="embedded-request-view"' : ''; ?>>
<div class="admin-shell">
    <?php if (!$isEmbeddedView) include __DIR__ . '/admin_sidebar.php'; ?>

    <main class="admin-content">
        <div class="topbar">
            <div class="page-title">
                <h1>Workspace Request</h1>
                <p>Review the owner's <?php echo $isBranchRequest ? 'new branch' : 'new business'; ?> request</p>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="notice <?php echo e($flash['type']); ?>"><?php echo e($flash['message']); ?></div>
        <?php endif; ?>

        <section class="panel">
            <div class="panel-header">
                <div class="request-section-heading">
                    <h2>Owner Information</h2>
                    <span class="request-code-inline"><?php echo e($request['request_code']); ?></span>
                </div>
                <a class="btn btn-silver" id="requestViewBackButton" href="pending_requests.php">Back</a>
            </div>
            <div class="panel-body">
                <div class="request-summary-grid">
                    <div class="request-summary-item"><label>Full Name</label><div class="value"><?php echo e($request['full_name']); ?></div></div>
                    <div class="request-summary-item"><label>Username</label><div class="value"><?php echo e($request['username']); ?></div></div>
                    <div class="request-summary-item"><label>Email</label><div class="value"><?php echo e($request['email']); ?></div></div>
                    <div class="request-summary-item"><label>Phone</label><div class="value"><?php echo e($request['phone']); ?></div></div>
                    <div class="request-summary-item"><label>Account Status</label><div class="value"><?php echo e(ucfirst($request['account_status'])); ?></div></div>
                    <div class="request-summary-item"><label>Submitted</label><div class="value"><?php echo e(date('M d, Y h:i A', strtotime($request['created_at']))); ?></div></div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header"><h2>Workspace Request</h2></div>
            <div class="panel-body">
                <div class="request-summary-grid">
                    <div class="request-summary-item"><label>Request Type</label><div class="value"><?php echo $isBranchRequest ? 'New Branch' : 'New Business'; ?></div></div>
                    <div class="request-summary-item"><label>Status</label><div class="value"><span class="badge badge-<?php echo e($currentStatus); ?>"><?php echo e(ucfirst($currentStatus)); ?></span></div></div>
                    <div class="request-summary-item"><label>Business Name</label><div class="value"><?php echo e($request['business_name']); ?></div></div>
                    <div class="request-summary-item"><label>SME Type</label><div class="value"><?php echo e($request['business_type']); ?></div></div>
                    <div class="request-summary-item"><label>Branch Name</label><div class="value"><?php echo e($request['branch_name']); ?></div></div>
                    <div class="request-summary-item"><label><?php echo $isBranchRequest ? 'Branch Address' : 'Business/Branch Address'; ?></label><div class="value"><?php echo e($request['business_address']); ?></div></div>
                    <?php if ($isBranchRequest): ?>
                        <div class="request-summary-item"><label>Existing Business Code</label><div class="value"><?php echo e($request['parent_business_code'] ?: 'Unavailable'); ?></div></div>
                        <div class="request-summary-item"><label>Business Entity ID</label><div class="value">#<?php echo (int)$request['business_entity_id']; ?></div></div>
                    <?php else: ?>
                        <div class="request-summary-item full"><label>Separate Operations Confirmation</label><div class="value"><?php echo (int)$request['separate_operations'] === 1 ? 'Confirmed by owner' : 'Not selected'; ?></div></div>
                    <?php endif; ?>
                    <?php if ($currentStatus === 'approved'): ?>
                        <div class="request-summary-item"><label>Approved Business Code</label><div class="value"><?php echo e($request['business_code']); ?></div></div>
                        <div class="request-summary-item"><label>Approved Branch Code</label><div class="value"><?php echo e($request['branch_code']); ?></div></div>
                    <?php endif; ?>
                    <?php if (!empty($request['admin_remarks'])): ?>
                        <div class="request-summary-item full"><label>Administrator Remarks</label><div class="value"><?php echo nl2br(e($request['admin_remarks'])); ?></div></div>
                    <?php endif; ?>
                </div>

                <div class="notice <?php echo $integrityOk ? 'notice-success' : 'notice-error'; ?>" style="margin-top:16px;">
                    <?php if ($integrityOk): ?>
                        Owner verification passed. Approval will create and assign the requested workspace without changing the owner's currently active branch.
                    <?php else: ?>
                        Owner verification failed. The requester is no longer an active owner or no longer owns the selected parent business. Approval is disabled.
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header"><h2>Administrator Actions</h2></div>
            <div class="panel-body">
                <?php if ($isFinalized): ?>
                    <div class="notice <?php echo $currentStatus === 'approved' ? 'notice-success' : 'notice-error'; ?>">
                        This request has already been <strong><?php echo e($currentStatus); ?></strong> and is final.
                    </div>
                <?php else: ?>
                    <form id="workspaceReviewForm" action="workspace_request_review.php" method="POST" class="workspace-action-form">
                        <input type="hidden" name="csrf_token" value="<?php echo e(generateCsrfToken('admin_request_action')); ?>">
                        <input type="hidden" name="request_id" value="<?php echo (int)$request['id']; ?>">
                        <input type="hidden" name="decision" id="workspaceDecision" value="">

                        <div>
                            <label for="workspaceActionSelect">Select Action</label>
                            <select id="workspaceActionSelect" required>
                                <option value="" selected disabled>Choose an action</option>
                                <option value="approve" <?php echo !$integrityOk ? 'disabled' : ''; ?>>Approve Request</option>
                                <option value="reject">Reject Request</option>
                            </select>
                        </div>

                        <div id="workspaceRemarksField">
                            <label for="workspaceRemarks">Remarks</label>
                            <textarea id="workspaceRemarks" name="remarks" placeholder="Add administrator remarks"></textarea>
                        </div>

                        <div>
                            <button type="submit" class="btn btn-silver workspace-review-submit" id="workspaceReviewSubmit" disabled>Submit</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>

<div class="review-confirm-overlay" id="reviewConfirmOverlay">
    <div class="review-confirm-box">
        <h3>Confirm Action</h3>
        <p id="reviewConfirmMessage">Continue with this request?</p>
        <div class="review-confirm-actions">
            <button type="button" class="btn btn-silver" id="reviewConfirmCancel">Cancel</button>
            <button type="button" class="btn btn-silver" id="reviewConfirmOk">Confirm</button>
        </div>
    </div>
</div>

<script src="/NexGen/CODE/JS/admin_module.js"></script>
<script>
<?php if ($isEmbeddedView): ?>
(function() {
    const backButton = document.getElementById('requestViewBackButton');
    if (!backButton || window.parent === window) return;

    backButton.addEventListener('click', function(event) {
        event.preventDefault();
        window.parent.postMessage({ type: 'nexgen-close-request-view' }, window.location.origin);
    });
})();
<?php endif; ?>

document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('workspaceReviewForm');
    const action = document.getElementById('workspaceActionSelect');
    const decision = document.getElementById('workspaceDecision');
    const remarksField = document.getElementById('workspaceRemarksField');
    const remarks = document.getElementById('workspaceRemarks');
    const submit = document.getElementById('workspaceReviewSubmit');
    const overlay = document.getElementById('reviewConfirmOverlay');
    const message = document.getElementById('reviewConfirmMessage');
    const cancel = document.getElementById('reviewConfirmCancel');
    const confirm = document.getElementById('reviewConfirmOk');

    if (!form || !action || !decision || !remarksField || !remarks || !submit) return;

    action.addEventListener('change', function () {
        const rejecting = action.value === 'reject';
        decision.value = action.value;
        remarksField.classList.add('show');
        remarks.required = rejecting;
        remarks.placeholder = rejecting
            ? 'State why this workspace request is rejected'
            : 'Optional approval remarks';
        submit.textContent = rejecting ? 'Reject Request' : 'Approve Request';
        submit.disabled = false;
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!form.reportValidity()) return;
        message.textContent = action.value === 'reject'
            ? 'Reject this workspace request?'
            : 'Approve this workspace request and create the workspace?';
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
    });

    function closeConfirm() {
        overlay.classList.remove('show');
        document.body.style.overflow = '';
    }

    cancel.addEventListener('click', closeConfirm);
    confirm.addEventListener('click', function () {
        closeConfirm();
        form.submit();
    });
    overlay.addEventListener('click', function (event) {
        if (event.target === overlay) closeConfirm();
    });
});
</script>
</body>
</html>

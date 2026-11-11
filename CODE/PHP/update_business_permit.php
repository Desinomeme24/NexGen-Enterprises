<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tenant_helper.php';

function nxBusinessPermitRedirect(bool $success, string $message, int $businessEntityId): void
{
    $_SESSION[$success ? 'success' : 'error'] = $message;
    header('Location: ' . nxAppUrl('san_andres_analytics.php') . '?business_id=' . $businessEntityId);
    exit();
}

if (
    !isset($_SESSION['user_id'], $_SESSION['role'])
    || !nxIsSuperAdminRole((string)$_SESSION['role'])
) {
    header('Location: ' . NEXGEN_ADMIN_LOGIN_PATH);
    exit();
}

enforceSessionTimeout();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

$businessEntityId = filter_var($_POST['business_entity_id'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!is_int($businessEntityId)) {
    http_response_code(400);
    exit('Invalid business selection.');
}

$action = (string)($_POST['action'] ?? '');
$permitYear = filter_var($_POST['permit_year'] ?? null, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 2000, 'max_range' => 2100],
]);
if (!is_int($permitYear) || $permitYear !== (int)date('Y')) {
    nxBusinessPermitRedirect(false, 'Permit assessments can only be updated for the current calendar year.', $businessEntityId);
}

$csrfScope = $action === 'save_assessment'
    ? 'permit_assessment_' . $businessEntityId . '_' . $permitYear
    : 'permit_quarter_' . $businessEntityId . '_' . $permitYear . '_' . (int)($_POST['quarter_number'] ?? 0);
if (!validateCsrfToken($csrfScope, $_POST['csrf_token'] ?? null)) {
    nxBusinessPermitRedirect(false, 'Your session expired. Reload San Andres Analytics and try again.', $businessEntityId);
}

$adminId = (int)$_SESSION['user_id'];
$transactionStarted = false;
try {
    $accountStmt = $conn->prepare(
        "SELECT id
         FROM users
         WHERE id = ? AND role IN ('system_admin', 'super_admin') AND account_status = 'active'
         LIMIT 1"
    );
    if (!$accountStmt) {
        throw new RuntimeException('Unable to verify the BPLO account.');
    }
    $accountStmt->bind_param('i', $adminId);
    $accountStmt->execute();
    $activeAdmin = (bool)$accountStmt->get_result()->fetch_assoc();
    $accountStmt->close();
    if (!$activeAdmin) {
        nxBusinessPermitRedirect(false, 'Your BPLO account is no longer active.', $businessEntityId);
    }

    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to begin the permit update.');
    }
    $transactionStarted = true;

    $businessStmt = $conn->prepare(
        'SELECT id, business_name FROM business_entities WHERE id = ? FOR UPDATE'
    );
    if (!$businessStmt) {
        throw new RuntimeException('Unable to load the business permit record.');
    }
    $businessStmt->bind_param('i', $businessEntityId);
    $businessStmt->execute();
    $business = $businessStmt->get_result()->fetch_assoc();
    $businessStmt->close();
    if (!$business) {
        throw new RuntimeException('Business not found.');
    }

    $assessmentStmt = $conn->prepare(
        'SELECT id, annual_fee FROM business_permit_assessments
         WHERE business_entity_id = ? AND permit_year = ? FOR UPDATE'
    );
    if (!$assessmentStmt) {
        throw new RuntimeException('Quarterly permit tracking is not available. Apply the permit-payment migration first.');
    }
    $assessmentStmt->bind_param('ii', $businessEntityId, $permitYear);
    $assessmentStmt->execute();
    $assessment = $assessmentStmt->get_result()->fetch_assoc();
    $assessmentStmt->close();

    if ($action === 'save_assessment') {
        $annualFeeInput = trim((string)($_POST['annual_fee'] ?? ''));
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $annualFeeInput) || (float)$annualFeeInput <= 0) {
            throw new RuntimeException('Enter a valid annual fee greater than zero with up to two decimal places.');
        }
        $annualFee = number_format((float)$annualFeeInput, 2, '.', '');
        $feeCents = (int)round((float)$annualFee * 100);

        $existingQuarters = [];
        $paidAmountCents = 0;
        if ($assessment) {
            $assessmentId = (int)$assessment['id'];
            $quarterRead = $conn->prepare(
                'SELECT quarter_number, assessed_amount, payment_status
                 FROM business_permit_quarterly_payments
                 WHERE assessment_id = ?
                 FOR UPDATE'
            );
            if (!$quarterRead) {
                throw new RuntimeException('Unable to verify existing quarterly payments.');
            }
            $quarterRead->bind_param('i', $assessmentId);
            if (!$quarterRead->execute()) {
                throw new RuntimeException('Unable to load existing quarterly payments.');
            }
            $quarterResult = $quarterRead->get_result();
            while ($quarterRow = $quarterResult->fetch_assoc()) {
                $quarterNumber = (int)$quarterRow['quarter_number'];
                $existingQuarters[$quarterNumber] = (string)$quarterRow['payment_status'];
                if ($quarterRow['payment_status'] === 'paid') {
                    $paidAmountCents += (int)round((float)$quarterRow['assessed_amount'] * 100);
                }
            }
            $quarterRead->close();
        }

        $unpaidQuarters = [];
        for ($quarter = 1; $quarter <= 4; $quarter++) {
            if (($existingQuarters[$quarter] ?? 'pending') !== 'paid') {
                $unpaidQuarters[] = $quarter;
            }
        }

        if ($assessment) {
            $previousFeeCents = (int)round((float)$assessment['annual_fee'] * 100);
            if (
                $previousFeeCents !== $feeCents
                && ($unpaidQuarters === [] || $paidAmountCents >= $feeCents)
            ) {
                throw new RuntimeException(
                    'The revised annual fee must exceed recorded paid amounts and at least one quarter must remain unpaid. Reconcile the permit payments before changing this fee.'
                );
            }

            $updateAssessment = $conn->prepare(
                'UPDATE business_permit_assessments SET annual_fee = ? WHERE id = ?'
            );
            if (!$updateAssessment) {
                throw new RuntimeException('Unable to prepare the annual assessment update.');
            }
            $updateAssessment->bind_param('si', $annualFee, $assessmentId);
            if (!$updateAssessment->execute()) {
                throw new RuntimeException('Unable to update the annual assessment.');
            }
            $updateAssessment->close();
        } else {
            $insertAssessment = $conn->prepare(
                'INSERT INTO business_permit_assessments
                    (business_entity_id, permit_year, annual_fee, created_by)
                 VALUES (?, ?, ?, ?)'
            );
            if (!$insertAssessment) {
                throw new RuntimeException('Unable to prepare the annual assessment.');
            }
            $insertAssessment->bind_param('iisi', $businessEntityId, $permitYear, $annualFee, $adminId);
            if (!$insertAssessment->execute()) {
                throw new RuntimeException('Unable to create the annual assessment.');
            }
            $assessmentId = (int)$insertAssessment->insert_id;
            $insertAssessment->close();
        }

        $remainingFeeCents = max(0, $feeCents - $paidAmountCents);
        $allocationQuarters = $unpaidQuarters !== [] ? $unpaidQuarters : [1, 2, 3, 4];
        $baseCents = intdiv($remainingFeeCents, count($allocationQuarters));
        $remainder = $remainingFeeCents % count($allocationQuarters);
        $allocationAmounts = [];
        foreach ($allocationQuarters as $index => $quarterNumber) {
            $allocationAmounts[$quarterNumber] = $baseCents + ($index < $remainder ? 1 : 0);
        }

        $quarterStmt = $conn->prepare(
            "INSERT INTO business_permit_quarterly_payments
                (assessment_id, quarter_number, assessed_amount, payment_status)
             VALUES (?, ?, ?, 'pending')
             ON DUPLICATE KEY UPDATE
                assessed_amount = IF(payment_status <> 'paid', VALUES(assessed_amount), assessed_amount)"
        );
        if (!$quarterStmt) {
            throw new RuntimeException('Unable to prepare the quarterly assessment records.');
        }
        for ($quarter = 1; $quarter <= 4; $quarter++) {
            $amount = number_format(($allocationAmounts[$quarter] ?? 0) / 100, 2, '.', '');
            $quarterStmt->bind_param('iis', $assessmentId, $quarter, $amount);
            if (!$quarterStmt->execute()) {
                throw new RuntimeException('Unable to save quarterly assessment amounts.');
            }
        }
        $quarterStmt->close();

        $description = "Set {$permitYear} annual business permit fee for business #{$businessEntityId} ({$business['business_name']}) to PHP {$annualFee}";
        $logAction = 'set_business_permit_assessment';
    } elseif ($action === 'set_quarter') {
        $quarter = filter_var($_POST['quarter_number'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 4],
        ]);
        $paymentStatus = (string)($_POST['payment_status'] ?? '');
        if (!is_int($quarter) || !in_array($paymentStatus, ['paid', 'unpaid'], true)) {
            throw new RuntimeException('Select a valid quarter and payment status.');
        }
        if (!$assessment) {
            throw new RuntimeException('Set the annual permit fee before recording quarterly payments.');
        }

        $assessmentId = (int)$assessment['id'];
        $quarterRead = $conn->prepare(
            'SELECT payment_status FROM business_permit_quarterly_payments
             WHERE assessment_id = ? AND quarter_number = ? FOR UPDATE'
        );
        if (!$quarterRead) {
            throw new RuntimeException('Unable to load the quarterly payment record.');
        }
        $quarterRead->bind_param('ii', $assessmentId, $quarter);
        if (!$quarterRead->execute()) {
            throw new RuntimeException('Unable to load the quarterly payment record.');
        }
        $quarterRecord = $quarterRead->get_result()->fetch_assoc();
        $quarterRead->close();
        if (!$quarterRecord) {
            throw new RuntimeException('The quarterly payment record is missing.');
        }
        if ((string)$quarterRecord['payment_status'] === $paymentStatus) {
            throw new RuntimeException('Quarter ' . $quarter . ' is already marked ' . $paymentStatus . '.');
        }

        if (
            $paymentStatus === 'unpaid'
            && !nxBusinessPermitQuarterGraceExpired($permitYear, $quarter)
        ) {
            $graceDeadline = nxBusinessPermitQuarterGraceDeadline($permitYear, $quarter)->format('M d, Y');
            throw new RuntimeException(
                "Quarter {$quarter} cannot be marked unpaid before its 20-day grace period ends on {$graceDeadline}."
            );
        }
        if ($paymentStatus === 'paid') {
            $updateQuarter = $conn->prepare(
                'UPDATE business_permit_quarterly_payments
                 SET payment_status = ?, paid_at = CURRENT_TIMESTAMP, paid_by = ?
                 WHERE assessment_id = ? AND quarter_number = ?'
            );
            if (!$updateQuarter) {
                throw new RuntimeException('Unable to prepare the quarterly payment update.');
            }
            $updateQuarter->bind_param('siii', $paymentStatus, $adminId, $assessmentId, $quarter);
        } else {
            $updateQuarter = $conn->prepare(
                'UPDATE business_permit_quarterly_payments
                 SET payment_status = ?, paid_at = NULL, paid_by = NULL
                 WHERE assessment_id = ? AND quarter_number = ?'
            );
            if (!$updateQuarter) {
                throw new RuntimeException('Unable to prepare the quarterly payment update.');
            }
            $updateQuarter->bind_param('sii', $paymentStatus, $assessmentId, $quarter);
        }
        if (!$updateQuarter->execute()) {
            throw new RuntimeException('Unable to update the quarterly payment.');
        }
        $updateQuarter->close();

        $description = "Marked business #{$businessEntityId} ({$business['business_name']}) permit quarter {$quarter} for {$permitYear} as {$paymentStatus}";
        $logAction = 'update_business_permit_quarter';
    } else {
        throw new RuntimeException('Invalid permit update action.');
    }

    $quarterStatusStmt = $conn->prepare(
        'SELECT quarter_number, payment_status
         FROM business_permit_quarterly_payments
         WHERE assessment_id = ?
         ORDER BY quarter_number'
    );
    if (!$quarterStatusStmt) {
        throw new RuntimeException('Unable to verify quarterly payment status.');
    }
    $quarterStatusStmt->bind_param('i', $assessmentId);
    $quarterStatusStmt->execute();
    $quarterResult = $quarterStatusStmt->get_result();
    $hasConfirmedUnpaid = false;
    $firstUnpaidMonth = null;
    $quarterCount = 0;
    while ($quarterRow = $quarterResult->fetch_assoc()) {
        $quarterCount++;
        if ($quarterRow['payment_status'] === 'unpaid') {
            $hasConfirmedUnpaid = true;
            if ($firstUnpaidMonth === null) {
                $firstUnpaidMonth = ((int)$quarterRow['quarter_number'] - 1) * 3 + 1;
            }
        }
    }
    $quarterStatusStmt->close();
    if ($quarterCount !== 4) {
        throw new RuntimeException('The assessment must contain exactly four quarterly payment records.');
    }

    $overallStatus = $hasConfirmedUnpaid ? 'unpaid' : 'paid';
    $unpaidSince = !$hasConfirmedUnpaid
        ? null
        : sprintf('%04d-%02d-01 00:00:00', $permitYear, $firstUnpaidMonth);
    $syncStmt = $conn->prepare(
        'UPDATE business_entities
         SET permit_payment_status = ?, permit_unpaid_since = ?
         WHERE id = ?'
    );
    if (!$syncStmt) {
        throw new RuntimeException('Unable to synchronize the business permit status.');
    }
    $syncStmt->bind_param('ssi', $overallStatus, $unpaidSince, $businessEntityId);
    if (!$syncStmt->execute()) {
        throw new RuntimeException('Unable to synchronize the business permit status.');
    }
    $syncStmt->close();

    if (!logAdminActivitySecure($conn, $adminId, $logAction, 'business_entity', $businessEntityId, $description)) {
        throw new RuntimeException('Unable to record the permit change in the admin log.');
    }
    if (!$conn->commit()) {
        throw new RuntimeException('Unable to commit the permit update.');
    }
    $transactionStarted = false;
    nxBusinessPermitRedirect(true, $action === 'save_assessment'
        ? 'Annual permit assessment saved. Quarterly amounts are now available.'
        : 'Quarterly permit payment updated; overall business access status has been synchronized.', $businessEntityId);
} catch (Throwable $error) {
    if ($transactionStarted) {
        $conn->rollback();
    }
    error_log('NexGen business permit payment update failed: ' . $error->getMessage());
    nxBusinessPermitRedirect(false, $error->getMessage(), $businessEntityId);
}

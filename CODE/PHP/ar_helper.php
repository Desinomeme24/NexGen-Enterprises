<?php
/* Shared Accounts Receivable (AR) helpers.
   The interface may use the session flag for display, but sale-writing
   endpoints pass the database connection so revoked access takes effect
   immediately and cannot be bypassed with a forged request. */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('nxArEnabled')) {
    function nxArEnabled(?mysqli $conn = null, ?int $userId = null): bool
    {
        $resolvedUserId = (int)($userId ?? ($_SESSION['user_id'] ?? 0));

        if ($conn instanceof mysqli && $resolvedUserId > 0) {
            $stmt = $conn->prepare(
                "SELECT can_accounts_receivable, account_status
                 FROM users
                 WHERE id = ?
                 LIMIT 1"
            );

            if (!$stmt) {
                error_log('NexGen AR permission check could not be prepared.');
                return false;
            }

            $stmt->bind_param('i', $resolvedUserId);
            if (!$stmt->execute()) {
                $stmt->close();
                error_log('NexGen AR permission check could not be executed.');
                $_SESSION['can_accounts_receivable'] = 0;
                return false;
            }
            $result = $stmt->get_result();
            $account = $result ? $result->fetch_assoc() : null;
            $stmt->close();

            $enabled = $account
                && ($account['account_status'] ?? '') === 'active'
                && (int)($account['can_accounts_receivable'] ?? 0) === 1;

            $_SESSION['can_accounts_receivable'] = $enabled ? 1 : 0;
            return $enabled;
        }

        return (int)($_SESSION['can_accounts_receivable'] ?? 0) === 1;
    }
}

if (!function_exists('nxValidateReceivableDueDate')) {
    function nxValidateReceivableDueDate(string $dueDate): string
    {
        $dueDate = trim($dueDate);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate);
        $errors = DateTimeImmutable::getLastErrors();
        $hasErrors = is_array($errors)
            && ((int)$errors['warning_count'] > 0 || (int)$errors['error_count'] > 0);

        if (!$parsed || $hasErrors || $parsed->format('Y-m-d') !== $dueDate) {
            throw new RuntimeException('Provide a valid due date for the receivable.');
        }

        $today = new DateTimeImmutable('today');
        if ($parsed < $today) {
            throw new RuntimeException('The due date cannot be earlier than today.');
        }

        return $dueDate;
    }
}

if (!function_exists('nxGenerateCustomerCode')) {
    function nxGenerateCustomerCode(mysqli $conn, int $businessId): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = 'CUS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $stmt = $conn->prepare(
                'SELECT 1 FROM customers WHERE business_id = ? AND customer_code = ? LIMIT 1'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to validate the customer code.');
            }
            $stmt->bind_param('is', $businessId, $code);
            $stmt->execute();
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            if (!$exists) {
                return $code;
            }
        }

        throw new RuntimeException('Unable to generate a unique customer code. Please try again.');
    }
}

if (!function_exists('nxResolveSaleCustomer')) {
    function nxResolveSaleCustomer(
        mysqli $conn,
        int $businessId,
        int $customerId,
        array $input,
        bool $required
    ): ?int {
        if ($customerId > 0) {
            $stmt = $conn->prepare(
                "SELECT id
                 FROM customers
                 WHERE id = ? AND business_id = ? AND status = 1
                 LIMIT 1
                 FOR UPDATE"
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to validate the selected customer.');
            }
            $stmt->bind_param('ii', $customerId, $businessId);
            $stmt->execute();
            $customer = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$customer) {
                throw new RuntimeException('The selected customer is not active in this business branch.');
            }

            return (int)$customer['id'];
        }

        if (!$required) {
            return null;
        }

        $name = trim((string)($input['customer_name'] ?? ''));
        $phone = trim((string)($input['customer_phone'] ?? ''));
        $email = trim((string)($input['customer_email'] ?? ''));
        $address = trim((string)($input['customer_address'] ?? ''));
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        $addressLength = function_exists('mb_strlen') ? mb_strlen($address, 'UTF-8') : strlen($address);

        if ($nameLength < 2 || $nameLength > 150) {
            throw new RuntimeException('Customer name must be between 2 and 150 characters.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $name . $phone . $email . $address)) {
            throw new RuntimeException('Customer information contains invalid characters.');
        }
        if ($phone !== '' && !preg_match('/^[0-9+().\- ]{7,20}$/', $phone)) {
            throw new RuntimeException('Provide a valid customer phone number.');
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100)) {
            throw new RuntimeException('Provide a valid customer email address.');
        }
        if ($addressLength > 500) {
            throw new RuntimeException('Customer address must not exceed 500 characters.');
        }

        $customerCode = nxGenerateCustomerCode($conn, $businessId);
        $phoneForDb = $phone !== '' ? $phone : null;
        $emailForDb = $email !== '' ? $email : null;
        $addressForDb = $address !== '' ? $address : null;

        $stmt = $conn->prepare(
            "INSERT INTO customers
                (business_id, customer_code, customer_name, phone, email, address, status)
             VALUES (?, ?, ?, ?, ?, ?, 1)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the new customer record.');
        }
        $stmt->bind_param(
            'isssss',
            $businessId,
            $customerCode,
            $name,
            $phoneForDb,
            $emailForDb,
            $addressForDb
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to save the new customer record.');
        }
        $newCustomerId = (int)$stmt->insert_id;
        $stmt->close();

        return $newCustomerId;
    }
}
?>

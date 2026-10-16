<?php

function nxcb_receivables(mysqli $conn, array $args, array $ctx): array {
    if (!nxcb_permission($ctx, 'accounts_receivable')) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }

    $businessId = (int)$ctx['business_id'];
    $view = $args['view'] ?? 'summary';
    $limit = nxcb_clamp_int($args['limit'] ?? 10, 1, 20, 10);

    if ($view === 'summary') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS total_receivables,
                                       COALESCE(SUM(balance_due),0) AS total_balance_due,
                                       SUM(CASE WHEN balance_due > 0 AND due_date IS NOT NULL AND due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count,
                                       SUM(CASE WHEN balance_due > 0 AND amount_paid <= 0 AND NOT (due_date IS NOT NULL AND due_date < CURDATE()) THEN 1 ELSE 0 END) AS unpaid_count,
                                       SUM(CASE WHEN balance_due > 0 AND amount_paid > 0 AND NOT (due_date IS NOT NULL AND due_date < CURDATE()) THEN 1 ELSE 0 END) AS partial_count
                                FROM accounts_receivable WHERE business_id = ?");
        $stmt->bind_param('i', $businessId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();
        return [
            'ok' => true,
            'view' => 'summary',
            'total_receivables' => (int)($row['total_receivables'] ?? 0),
            'total_balance_due' => nxcb_money_number($row['total_balance_due'] ?? 0),
            'overdue_count' => (int)($row['overdue_count'] ?? 0),
            'unpaid_count' => (int)($row['unpaid_count'] ?? 0),
            'partial_count' => (int)($row['partial_count'] ?? 0),
        ];
    }

    $base = "SELECT c.customer_name, c.customer_code, s.sales_no, ar.total_amount,
                    ar.amount_paid, ar.balance_due, ar.due_date,
                    CASE WHEN ar.balance_due > 0 AND ar.due_date IS NOT NULL AND ar.due_date < CURDATE()
                         THEN DATEDIFF(CURDATE(), ar.due_date) ELSE 0 END AS days_overdue,
                    CASE
                      WHEN ar.balance_due <= 0 THEN 'Paid'
                      WHEN ar.due_date IS NOT NULL AND ar.due_date < CURDATE() AND ar.balance_due > 0 THEN 'Overdue'
                      WHEN ar.amount_paid > 0 AND ar.balance_due > 0 THEN 'Partially Paid'
                      ELSE 'Unpaid'
                    END AS live_status
             FROM accounts_receivable ar
             INNER JOIN customers c ON c.id = ar.customer_id AND c.business_id = ar.business_id
             INNER JOIN sales s ON s.id = ar.sale_id AND s.business_id = ar.business_id
             WHERE ar.business_id = ?";

    switch ($view) {
        case 'overdue':
            $sql = $base . " AND ar.balance_due > 0 AND ar.due_date IS NOT NULL AND ar.due_date < CURDATE()
                             ORDER BY ar.due_date ASC, ar.balance_due DESC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $businessId, $limit);
            break;

        case 'followup_priority':
            $sql = $base . " AND ar.balance_due > 0
                             ORDER BY CASE
                               WHEN ar.due_date IS NOT NULL AND ar.due_date < CURDATE() THEN 1
                               WHEN ar.amount_paid > 0 THEN 2
                               ELSE 3
                             END,
                             ar.due_date ASC, ar.balance_due DESC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $businessId, $limit);
            break;

        case 'largest_balance':
            $sql = $base . " AND ar.balance_due > 0 ORDER BY ar.balance_due DESC, ar.due_date ASC LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('i', $businessId);
            break;

        case 'customer_search':
            $q = trim((string)($args['customer_query'] ?? ''));
            if ($q === '') return ['ok' => false, 'error' => 'missing_customer_query'];
            $like = '%' . $q . '%';
            $sql = $base . " AND (c.customer_name LIKE ? OR c.customer_code LIKE ? OR s.sales_no LIKE ?)
                             ORDER BY ar.balance_due DESC, ar.due_date ASC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('isssi', $businessId, $like, $like, $like, $limit);
            break;

        case 'unpaid':
        default:
            $sql = $base . " AND ar.balance_due > 0 ORDER BY ar.due_date ASC, ar.balance_due DESC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $businessId, $limit);
            break;
    }

    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($items as &$row) {
        $row['total_amount'] = nxcb_money_number($row['total_amount'] ?? 0);
        $row['amount_paid'] = nxcb_money_number($row['amount_paid'] ?? 0);
        $row['balance_due'] = nxcb_money_number($row['balance_due'] ?? 0);
        $row['days_overdue'] = (int)($row['days_overdue'] ?? 0);
    }

    return ['ok' => true, 'view' => $view, 'count' => count($items), 'items' => $items];
}
<?php

function nxcb_inventory_products(mysqli $conn, array $args, array $ctx): array {
    if (!nxcb_permission($ctx, 'inventory')) {
        return ['ok' => false, 'error' => 'permission_denied', 'message' => 'Inventory access is not available for this account.'];
    }

    $businessId = (int)$ctx['business_id'];
    $filter = $args['filter'] ?? 'search';
    $limit = nxcb_clamp_int($args['limit'] ?? 10, 1, 20, 10);

    $baseSelect = "SELECT p.id, p.product_code, p.product_name, p.brand, p.unit,
                          p.stock_quantity, p.reorder_level, p.on_order_level,
                          p.expiry_date, p.created_at, c.category_name
                   FROM products p
                   LEFT JOIN categories c ON c.id = p.category_id AND c.business_id = p.business_id
                   WHERE p.business_id = ? AND p.is_active = 1";

    $items = [];

    switch ($filter) {
        case 'search':
            $query = trim((string)($args['query'] ?? ''));
            if ($query === '') {
                return ['ok' => false, 'error' => 'missing_query', 'message' => 'A product name, code, or brand is required.'];
            }
            $like = '%' . $query . '%';
            $sql = $baseSelect . " AND (p.product_name LIKE ? OR p.product_code LIKE ? OR p.brand LIKE ?)
                                  ORDER BY CASE WHEN p.product_name = ? OR p.product_code = ? THEN 0 ELSE 1 END,
                                           p.product_name ASC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('isssssi', $businessId, $like, $like, $like, $query, $query, $limit);
            break;

        case 'low_stock':
            $sql = $baseSelect . " AND p.stock_quantity > 0 AND p.stock_quantity <= p.reorder_level
                                  ORDER BY p.stock_quantity ASC, p.product_name ASC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $businessId, $limit);
            break;

        case 'out_of_stock':
            $sql = $baseSelect . " AND p.stock_quantity <= 0
                                  ORDER BY p.product_name ASC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $businessId, $limit);
            break;

        case 'near_reorder':
            $sql = $baseSelect . " AND p.stock_quantity > p.reorder_level
                                  AND p.stock_quantity <= (p.reorder_level + 3)
                                  ORDER BY p.stock_quantity ASC, p.product_name ASC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $businessId, $limit);
            break;

        case 'on_order':
            $sql = $baseSelect . " AND p.on_order_level > 0
                                  ORDER BY p.on_order_level DESC, p.product_name ASC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $businessId, $limit);
            break;

        case 'expired':
            $effectiveExpirySql = "COALESCE(p.expiry_date, (SELECT MIN(pb.expiry_date)
                FROM product_batches pb
                WHERE pb.product_id = p.id
                  AND pb.business_id = p.business_id
                  AND pb.status = 'active'))";
            $sql = $baseSelect . " AND {$effectiveExpirySql} IS NOT NULL AND {$effectiveExpirySql} < CURDATE()
                                  ORDER BY {$effectiveExpirySql} ASC, p.product_name ASC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $businessId, $limit);
            break;

        case 'expiring':
            $days = nxcb_clamp_int($args['days'] ?? 30, 1, 365, 30);
            $sql = $baseSelect . " AND p.expiry_date IS NOT NULL
                                  AND p.expiry_date >= CURDATE()
                                  AND p.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                                  ORDER BY p.expiry_date ASC, p.product_name ASC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iii', $businessId, $days, $limit);
            break;

        case 'recently_added':
            $sql = $baseSelect . " ORDER BY p.created_at DESC, p.id DESC LIMIT ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('ii', $businessId, $limit);
            break;

        default:
            return ['ok' => false, 'error' => 'invalid_filter', 'message' => 'Unknown inventory filter.'];
    }

    if (!$stmt || !$stmt->execute()) {
        return ['ok' => false, 'error' => 'database_error', 'message' => 'Unable to read inventory data.'];
    }

    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['stock_quantity'] = (int)$row['stock_quantity'];
        $row['reorder_level'] = (int)$row['reorder_level'];
        $row['on_order_level'] = (int)$row['on_order_level'];
        $items[] = $row;
    }
    $stmt->close();

    return [
        'ok' => true,
        'filter' => $filter,
        'today' => date('Y-m-d'),
        'count' => count($items),
        'items' => $items,
    ];
}

function nxcb_stock_activity(mysqli $conn, array $args, array $ctx): array {
    if (!nxcb_permission($ctx, 'inventory')) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }

    $businessId = (int)$ctx['business_id'];
    $movement = $args['movement_type'] ?? 'all';
    $limit = nxcb_clamp_int($args['limit'] ?? 10, 1, 20, 10);
    $period = $args['period'] ?? 'recent';

    $base = "SELECT sm.movement_type, sm.quantity, sm.remarks, sm.created_at,
                    p.product_name, p.product_code
             FROM stock_movements sm
             INNER JOIN products p ON p.id = sm.product_id AND p.business_id = sm.business_id
             WHERE sm.business_id = ?";

    if ($period === 'recent') {
        if (in_array($movement, ['stock_in','stock_out'], true)) {
            $sql = $base . " AND sm.movement_type = ? ORDER BY sm.created_at DESC, sm.id DESC LIMIT ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) return ['ok' => false, 'error' => 'database_error'];
            $stmt->bind_param('isi', $businessId, $movement, $limit);
        } else {
            $sql = $base . " ORDER BY sm.created_at DESC, sm.id DESC LIMIT ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) return ['ok' => false, 'error' => 'database_error'];
            $stmt->bind_param('ii', $businessId, $limit);
        }
        $range = ['label' => 'Recent Stock Activity', 'period' => 'recent'];
    } else {
        $range = nxcb_range($args, 'this_week');
        if (!empty($range['error'])) return ['ok' => false, 'error' => $range['error']];
        $sql = $base . " AND sm.created_at BETWEEN ? AND ?";

        if (in_array($movement, ['stock_in','stock_out'], true)) {
            $sql .= " AND sm.movement_type = ? ORDER BY sm.created_at DESC, sm.id DESC LIMIT ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) return ['ok' => false, 'error' => 'database_error'];
            $stmt->bind_param('isssi', $businessId, $range['start'], $range['end'], $movement, $limit);
        } else {
            $sql .= " ORDER BY sm.created_at DESC, sm.id DESC LIMIT ?";
            $stmt = $conn->prepare($sql);
            if (!$stmt) return ['ok' => false, 'error' => 'database_error'];
            $stmt->bind_param('issi', $businessId, $range['start'], $range['end'], $limit);
        }
    }

    if (!$stmt->execute()) {
        $stmt->close();
        return ['ok' => false, 'error' => 'database_error'];
    }

    $items = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['quantity'] = (int)$row['quantity'];
        $items[] = $row;
    }
    $stmt->close();

    return [
        'ok' => true,
        'movement_type' => $movement,
        'range' => $range,
        'count' => count($items),
        'items' => $items,
    ];
}


function nxcb_product_lookup(mysqli $conn, int $businessId, string $query): ?array {
    $like = '%' . $query . '%';
    $stmt = $conn->prepare("SELECT id, product_name, product_code, stock_quantity, reorder_level, expiry_date
                            FROM products
                            WHERE business_id = ? AND is_active = 1
                              AND (product_name LIKE ? OR product_code LIKE ? OR brand LIKE ?)
                            ORDER BY CASE WHEN product_name = ? OR product_code = ? THEN 0 ELSE 1 END,
                                     product_name ASC LIMIT 1");
    $stmt->bind_param('isssss', $businessId, $like, $like, $like, $query, $query);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}


function nxcb_product_history(mysqli $conn, array $args, array $ctx): array {
    if (!nxcb_permission($ctx, 'inventory')) {
        return ['ok' => false, 'error' => 'permission_denied'];
    }

    $businessId = (int)$ctx['business_id'];
    $event = $args['event'] ?? 'last_stock_in';
    $query = trim((string)($args['product_query'] ?? ''));
    if ($query === '') return ['ok' => false, 'error' => 'missing_product_query'];

    $product = nxcb_product_lookup($conn, $businessId, $query);
    if (!$product) {
        return ['ok' => true, 'found' => false, 'query' => $query, 'event' => $event];
    }

    if ($event === 'last_sale') {
        $stmt = $conn->prepare("SELECT s.sale_date, s.sales_no, si.quantity
                                FROM sale_items si
                                INNER JOIN sales s ON s.id = si.sale_id
                                WHERE s.business_id = ? AND si.product_id = ?
                                ORDER BY s.sale_date DESC, s.id DESC LIMIT 1");
        $stmt->bind_param('ii', $businessId, $product['id']);
    } else {
        $stmt = $conn->prepare("SELECT sm.created_at, sm.quantity, sm.remarks
                                FROM stock_movements sm
                                WHERE sm.business_id = ? AND sm.product_id = ?
                                  AND sm.movement_type = 'stock_in'
                                ORDER BY sm.created_at DESC, sm.id DESC LIMIT 1");
        $stmt->bind_param('ii', $businessId, $product['id']);
    }
    $stmt->execute();
    $record = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'ok' => true,
        'found' => true,
        'event' => $event,
        'product' => $product,
        'record' => $record ?: null,
    ];
}
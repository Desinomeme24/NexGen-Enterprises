<?php

function nxcb_clamp_int($value, int $min, int $max, int $default): int {
    if (!is_numeric($value)) return $default;
    $value = (int)$value;
    return max($min, min($max, $value));
}

function nxcb_valid_date(?string $date): ?string {
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) return null;
    return $date;
}

function nxcb_range(array $args, string $defaultPeriod = 'today'): array {
    $period = $args['period'] ?? $defaultPeriod;
    $today = new DateTime('today');

    switch ($period) {
        case 'yesterday':
            $start = (clone $today)->modify('-1 day');
            $end = clone $start;
            $label = 'Yesterday';
            break;
        case 'this_week':
            $start = (clone $today)->modify('monday this week');
            $end = (clone $start)->modify('sunday this week');
            $label = 'This Week';
            break;
        case 'last_week':
            $start = (clone $today)->modify('monday last week');
            $end = (clone $start)->modify('sunday last week');
            $label = 'Last Week';
            break;
        case 'this_month':
            $start = new DateTime('first day of this month');
            $end = new DateTime('last day of this month');
            $label = 'This Month';
            break;
        case 'last_month':
            $start = new DateTime('first day of last month');
            $end = new DateTime('last day of last month');
            $label = 'Last Month';
            break;
        case 'this_year':
            $year = (int)date('Y');
            $start = new DateTime($year . '-01-01');
            $end = new DateTime($year . '-12-31');
            $label = 'This Year';
            break;
        case 'last_year':
            $year = (int)date('Y') - 1;
            $start = new DateTime($year . '-01-01');
            $end = new DateTime($year . '-12-31');
            $label = 'Last Year';
            break;
        case 'custom':
            $startDate = nxcb_valid_date($args['start_date'] ?? null);
            $endDate = nxcb_valid_date($args['end_date'] ?? null);
            if (!$startDate || !$endDate) {
                return ['error' => 'custom period requires valid start_date and end_date in YYYY-MM-DD format'];
            }
            $start = new DateTime($startDate);
            $end = new DateTime($endDate);
            if ($end < $start) {
                return ['error' => 'end_date must not be earlier than start_date'];
            }
            $label = $start->format('M d, Y') . ' to ' . $end->format('M d, Y');
            break;
        case 'today':
        default:
            $start = clone $today;
            $end = clone $today;
            $label = 'Today';
            break;
    }

    return [
        'start' => $start->format('Y-m-d 00:00:00'),
        'end' => $end->format('Y-m-d 23:59:59'),
        'label' => $label,
        'period' => $period,
    ];
}

function nxcb_json_tool_result(array $data): string {
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function nxcb_money_number($value): float {
    return round((float)$value, 2);
}

function nxcb_permission(array $ctx, string $key): bool {
    return !empty($ctx['permissions'][$key]);
}
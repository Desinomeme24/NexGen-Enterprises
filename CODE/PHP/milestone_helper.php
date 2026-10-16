<?php


if (!function_exists('nxMilestoneThresholds')) {
    function nxMilestoneThresholds(): array
    {
        /*
         * Use deliberate milestone tiers instead of extending the final gap
         * forever. This keeps achievements meaningful at higher sales values
         * (for example, 500K instead of an odd 807K milestone).
         */
        return [
            'today'       => [500, 1000, 1500, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000, 2000000],
            'week'        => [3000, 6000, 9000, 15000, 25000, 50000, 100000, 250000, 500000, 1000000, 2000000, 5000000],
            'month'       => [10000, 20000, 50000, 100000, 250000, 500000, 1000000, 2000000, 5000000, 10000000],
            'quarter'     => [50000, 100000, 250000, 500000, 1000000, 2000000, 5000000, 10000000],
            'semi_annual' => [100000, 250000, 500000, 1000000, 2000000, 5000000, 10000000, 25000000],
            'annual'      => [250000, 500000, 1000000, 2000000, 5000000, 10000000, 25000000, 50000000],
        ];
    }
}

if (!function_exists('nxCurrentMilestone')) {
   
    function nxCurrentMilestone(float $amount, array $thresholds): ?int
    {
        if (empty($thresholds)) {
            return null;
        }

        sort($thresholds);
        $count = count($thresholds);

        if ($amount < $thresholds[0]) {
            return null;
        }

        $reached = null;
        foreach ($thresholds as $t) {
            if ($amount >= $t) {
                $reached = $t;
            } else {
                break;
            }
        }

        /*
         * Do not auto-create arbitrary thresholds beyond the configured tiers.
         * The highest configured tier reached is the milestone for this period.
         */
        return $reached;
    }
}

if (!function_exists('nxMilestoneLabel')) {
    function nxMilestoneLabel(int $amount): string
    {
        if ($amount >= 1000000) {
            return rtrim(rtrim(number_format($amount / 1000000, 2), '0'), '.') . 'M';
        }
        if ($amount >= 1000) {
            return rtrim(rtrim(number_format($amount / 1000, 1), '0'), '.') . 'K';
        }
        return number_format($amount);
    }
}

if (!function_exists('nxRevenueForRange')) {
    function nxRevenueForRange(mysqli $conn, int $businessId, string $start, string $end): float
    {
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(total_amount), 0) AS revenue
            FROM sales
            WHERE business_id = ? AND sale_date BETWEEN ? AND ?
        ");
        if (!$stmt) {
            return 0.0;
        }
        $stmt->bind_param("iss", $businessId, $start, $end);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (float)($row['revenue'] ?? 0);
    }
}



if (!function_exists('nxQuarterRange')) {
    /** [start DateTime, end DateTime, quarter number, year] containing $asOf. */
    function nxQuarterRange(DateTime $asOf): array
    {
        $year = (int)$asOf->format('Y');
        $month = (int)$asOf->format('n');
        $quarter = (int)ceil($month / 3);
        $startMonth = (($quarter - 1) * 3) + 1;

        $start = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $startMonth));
        $end = (clone $start)->modify('+2 months')->modify('last day of this month')->setTime(23, 59, 59);

        return [$start, $end, $quarter, $year];
    }
}

if (!function_exists('nxPreviousQuarterRange')) {
    /** [start, end, quarter number, year] for the quarter immediately before $asOf's quarter. */
    function nxPreviousQuarterRange(DateTime $asOf): array
    {
        [$curStart, , ,] = nxQuarterRange($asOf);
        $anchor = (clone $curStart)->modify('-1 day');
        return nxQuarterRange($anchor);
    }
}

if (!function_exists('nxSemiAnnualRange')) {
    /** [start DateTime, end DateTime, half (1 or 2), year] containing $asOf. */
    function nxSemiAnnualRange(DateTime $asOf): array
    {
        $year = (int)$asOf->format('Y');
        $month = (int)$asOf->format('n');
        $half = $month <= 6 ? 1 : 2;

        if ($half === 1) {
            $start = new DateTime(sprintf('%04d-01-01 00:00:00', $year));
            $end = new DateTime(sprintf('%04d-06-30 23:59:59', $year));
        } else {
            $start = new DateTime(sprintf('%04d-07-01 00:00:00', $year));
            $end = new DateTime(sprintf('%04d-12-31 23:59:59', $year));
        }

        return [$start, $end, $half, $year];
    }
}

if (!function_exists('nxPreviousSemiAnnualRange')) {
    /** [start, end, half, year] for the half-year immediately before $asOf's half. */
    function nxPreviousSemiAnnualRange(DateTime $asOf): array
    {
        [$curStart, , ,] = nxSemiAnnualRange($asOf);
        $anchor = (clone $curStart)->modify('-1 day');
        return nxSemiAnnualRange($anchor);
    }
}

if (!function_exists('nxPreviousYearRange')) {
    /** [start DateTime, end DateTime, year] for the calendar year before $asOf's year. */
    function nxPreviousYearRange(DateTime $asOf): array
    {
        $year = (int)$asOf->format('Y') - 1;
        $start = new DateTime(sprintf('%04d-01-01 00:00:00', $year));
        $end = new DateTime(sprintf('%04d-12-31 23:59:59', $year));
        return [$start, $end, $year];
    }
}



if (!function_exists('nxCheckLiveMilestones')) {
    function nxCheckLiveMilestones(mysqli $conn, int $businessId, DateTime $asOf): void
    {
        $reachedAtStr = $asOf->format('Y-m-d H:i:s');

        $dayStart = (clone $asOf)->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $dayEnd = (clone $asOf)->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        $weekStart = (clone $asOf)->modify('monday this week')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $weekEnd = (clone $asOf)->modify('sunday this week')->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        $monthStart = (clone $asOf)->modify('first day of this month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $monthEnd = (clone $asOf)->modify('last day of this month')->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        $periods = [
            'today' => ['start' => $dayStart, 'end' => $dayEnd, 'bucket' => $asOf->format('Y-m-d')],
            'week'  => ['start' => $weekStart, 'end' => $weekEnd, 'bucket' => $asOf->format('o-\WW')],
            'month' => ['start' => $monthStart, 'end' => $monthEnd, 'bucket' => $asOf->format('Y-m')],
        ];

        $thresholdsByPeriod = nxMilestoneThresholds();

        $insertStmt = $conn->prepare("
            INSERT IGNORE INTO sales_milestones
                (business_id, period_type, period_bucket, threshold, actual_amount, reached_at)
            VALUES (?, ?, ?, ?, NULL, ?)
        ");
        if (!$insertStmt) {
            return;
        }

        foreach ($periods as $periodType => $info) {
            $revenue = nxRevenueForRange($conn, $businessId, $info['start'], $info['end']);
            $reached = nxCurrentMilestone($revenue, $thresholdsByPeriod[$periodType]);
            if ($reached === null) {
                continue;
            }

            $insertStmt->bind_param(
                "issis",
                $businessId,
                $periodType,
                $info['bucket'],
                $reached,
                $reachedAtStr
            );
            $insertStmt->execute();
        }

        $insertStmt->close();
    }
}



if (!function_exists('nxMilestoneBucketExists')) {
    function nxMilestoneBucketExists(mysqli $conn, int $businessId, string $periodType, string $bucket): bool
    {
        $stmt = $conn->prepare("
            SELECT id FROM sales_milestones
            WHERE business_id = ? AND period_type = ? AND period_bucket = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("iss", $businessId, $periodType, $bucket);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('nxRecordCompletedAchievement')) {
    function nxRecordCompletedAchievement(mysqli $conn, int $businessId, string $periodType, string $bucket, int $threshold, float $actualAmount, string $reachedAt): void
    {
        $stmt = $conn->prepare("
            INSERT IGNORE INTO sales_milestones
                (business_id, period_type, period_bucket, threshold, actual_amount, reached_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("issids", $businessId, $periodType, $bucket, $threshold, $actualAmount, $reachedAt);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('nxCheckCompletedPeriodAchievements')) {
    function nxCheckCompletedPeriodAchievements(mysqli $conn, int $businessId, DateTime $asOf): void
    {
        $thresholdsByPeriod = nxMilestoneThresholds();

        /* Quarter */
        [$qStart, $qEnd, $qNum, $qYear] = nxPreviousQuarterRange($asOf);
        $qBucket = $qYear . '-Q' . $qNum;
        if (!nxMilestoneBucketExists($conn, $businessId, 'quarter', $qBucket)) {
            $total = nxRevenueForRange($conn, $businessId, $qStart->format('Y-m-d H:i:s'), $qEnd->format('Y-m-d H:i:s'));
            $reached = nxCurrentMilestone($total, $thresholdsByPeriod['quarter']);
            if ($reached !== null) {
                nxRecordCompletedAchievement($conn, $businessId, 'quarter', $qBucket, $reached, $total, $qEnd->format('Y-m-d H:i:s'));
            }
        }

        /* Semi-Annual */
        [$hStart, $hEnd, $hNum, $hYear] = nxPreviousSemiAnnualRange($asOf);
        $hBucket = $hYear . '-H' . $hNum;
        if (!nxMilestoneBucketExists($conn, $businessId, 'semi_annual', $hBucket)) {
            $total = nxRevenueForRange($conn, $businessId, $hStart->format('Y-m-d H:i:s'), $hEnd->format('Y-m-d H:i:s'));
            $reached = nxCurrentMilestone($total, $thresholdsByPeriod['semi_annual']);
            if ($reached !== null) {
                nxRecordCompletedAchievement($conn, $businessId, 'semi_annual', $hBucket, $reached, $total, $hEnd->format('Y-m-d H:i:s'));
            }
        }

        /* Annual */
        [$yStart, $yEnd, $year] = nxPreviousYearRange($asOf);
        $yBucket = (string)$year;
        if (!nxMilestoneBucketExists($conn, $businessId, 'annual', $yBucket)) {
            $total = nxRevenueForRange($conn, $businessId, $yStart->format('Y-m-d H:i:s'), $yEnd->format('Y-m-d H:i:s'));
            $reached = nxCurrentMilestone($total, $thresholdsByPeriod['annual']);
            if ($reached !== null) {
                nxRecordCompletedAchievement($conn, $businessId, 'annual', $yBucket, $reached, $total, $yEnd->format('Y-m-d H:i:s'));
            }
        }
    }
}


if (!function_exists('nxCheckAndRecordMilestones')) {
    function nxCheckAndRecordMilestones(mysqli $conn, int $businessId, ?DateTime $asOf = null): void
    {
        $asOf = $asOf ?? new DateTime();
        nxCheckLiveMilestones($conn, $businessId, $asOf);
        nxCheckCompletedPeriodAchievements($conn, $businessId, $asOf);
    }
}
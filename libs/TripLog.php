<?php

declare(strict_types=1);

/**
 * Trip log from /tripinfo: the month list tells how many trips each day had, day details are
 * fetched only for days whose count changed (within the last FETCH_DAYS days). How long the log
 * is kept is configurable (0 = unlimited); the tile only gets the last FETCH_DAYS days.
 */
class TripLog
{
    public const FETCH_DAYS = 31;
    public const MAX_DAY_CALLS = 15;
    public const BACKFILL_MONTHS = 60;      // walk back at most five years
    public const BACKFILL_EMPTY_STOP = 3;   // three months in a row without trips end the walk

    /** Month answer (tripPeriodType 0) → ['Y-m-d' => trip count]. */
    public static function monthCounts(array $msg): array
    {
        $counts = [];
        foreach ($msg['tripDayList'] ?? [] as $day) {
            $date = DateTime::createFromFormat('!Ymd', (string) ($day['tripDayInMonth'] ?? ''));
            if ($date !== false) {
                $counts[$date->format('Y-m-d')] = (int) ($day['tripCntDay'] ?? 0);
            }
        }
        return $counts;
    }

    /**
     * Days within the fetch window whose trip count differs from the log, newest first. A shorter
     * retention narrows the window, otherwise pruned days would be fetched again every time.
     */
    public static function due(array $log, array $counts, string $today, int $keepDays = 0): array
    {
        $from = self::from($today, $keepDays > 0 ? min(self::FETCH_DAYS, $keepDays) : self::FETCH_DAYS);
        $due = [];
        foreach ($counts as $date => $count) {
            if ($date >= $from && $date <= $today && $count > 0 && (int) ($log[$date]['count'] ?? -1) !== $count) {
                $due[] = $date;
            }
        }
        rsort($due);
        return array_slice($due, 0, self::MAX_DAY_CALLS);
    }

    /** Day answer (tripPeriodType 1) → log entry, trips in chronological order. */
    public static function parseDay(array $msg): ?array
    {
        $day = $msg['dayTripList'][0] ?? null;
        if (!is_array($day)) {
            return null;
        }
        $trips = [];
        foreach ($day['tripList'] ?? [] as $trip) {
            $time = (string) ($trip['tripTime'] ?? '');
            $trips[] = [
                't'     => preg_match('/^(\d{2})(\d{2})/', $time, $m) ? $m[1] . ':' . $m[2] : '',
                'drive' => (int) ($trip['tripDrvTime'] ?? 0),
                'idle'  => (int) ($trip['tripIdleTime'] ?? 0),
                'dist'  => (float) ($trip['tripDist'] ?? 0),
                'avg'   => (float) ($trip['tripAvgSpeed'] ?? 0),
                'max'   => (float) ($trip['tripMaxSpeed'] ?? 0),
            ];
        }
        usort($trips, static fn (array $a, array $b): int => strcmp($a['t'], $b['t']));
        return [
            'count' => (int) ($day['dayTripCnt'] ?? count($trips)),
            'drive' => (int) ($day['tripDrvTime'] ?? 0),
            'idle'  => (int) ($day['tripIdleTime'] ?? 0),
            'dist'  => (float) ($day['tripDist'] ?? 0),
            'avg'   => (float) ($day['tripAvgSpeed'] ?? 0),
            'max'   => (float) ($day['tripMaxSpeed'] ?? 0),
            'trips' => $trips,
        ];
    }

    /**
     * Older days found by the backfill that are still missing from the log, newest first, within
     * the retention and at most $limit (the calls left for this run).
     */
    public static function backfillDue(array $pending, array $log, string $today, int $keepDays, int $limit): array
    {
        $from = $keepDays > 0 ? self::from($today, $keepDays) : '';
        $due = [];
        foreach ($pending as $date => $count) {
            $date = (string) $date;
            if ($date >= $from && (int) ($log[$date]['count'] ?? -1) !== (int) $count) {
                $due[] = $date;
            }
        }
        rsort($due);
        return array_slice($due, 0, max(0, $limit));
    }

    /** Pending backfill days that are done (in the log) or outside the retention are dropped. */
    public static function backfillLeft(array $pending, array $log, string $today, int $keepDays): array
    {
        $from = $keepDays > 0 ? self::from($today, $keepDays) : '';
        return array_filter($pending, static fn ($count, $date): bool => (string) $date >= $from
            && (int) ($log[(string) $date]['count'] ?? -1) !== (int) $count, ARRAY_FILTER_USE_BOTH);
    }

    /** Keep $keepDays days (0 = unlimited), newest first. */
    public static function prune(array $log, string $today, int $keepDays): array
    {
        if ($keepDays > 0) {
            $log = self::window($log, $today, $keepDays);
        }
        krsort($log);
        return $log;
    }

    /** The last $days days of the log, newest first. */
    public static function window(array $log, string $today, int $days = self::FETCH_DAYS): array
    {
        $from = self::from($today, $days);
        $log = array_filter($log, static fn ($date): bool => (string) $date >= $from, ARRAY_FILTER_USE_KEY);
        krsort($log);
        return $log;
    }

    private static function from(string $today, int $days): string
    {
        return date('Y-m-d', (int) strtotime($today . ' -' . (max(1, $days) - 1) . ' days'));
    }
}

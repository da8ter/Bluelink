<?php

declare(strict_types=1);

/**
 * Trip log from /tripinfo: the month list tells how many trips each day had, day details are
 * fetched only for days whose count changed. Kept for KEEP_DAYS days.
 */
class TripLog
{
    public const KEEP_DAYS = 31;
    public const MAX_DAY_CALLS = 15;

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

    /** Days within the window whose trip count differs from the log, newest first. */
    public static function due(array $log, array $counts, string $today): array
    {
        $from = date('Y-m-d', strtotime($today . ' -' . (self::KEEP_DAYS - 1) . ' days'));
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

    public static function prune(array $log, string $today): array
    {
        $from = date('Y-m-d', strtotime($today . ' -' . (self::KEEP_DAYS - 1) . ' days'));
        $log = array_filter($log, static fn (string $date): bool => $date >= $from, ARRAY_FILTER_USE_KEY);
        krsort($log);
        return $log;
    }
}

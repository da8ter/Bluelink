<?php

declare(strict_types=1);

/**
 * Daily driving statistics from /drvhistory (periodTarget 0 = last 30 days).
 *
 * Every completed day is booked once into running counters. A day is written to the
 * archive at its own end (23:59:59) when that lies after the last counter write;
 * otherwise (late data, corrections) the amount is added at the current time, so the
 * counters never go backwards in the archive.
 */
class DrivingStats
{
    /** Counter key => API field (Wh). */
    public const ENERGY_FIELDS = [
        'Consumption'  => 'totalPwrCsp',
        'Drive'        => 'motorPwrCsp',
        'Climate'      => 'climatePwrCsp',
        'Electronics'  => 'eDPwrCsp',
        'BatteryCare'  => 'batteryMgPwrCsp',
        'Regeneration' => 'regenPwr',
    ];
    public const DISTANCE_FIELD = 'calculativeOdo';
    public const KEYS = ['Consumption', 'Drive', 'Climate', 'Electronics', 'BatteryCare', 'Regeneration', 'Distance'];
    private const KEEP_DAYS = 45;

    /**
     * drivingInfoDetail → ['Y-m-d' => [key => kWh|km]], oldest first.
     */
    public static function parseDays(array $details): array
    {
        $days = [];
        foreach ($details as $day) {
            if (!is_array($day)) {
                continue;
            }
            $date = DateTime::createFromFormat('!Ymd', (string) ($day['drivingDate'] ?? ''));
            if ($date === false) {
                continue;
            }
            $values = [];
            foreach (self::ENERGY_FIELDS as $key => $field) {
                $values[$key] = round(max(0.0, (float) ($day[$field] ?? 0)) / 1000, 3);
            }
            $values['Distance'] = round(max(0.0, (float) ($day[self::DISTANCE_FIELD] ?? 0)), 1);
            $days[$date->format('Y-m-d')] = $values;
        }
        ksort($days);
        return $days;
    }

    /**
     * What to book. `$booked` = ['Y-m-d' => values] already counted, `$lastWrite` = time of the
     * last counter write (0 = never), `$today` = 'Y-m-d'.
     *
     * @return array{backfill: list<array{date: string, end: int, values: array}>, now: array, booked: array, lastDay: ?array}
     */
    public static function plan(array $days, array $booked, int $lastWrite, string $today): array
    {
        $backfill = [];
        $now = array_fill_keys(self::KEYS, 0.0);
        $lastDay = null;
        foreach ($days as $date => $values) {
            if ($date >= $today) {
                continue;
            }
            $lastDay = ['date' => $date, 'values' => $values];
            $old = $booked[$date] ?? null;
            if ($old === null) {
                $end = self::dayEnd($date);
                if ($end > $lastWrite) {
                    $backfill[] = ['date' => $date, 'end' => $end, 'values' => $values];
                    $lastWrite = $end;
                } else {
                    foreach (self::KEYS as $key) {
                        $now[$key] += $values[$key];
                    }
                }
            } else {
                foreach (self::KEYS as $key) {
                    $now[$key] += max(0.0, $values[$key] - (float) ($old[$key] ?? 0));
                }
            }
            $booked[$date] = $values;
        }
        ksort($booked);
        $booked = array_slice($booked, -self::KEEP_DAYS, null, true);
        return ['backfill' => $backfill, 'now' => $now, 'booked' => $booked, 'lastDay' => $lastDay];
    }

    public static function dayEnd(string $date): int
    {
        [$y, $m, $d] = array_map('intval', explode('-', $date));
        return (int) mktime(23, 59, 59, $m, $d, $y);
    }
}

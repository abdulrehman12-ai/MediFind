<?php

declare(strict_types=1);

namespace App\Pharmacy;

use App\Enums\HoursIntervalKind;

/**
 * Weekly opening intervals in Asia/Karachi wall-clock time.
 * Closed days are represented by the absence of intervals.
 * Ranges are half-open, so an interval ending at 13:00 does not overlap one starting at 13:00.
 */
final class WeeklyHours
{
    /**
     * @param  list<array{day_of_week: int, interval_kind: string, opens_at: ?string, closes_at: ?string}>  $intervals
     */
    public static function assertValid(array $intervals): void
    {
        /** @var list<array{0: int, 1: int}> $ranges */
        $ranges = [];

        foreach ($intervals as $interval) {
            foreach (self::rangesFor($interval) as $range) {
                foreach ($ranges as $existing) {
                    if ($range[0] < $existing[1] && $existing[0] < $range[1]) {
                        throw new InvalidOperatingHours('Opening intervals overlap.');
                    }
                }

                $ranges[] = $range;
            }
        }
    }

    /**
     * @param  array{day_of_week: int, interval_kind: string, opens_at: ?string, closes_at: ?string}  $interval
     * @return list<array{0: int, 1: int}>
     */
    private static function rangesFor(array $interval): array
    {
        $day = $interval['day_of_week'];

        if ($day < 1 || $day > 7) {
            throw new InvalidOperatingHours('Weekday must be from Monday (1) through Sunday (7).');
        }

        $start = ($day - 1) * 86400;
        $kind = $interval['interval_kind'];

        if ($kind === HoursIntervalKind::AllDay->value) {
            if ($interval['opens_at'] !== null || $interval['closes_at'] !== null) {
                throw new InvalidOperatingHours('A 24-hour day does not use opening or closing times.');
            }

            return [[$start, $start + 86400]];
        }

        $opens = self::seconds($interval['opens_at']);
        $closes = self::seconds($interval['closes_at']);

        if ($kind === HoursIntervalKind::SameDay->value) {
            if ($opens >= $closes) {
                throw new InvalidOperatingHours('A same-day interval must close after it opens.');
            }

            return [[$start + $opens, $start + $closes]];
        }

        if ($kind === HoursIntervalKind::Overnight->value) {
            if ($opens <= $closes) {
                throw new InvalidOperatingHours('An overnight interval must close on the next weekday.');
            }

            $next = ($day % 7) * 86400;

            return [
                [$start + $opens, $start + 86400],
                [$next, $next + $closes],
            ];
        }

        throw new InvalidOperatingHours('Opening interval type is invalid.');
    }

    private static function seconds(?string $time): int
    {
        if ($time === null || preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $time, $matches) !== 1) {
            throw new InvalidOperatingHours('Opening and closing times must use 24-hour clock values.');
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = (int) ($matches[3] ?? 0);

        if ($hours > 23 || $minutes > 59 || $seconds > 59) {
            throw new InvalidOperatingHours('Opening and closing times must use 24-hour clock values.');
        }

        return ($hours * 3600) + ($minutes * 60) + $seconds;
    }
}

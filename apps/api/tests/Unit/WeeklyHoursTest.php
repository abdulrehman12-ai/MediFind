<?php

namespace Tests\Unit;

use App\Pharmacy\InvalidOperatingHours;
use App\Pharmacy\WeeklyHours;
use PHPUnit\Framework\TestCase;

class WeeklyHoursTest extends TestCase
{
    public function test_closed_week_and_twenty_four_hour_day_are_valid(): void
    {
        WeeklyHours::assertValid([]);
        WeeklyHours::assertValid([
            ['day_of_week' => 1, 'interval_kind' => 'all_day', 'opens_at' => null, 'closes_at' => null],
        ]);

        $this->addToAssertionCount(2);
    }

    public function test_split_shift_and_adjacent_intervals_are_valid(): void
    {
        WeeklyHours::assertValid([
            ['day_of_week' => 2, 'interval_kind' => 'same_day', 'opens_at' => '09:00', 'closes_at' => '13:00'],
            ['day_of_week' => 2, 'interval_kind' => 'same_day', 'opens_at' => '13:00', 'closes_at' => '17:00'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function test_overlapping_split_and_overnight_intervals_are_rejected(): void
    {
        $this->expectException(InvalidOperatingHours::class);
        WeeklyHours::assertValid([
            ['day_of_week' => 7, 'interval_kind' => 'overnight', 'opens_at' => '22:00', 'closes_at' => '06:00'],
            ['day_of_week' => 1, 'interval_kind' => 'same_day', 'opens_at' => '05:00', 'closes_at' => '08:00'],
        ]);
    }

    public function test_equal_times_and_all_day_with_clock_times_are_rejected(): void
    {
        try {
            WeeklyHours::assertValid([
                ['day_of_week' => 3, 'interval_kind' => 'same_day', 'opens_at' => '00:00', 'closes_at' => '00:00'],
            ]);
            $this->fail('Equal clock times were accepted.');
        } catch (InvalidOperatingHours) {
        }

        $this->expectException(InvalidOperatingHours::class);
        WeeklyHours::assertValid([
            ['day_of_week' => 3, 'interval_kind' => 'all_day', 'opens_at' => '00:00', 'closes_at' => '23:59'],
        ]);
    }
}

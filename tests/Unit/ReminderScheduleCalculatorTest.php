<?php

namespace Tests\Unit;

use App\Services\ReminderScheduleCalculator;
use Carbon\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class ReminderScheduleCalculatorTest extends TestCase
{
    public function test_it_schedules_later_today_when_time_is_in_the_future(): void
    {
        $after = Carbon::parse('2026-03-10 06:00:00', 'UTC');
        $next = ReminderScheduleCalculator::calculateNext('Asia/Damascus', '10:00', $after);

        $this->assertTrue($next->gt($after));
        $this->assertSame('10:00', $next->copy()->timezone('Asia/Damascus')->format('H:i'));
    }

    public function test_it_rolls_to_the_next_day_when_todays_time_has_passed(): void
    {
        $after = Carbon::parse('2026-03-10 20:00:00', 'Asia/Damascus')->utc();
        $next = ReminderScheduleCalculator::calculateNext('Asia/Damascus', '06:00', $after);

        $this->assertSame('2026-03-11', $next->copy()->timezone('Asia/Damascus')->toDateString());
        $this->assertSame('06:00', $next->copy()->timezone('Asia/Damascus')->format('H:i'));
    }

    public function test_it_handles_midnight_crossing(): void
    {
        $after = Carbon::parse('2026-01-01 23:50:00', 'Europe/Paris')->utc();
        $next = ReminderScheduleCalculator::calculateNext('Europe/Paris', '00:10', $after);

        $this->assertSame('2026-01-02 00:10', $next->copy()->timezone('Europe/Paris')->format('Y-m-d H:i'));
    }

    public function test_dst_spring_forward_still_returns_a_valid_instant(): void
    {
        $after = Carbon::parse('2026-03-28 12:00:00', 'Europe/Paris')->utc();
        $next = ReminderScheduleCalculator::calculateNext('Europe/Paris', '06:00', $after);

        $this->assertSame('2026-03-29', $next->copy()->timezone('Europe/Paris')->toDateString());
        $this->assertTrue($next->gt($after));
    }

    public function test_it_rejects_invalid_times(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ReminderScheduleCalculator::normalizeTime('25:61');
    }

    public function test_is_valid_time(): void
    {
        $this->assertTrue(ReminderScheduleCalculator::isValidTime('06:30'));
        $this->assertFalse(ReminderScheduleCalculator::isValidTime('ab:cd'));
    }
}

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

    public function test_it_normalizes_arabic_digits_and_short_hours(): void
    {
        $this->assertSame('05:30:00', ReminderScheduleCalculator::normalizeTime('5:30'));
        $this->assertSame('05:30:00', ReminderScheduleCalculator::normalizeTime('٥:٣٠'));
        $this->assertSame('05:30:00', ReminderScheduleCalculator::normalizeTime('۰۵:۳۰'));
    }

    public function test_it_applies_period_suffixes_and_formats_twelve_hour_labels(): void
    {
        $this->assertSame('20:00:00', ReminderScheduleCalculator::normalizeTime('8:00 م'));
        $this->assertSame('20:00:00', ReminderScheduleCalculator::normalizeTime('08:00 مساء'));
        $this->assertSame('08:00:00', ReminderScheduleCalculator::normalizeTime('8 ص'));
        $this->assertSame('00:00:00', ReminderScheduleCalculator::normalizeTime('12 ص'));
        $this->assertSame('12:00:00', ReminderScheduleCalculator::normalizeTime('12 م'));
        $this->assertSame('15:00:00', ReminderScheduleCalculator::normalizeTime('3:00 pm'));

        $this->assertSame('12:30 ص', ReminderScheduleCalculator::format12Hour('00:30:00'));
        $this->assertSame('8:00 ص', ReminderScheduleCalculator::format12Hour('08:00:00'));
        $this->assertSame('12:01 م', ReminderScheduleCalculator::format12Hour('12:01:00'));
        $this->assertSame('8:00 م', ReminderScheduleCalculator::format12Hour('20:00:00'));
    }

    public function test_it_asks_before_saving_an_ambiguous_evening_or_noon_time(): void
    {
        $evening = ReminderScheduleCalculator::classifyTime('08:00', 'evening');
        $this->assertSame('confirm', $evening['action']);
        $this->assertSame('08:00:00', $evening['options'][0]['time']);
        $this->assertSame('20:00:00', $evening['options'][1]['time']);
        $this->assertSame('8:00 ص', $evening['options'][0]['label']);
        $this->assertSame('8:00 م', $evening['options'][1]['label']);

        $noon = ReminderScheduleCalculator::classifyTime('12:01', 'morning');
        $this->assertSame('confirm', $noon['action']);
        $this->assertSame('00:01:00', $noon['options'][0]['time']);
        $this->assertSame('12:01:00', $noon['options'][1]['time']);

        $morning = ReminderScheduleCalculator::classifyTime('05:30', 'morning');
        $this->assertSame('save', $morning['action']);
        $this->assertSame('05:30:00', $morning['time']);

        $explicit = ReminderScheduleCalculator::classifyTime('8:00 م', 'evening');
        $this->assertSame('save', $explicit['action']);
        $this->assertSame('20:00:00', $explicit['time']);
    }

    public function test_stale_window_outlasts_telegram_timeout_and_buffer(): void
    {
        $timeout = (int) config('telegram.timeout', 15);
        $queueLatencySeconds = 30;
        $processingBufferSeconds = 60;

        $this->assertGreaterThanOrEqual(
            $timeout + $queueLatencySeconds + $processingBufferSeconds,
            (int) config('adhkar.reminder_stale_minutes') * 60,
        );
    }
}

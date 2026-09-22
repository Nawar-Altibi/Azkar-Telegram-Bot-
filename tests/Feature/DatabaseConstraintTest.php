<?php

namespace Tests\Feature;

use App\Enums\ReminderType;
use App\Models\ReminderLog;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Tests\TestCase;

class DatabaseConstraintTest extends TestCase
{
    public function test_telegram_id_is_unique(): void
    {
        User::factory()->create(['telegram_id' => 555]);
        $this->expectException(QueryException::class);
        User::factory()->create(['telegram_id' => 555, 'chat_id' => 556]);
    }

    public function test_reminder_log_day_is_unique(): void
    {
        $user = User::factory()->create();
        ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Morning,
            'scheduled_local_date' => '2026-09-22',
        ]);

        $this->expectException(QueryException::class);
        ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Morning,
            'scheduled_local_date' => '2026-09-22',
        ]);
    }

    public function test_telegram_update_id_is_unique(): void
    {
        TelegramUpdate::factory()->create(['update_id' => 9]);
        $this->expectException(QueryException::class);
        TelegramUpdate::factory()->create(['update_id' => 9]);
    }
}

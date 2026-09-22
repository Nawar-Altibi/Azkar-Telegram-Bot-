<?php

namespace Database\Factories;

use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Models\ReminderLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderLog>
 */
class ReminderLogFactory extends Factory
{
    protected $model = ReminderLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'reminder_type' => ReminderType::Morning,
            'collection_key' => 'morning_short',
            'scheduled_local_date' => now()->toDateString(),
            'scheduled_for' => now(),
            'status' => ReminderLogStatus::Pending,
            'telegram_message_id' => null,
            'sent_at' => null,
            'completed_at' => null,
            'error_message' => null,
            'retry_count' => 0,
            'dispatch_attempts' => 0,
            'content_version_map' => null,
            'content_snapshot_hash' => null,
        ];
    }
}

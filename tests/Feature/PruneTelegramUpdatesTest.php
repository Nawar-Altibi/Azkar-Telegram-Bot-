<?php

namespace Tests\Feature;

use App\Enums\TelegramUpdateStatus;
use App\Jobs\SendAdhkarReminderJob;
use App\Models\TelegramUpdate;
use Tests\TestCase;

class PruneTelegramUpdatesTest extends TestCase
{
    public function test_only_finished_updates_past_retention_are_deleted(): void
    {
        $old = now()->subDays(31);
        $recent = now()->subDays(29);

        $this->update(1, TelegramUpdateStatus::Processed, $old);
        $this->update(2, TelegramUpdateStatus::Failed, $old);
        $this->update(3, TelegramUpdateStatus::Pending, $old);
        $this->update(4, TelegramUpdateStatus::Processing, $old);
        $this->update(5, TelegramUpdateStatus::Processed, $recent);

        $this->artisan('bot:prune-updates')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [3, 4, 5],
            TelegramUpdate::query()->pluck('update_id')->all(),
        );
    }

    public function test_reminder_jobs_use_their_own_queue(): void
    {
        $this->assertSame('reminders', (new SendAdhkarReminderJob(1))->queue);
    }

    private function update(int $updateId, TelegramUpdateStatus $status, \DateTimeInterface $receivedAt): void
    {
        TelegramUpdate::query()->create([
            'update_id' => $updateId,
            'chat_id' => 1,
            'payload' => ['update_id' => $updateId],
            'status' => $status,
            'received_at' => $receivedAt,
        ]);
    }
}

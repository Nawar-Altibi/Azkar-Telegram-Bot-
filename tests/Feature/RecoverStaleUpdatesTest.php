<?php

namespace Tests\Feature;

use App\Enums\TelegramUpdateStatus;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class RecoverStaleUpdatesTest extends TestCase
{
    public function test_recover_stale_redispatches_stuck_updates(): void
    {
        Bus::fake([ProcessTelegramUpdateJob::class]);
        $this->mock(TelegramUpdateProcessor::class);

        $update = TelegramUpdate::factory()->create([
            'status' => TelegramUpdateStatus::Pending,
            'attempts' => 0,
            'received_at' => now()->subMinutes(20),
        ]);

        $this->artisan('adhkar:recover-stale')->assertSuccessful();
        $this->assertSame(1, $update->fresh()->attempts);
        Bus::assertDispatched(ProcessTelegramUpdateJob::class);
    }
}

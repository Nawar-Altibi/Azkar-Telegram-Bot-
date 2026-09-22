<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    public function test_invalid_secret_returns_403(): void
    {
        $this->postJson('/telegram/webhook/wrong', ['update_id' => 1], [
            'X-Telegram-Bot-Api-Secret-Token' => 'wrong',
        ])->assertStatus(403);
    }

    public function test_missing_header_returns_403(): void
    {
        $this->postJson($this->webhookUrl(), ['update_id' => 1])->assertStatus(403);
    }

    public function test_payload_without_update_id_returns_400(): void
    {
        $this->postWebhook(['message' => ['text' => 'hi']])->assertStatus(400);
        $this->assertDatabaseCount('telegram_updates', 0);
    }

    public function test_valid_update_is_persisted_and_job_is_dispatched(): void
    {
        Bus::fake([ProcessTelegramUpdateJob::class]);

        $this->mock(TelegramUpdateProcessor::class, function ($mock) {
            $mock->shouldNotReceive('handle');
        });

        $this->postWebhook($this->telegramMessagePayload('/start', 11))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('telegram_updates', ['update_id' => 11]);
        Bus::assertDispatched(ProcessTelegramUpdateJob::class);
    }

    public function test_duplicate_update_id_returns_200_without_a_second_row(): void
    {
        Bus::fake();
        $payload = $this->telegramMessagePayload('/start', 22);

        $this->postWebhook($payload)->assertOk();
        $this->postWebhook($payload)->assertOk();

        $this->assertSame(1, TelegramUpdate::query()->where('update_id', 22)->count());
    }
}

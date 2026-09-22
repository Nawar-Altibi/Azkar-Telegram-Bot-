<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsPauseResumeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    public function test_settings_mode_and_pause_resume(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'mode' => AdhkarMode::Short,
        ]);

        $this->process($this->telegramMessagePayload('/settings', 10));
        $this->process($this->telegramCallbackPayload('settings:mode:full', 11));
        $this->assertSame(AdhkarMode::Full, $user->fresh()->mode);

        $this->process($this->telegramMessagePayload('/pause', 12));
        $user->refresh();
        $this->assertFalse($user->reminders_enabled);
        $this->assertNull($user->next_morning_at);

        $this->process($this->telegramMessagePayload('/pause', 13));
        $this->process($this->telegramMessagePayload('/resume', 14));
        $user->refresh();
        $this->assertTrue($user->reminders_enabled);
        $this->assertNotNull($user->next_morning_at);

        $this->process($this->telegramMessagePayload('/resume', 15));
        $this->assertTrue($user->fresh()->reminders_enabled);
    }

    public function test_pause_before_onboarding_points_to_start(): void
    {
        User::factory()->newUser()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'onboarding_state' => OnboardingState::New,
        ]);

        $this->process($this->telegramMessagePayload('/pause', 20));
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', '/start'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function process(array $payload): void
    {
        $this->postWebhook($payload)->assertOk();
        $record = TelegramUpdate::query()->where('update_id', $payload['update_id'])->first();
        ProcessTelegramUpdateJob::dispatchSync($record->id);
    }
}

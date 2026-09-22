<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\User;
use App\Services\ContentSyncService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        app(ContentSyncService::class)->sync();
    }

    public function test_fresh_user_can_complete_onboarding(): void
    {
        $this->process($this->telegramMessagePayload('/start', 1));

        $user = User::query()->where('telegram_id', 1001)->first();
        $this->assertNotNull($user);
        $this->assertSame(OnboardingState::WaitingTimezone, $user->onboarding_state);

        $this->process($this->telegramCallbackPayload('onb:tz:1', 2));
        $user->refresh();
        $this->assertSame('Asia/Damascus', $user->timezone);
        $this->assertSame(OnboardingState::WaitingMorningTime, $user->onboarding_state);

        $this->process($this->telegramCallbackPayload('onb:time:morning:0600', 3));
        $user->refresh();
        $this->assertSame('06:00:00', $user->morning_time);
        $this->assertSame(OnboardingState::WaitingEveningTime, $user->onboarding_state);

        $this->process($this->telegramCallbackPayload('onb:time:evening:1800', 4));
        $user->refresh();
        $this->assertSame('18:00:00', $user->evening_time);
        $this->assertSame(OnboardingState::WaitingMode, $user->onboarding_state);

        $this->process($this->telegramCallbackPayload('onb:mode:short', 5));
        $user->refresh();
        $this->assertSame(OnboardingState::Completed, $user->onboarding_state);
        $this->assertTrue($user->reminders_enabled);
        $this->assertSame(AdhkarMode::Short, $user->mode);
        $this->assertNotNull($user->next_morning_at);
        $this->assertNotNull($user->next_evening_at);
    }

    public function test_invalid_custom_time_is_reprompted(): void
    {
        $this->process($this->telegramMessagePayload('/start', 1));
        $this->process($this->telegramCallbackPayload('onb:tz:1', 2));
        $this->process($this->telegramCallbackPayload('onb:time:morning:custom', 3));
        $this->process($this->telegramMessagePayload('not-a-time', 4));

        $user = User::query()->where('telegram_id', 1001)->first();
        $this->assertSame(OnboardingState::WaitingMorningTime, $user->onboarding_state);
        $this->assertNull($user->morning_time);
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', 'HH:MM') || str_contains($request['text'] ?? '', '06:30'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function process(array $payload): void
    {
        $this->postWebhook($payload)->assertOk();
        $record = \App\Models\TelegramUpdate::query()->where('update_id', $payload['update_id'])->first();
        ProcessTelegramUpdateJob::dispatchSync($record->id);
    }
}

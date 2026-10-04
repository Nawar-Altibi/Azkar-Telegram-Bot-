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

    public function test_settings_have_no_mode_and_pause_resume(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'mode' => AdhkarMode::Short,
        ]);

        $this->process($this->telegramMessagePayload('/settings', 10));
        Http::assertSent(function ($request) {
            return str_contains((string) ($request['text'] ?? ''), 'الإعدادات')
                && ! str_contains((string) ($request['text'] ?? ''), 'الورد:')
                && ! str_contains((string) ($request['reply_markup'] ?? ''), 'settings:mode');
        });

        $this->process($this->telegramCallbackPayload('settings:mode:full', 11));
        $this->assertSame(AdhkarMode::Short, $user->fresh()->mode);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageText')
            && str_contains((string) ($request['text'] ?? ''), 'الإعدادات'));

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

    public function test_settings_back_opens_the_reading_menu(): void
    {
        User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
        ]);

        $this->process($this->telegramCallbackPayload('settings:close', 30));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'editMessageText')
                && str_contains((string) ($request['text'] ?? ''), 'شو حابب تقرأ')
                && str_contains((string) ($request['reply_markup'] ?? ''), 'today:morning');
        });
        Http::assertNotSent(fn ($request) => ($request['text'] ?? '') === 'تم');
    }

    public function test_settings_screens_edit_the_same_message(): void
    {
        User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
        ]);

        $this->process($this->telegramCallbackPayload('settings:morning_time', 31));
        $this->process($this->telegramCallbackPayload('onb:time:morning:0630', 32));

        $this->assertSame(0, collect(Http::recorded())->filter(
            fn ($pair) => str_contains($pair[0]->url(), 'sendMessage')
        )->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageText')
            && str_contains((string) ($request['text'] ?? ''), 'رح نذكّرك بأذكار الصباح'));
    }

    public function test_pausing_from_settings_asks_first_and_offers_undo(): void
    {
        $user = User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $this->process($this->telegramCallbackPayload('settings:pause_ask', 40));
        $this->assertTrue($user->fresh()->reminders_enabled);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'بدك توقف التذكيرات')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'settings:pause_yes'));

        $this->process($this->telegramCallbackPayload('settings:pause_yes', 41));
        $this->assertFalse($user->fresh()->reminders_enabled);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'وقّفنا التذكيرات')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'settings:resume'));

        $this->process($this->telegramCallbackPayload('settings:resume', 42));
        $this->assertTrue($user->fresh()->reminders_enabled);
        $this->assertNotNull($user->fresh()->next_morning_at);
    }

    public function test_old_pause_button_asks_before_pausing(): void
    {
        $user = User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $this->process($this->telegramMessagePayload('⏸ إيقاف التذكير', 43));
        $this->assertTrue($user->fresh()->reminders_enabled);
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'settings:pause_yes'));

        $this->process($this->telegramCallbackPayload('settings:toggle_reminders', 44));
        $this->assertTrue($user->fresh()->reminders_enabled);
    }

    public function test_keyboard_can_stay_visible_or_be_hidden(): void
    {
        $user = User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);
        $this->assertTrue($user->fresh()->keyboard_persistent);

        $this->process($this->telegramCallbackPayload('settings:keyboard', 50));
        $this->assertFalse($user->fresh()->keyboard_persistent);
        Http::assertSent(function ($request) {
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);

            return is_array($markup) && isset($markup['keyboard']) && ! isset($markup['is_persistent']);
        });

        $this->process($this->telegramCallbackPayload('settings:keyboard', 51));
        $this->assertTrue($user->fresh()->keyboard_persistent);
        Http::assertSent(function ($request) {
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);

            return is_array($markup)
                && ($markup['is_persistent'] ?? false) === true
                && ($markup['input_field_placeholder'] ?? '') === 'اختر من الأزرار 👇';
        });
    }

    public function test_reset_asks_before_clearing_the_setup(): void
    {
        $user = User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $this->process($this->telegramCallbackPayload('onb:reset', 60));
        $this->assertSame(OnboardingState::Completed, $user->fresh()->onboarding_state);
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'onb:reset:yes'));

        $this->process($this->telegramCallbackPayload('onb:reset:no', 61));
        $this->assertSame(OnboardingState::Completed, $user->fresh()->onboarding_state);
        $this->assertTrue($user->fresh()->reminders_enabled);

        $this->process($this->telegramCallbackPayload('onb:reset:yes', 62));
        $this->assertSame(OnboardingState::WaitingTimezone, $user->fresh()->onboarding_state);
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

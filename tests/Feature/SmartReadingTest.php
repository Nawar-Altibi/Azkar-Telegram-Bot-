<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\ContentSyncService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmartReadingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        app(ContentSyncService::class)->sync();
    }

    public function test_reading_menu_puts_the_current_period_first_and_marks_it(): void
    {
        $this->user();

        $this->travelTo(Carbon::parse('2026-03-10 07:00:00', 'Asia/Damascus'));
        $this->handle($this->telegramMessagePayload('/today', 1));
        $morning = $this->lastMarkup();
        $this->assertSame('today:morning', $morning['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('primary', $morning['inline_keyboard'][0][0]['style'] ?? null);
        $this->assertSame('🌅 أذكار الصباح', $morning['inline_keyboard'][0][0]['text']);
        $this->assertArrayNotHasKey('style', $morning['inline_keyboard'][1][0]);
        $this->assertStringContainsString('صباح الخير', $this->lastText());

        $this->travelTo(Carbon::parse('2026-03-10 17:30:00', 'Asia/Damascus'));
        $this->handle($this->telegramMessagePayload('/today', 2));
        $evening = $this->lastMarkup();
        $this->assertSame('today:evening', $evening['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('primary', $evening['inline_keyboard'][0][0]['style'] ?? null);
        $this->assertSame('today:morning', $evening['inline_keyboard'][1][0]['callback_data']);

        $this->travelTo(Carbon::parse('2026-03-10 23:00:00', 'Asia/Damascus'));
        $this->handle($this->telegramMessagePayload('/today', 3));
        $night = $this->lastMarkup();
        $this->assertSame('today:bedtime', $night['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('primary', $night['inline_keyboard'][0][0]['style'] ?? null);
        $this->assertSame(
            ['today:bedtime', 'today:evening', 'today:morning'],
            collect($night['inline_keyboard'])->flatten(1)->pluck('callback_data')->all(),
        );

        $this->handle($this->telegramCallbackPayload('today:bedtime', 4));
        $this->assertStringStartsWith('🛌 أذكار النوم', $this->lastText());
    }

    public function test_first_choice_is_remembered_and_can_be_switched(): void
    {
        $user = $this->user();

        $this->handle($this->telegramCallbackPayload('today:morning', 10));
        $this->assertStringContainsString('أي ورد بتحب تقرأ', $this->lastText());

        $this->handle($this->telegramCallbackPayload('md:m:a', 11));
        $user->refresh();
        $this->assertSame(AdhkarMode::Full, $user->mode);
        $this->assertNotNull($user->mode_chosen_at);
        $this->assertStringContainsString('كامل', $this->lastText());
        $this->assertStringContainsString('md:m:s', json_encode($this->lastMarkup()));

        $this->handle($this->telegramCallbackPayload('today:evening', 12));
        $this->assertStringStartsWith("🌙 أذكار المساء\n🌿 كامل", $this->lastText());

        $this->handle($this->telegramCallbackPayload('md:e:s', 13));
        $this->assertSame(AdhkarMode::Short, $user->fresh()->mode);
        $this->assertStringStartsWith("🌙 أذكار المساء\n🌿 مختصر", $this->lastText());
        $this->assertStringContainsString('بدّل للورد الكامل', json_encode($this->lastMarkup(), JSON_UNESCAPED_UNICODE));
    }

    public function test_switching_inside_a_reminder_keeps_the_done_button_on_the_new_wird(): void
    {
        $user = $this->user();
        $log = ReminderLog::query()->create([
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Morning,
            'collection_key' => 'morning_short',
            'scheduled_for' => now(),
            'scheduled_local_date' => now()->timezone('Asia/Damascus')->toDateString(),
            'status' => ReminderLogStatus::Sent,
            'sent_at' => now(),
        ]);

        $this->handle($this->telegramCallbackPayload('reminder:start:'.$log->id.':s', 20));
        $this->assertSame(AdhkarMode::Short, $user->fresh()->mode);
        $this->assertStringContainsString('rsw:'.$log->id.':a', json_encode($this->lastMarkup()));

        $this->handle($this->telegramCallbackPayload('rsw:'.$log->id.':a', 21));
        $log->refresh();
        $this->assertSame('morning_full', $log->collection_key);
        $this->assertContains('morning.10', array_column($log->content_version_map, 'key'));
        $this->assertSame(AdhkarMode::Full, $user->fresh()->mode);
        $this->assertStringContainsString('كامل', $this->lastText());
    }

    public function test_one_tap_setup_saves_the_suggested_times(): void
    {
        $this->handle($this->telegramMessagePayload('/start', 30));
        $this->handle($this->telegramCallbackPayload('onb:begin', 31));
        $this->handle($this->telegramCallbackPayload('onb:tz:0', 32));
        $this->assertStringContainsString('6:00 صباحًا', $this->lastText());
        $this->assertStringContainsString('5:00 عصرًا', $this->lastText());

        $this->handle($this->telegramCallbackPayload('onb:quick:yes', 33));
        $user = User::query()->where('telegram_id', 1001)->first();
        $this->assertSame(OnboardingState::Completed, $user->onboarding_state);
        $this->assertSame('06:00:00', $user->morning_time);
        $this->assertSame('17:00:00', $user->evening_time);
        $this->assertTrue($user->reminders_enabled);
    }

    public function test_choosing_other_times_shows_the_morning_presets(): void
    {
        $this->handle($this->telegramMessagePayload('/start', 40));
        $this->handle($this->telegramCallbackPayload('onb:tz:0', 41));
        $this->handle($this->telegramCallbackPayload('onb:quick:custom', 42));

        $this->assertStringContainsString('أذكار الصباح', $this->lastText());
        $this->assertStringContainsString('onb:time:morning:0600', json_encode($this->lastMarkup()));
        $this->assertSame(OnboardingState::WaitingMorningTime, User::query()->where('telegram_id', 1001)->first()->onboarding_state);
    }

    private function user(): User
    {
        return User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001, 'timezone' => 'Asia/Damascus']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handle(array $payload): void
    {
        app(TelegramUpdateProcessor::class)->handle($payload);
    }

    private function lastRequest(): Request
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendMessage') || str_contains($pair[0]->url(), 'editMessageText'))
            ->last()[0];
    }

    private function lastText(): string
    {
        return (string) ($this->lastRequest()['text'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function lastMarkup(): array
    {
        return json_decode((string) ($this->lastRequest()['reply_markup'] ?? '[]'), true) ?: [];
    }
}

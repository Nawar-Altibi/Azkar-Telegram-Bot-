<?php

namespace Tests\Feature;

use App\Models\BotEvent;
use App\Models\User;
use App\Services\BotEventRecorder;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    public function test_setup_mistakes_unclear_text_and_quick_undo_are_recorded(): void
    {
        $this->handle($this->telegramMessagePayload('/start', 1));
        $this->handle($this->telegramCallbackPayload('onb:begin', 2));
        $this->handle($this->telegramCallbackPayload('onb:tz:0', 3));
        $this->handle($this->telegramCallbackPayload('onb:quick:yes', 4));

        $user = User::query()->where('telegram_id', 1001)->first();
        $user->update(['awaiting_input' => 'settings:morning_time_custom']);
        $this->handle($this->telegramMessagePayload('7;42', 5));
        $user->update(['awaiting_input' => null]);
        $this->handle($this->telegramMessagePayload('بطاطا', 6));
        $this->handle($this->telegramMediaPayload(['voice' => ['file_id' => 'v']], 7));
        $this->handle($this->telegramCallbackPayload('settings:pause_yes', 8));
        $this->handle($this->telegramCallbackPayload('settings:resume', 9));

        $events = BotEvent::query()->where('user_id', $user->id)->pluck('event')->all();
        foreach (['onboarding_started', 'onboarding_step', 'onboarding_completed', 'time_error', 'unknown_text', 'media', 'paused', 'resumed'] as $expected) {
            $this->assertContains($expected, $events);
        }

        $this->assertSame('time_bad_separator', BotEvent::query()->where('event', 'time_error')->first()->meta['reason'] ?? null);
        $this->assertSame('بطاطا', BotEvent::query()->where('event', 'unknown_text')->first()->meta['text']);
        $this->assertLessThanOrEqual(5, BotEvent::query()->where('event', 'resumed')->first()->meta['seconds_after_pause']);

        $this->artisan('bot:stats')
            ->expectsOutputToContain('الإعداد: بدأ 1 · خلّص 1')
            ->expectsOutputToContain('رجوع سريع خلال دقيقتين: 1')
            ->assertSuccessful();
    }

    public function test_unclear_text_is_deleted_after_thirty_days(): void
    {
        $user = User::factory()->create();
        $recorder = app(BotEventRecorder::class);

        $this->travelTo(now()->subDays(31));
        $recorder->unknownText($user, 'قديم');
        $recorder->record($user, 'paused');
        $this->travelBack();

        $recorder->unknownText($user, 'جديد');

        $texts = BotEvent::query()->where('event', 'unknown_text')->get()->pluck('meta.text')->all();
        $this->assertSame(['جديد'], $texts);
        $this->assertSame(1, BotEvent::query()->where('event', 'paused')->count());
        $this->artisan('bot:prune-events')->assertSuccessful();
    }

    public function test_a_broken_events_table_never_blocks_the_reply(): void
    {
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);
        \Illuminate\Support\Facades\Schema::drop('bot_events');

        $this->handle($this->telegramMessagePayload('بطاطا', 1));

        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'ما فهمت'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handle(array $payload): void
    {
        app(TelegramUpdateProcessor::class)->handle($payload);
    }
}

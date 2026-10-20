<?php

namespace Tests\Feature;

use App\Models\AdhkarCompletion;
use App\Models\User;
use App\Services\ContentSyncService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdhkarCompletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200),
        ]);
        app(ContentSyncService::class)->sync();
    }

    public function test_text_completion_hides_done_for_images_on_the_same_local_day(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'timezone' => 'Asia/Damascus',
            'timezone_label' => 'سوريا',
            'delivery_format' => 'text',
        ]);

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('sv:t:m:a', 1, $user->telegram_id)
        );

        $last = collect(Http::recorded())->last()[0];
        $markup = json_decode((string) ($last['reply_markup'] ?? ''), true);
        $done = collect($markup['inline_keyboard'] ?? [])->flatten(1)->firstWhere('callback_data', 'completion:done:morning');
        $this->assertNotNull($done);

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('completion:done:morning', 2, $user->telegram_id)
        );

        $this->assertDatabaseHas('adhkar_completions', [
            'user_id' => $user->id,
            'collection_group' => 'morning',
            'local_date' => now()->timezone($user->timezone)->toDateString(),
        ]);

        $user->update(['delivery_format' => 'images']);
        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('sv:t:m:a', 3, $user->telegram_id)
        );

        $lastMarkup = json_encode(collect(Http::recorded())->last()[0]['reply_markup'] ?? '', JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('completion:done:morning', (string) $lastMarkup);
    }

    public function test_completion_button_is_available_again_on_the_next_local_day(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'timezone' => 'Asia/Damascus',
            'timezone_label' => 'سوريا',
            'delivery_format' => 'text',
        ]);
        $today = Carbon::parse('2026-10-20 12:00:00', $user->timezone);
        Carbon::setTestNow($today);

        AdhkarCompletion::query()->create([
            'user_id' => $user->id,
            'collection_group' => 'morning',
            'local_date' => $today->toDateString(),
            'completed_at' => $today,
        ]);

        Carbon::setTestNow($today->copy()->addDay());
        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('sv:t:m:a', 4, $user->telegram_id)
        );

        $markup = json_encode(collect(Http::recorded())->last()[0]['reply_markup'] ?? '', JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('completion:done:morning', (string) $markup);
        Carbon::setTestNow();
    }

    public function test_manual_reading_gets_done_but_additional_adhkar_do_not(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'timezone' => 'Asia/Damascus',
            'timezone_label' => 'سوريا',
        ]);

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('rt:b:a:12', 5, $user->telegram_id)
        );
        $bedtimeMarkup = json_encode(collect(Http::recorded())->last()[0]['reply_markup'] ?? '', JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('completion:done:bedtime', (string) $bedtimeMarkup);

        app(TelegramUpdateProcessor::class)->handle(
            $this->telegramCallbackPayload('rt:z:a:12', 6, $user->telegram_id)
        );
        $additionalMarkup = json_encode(collect(Http::recorded())->last()[0]['reply_markup'] ?? '', JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('completion:done:', (string) $additionalMarkup);
    }
}

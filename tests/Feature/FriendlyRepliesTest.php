<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FriendlyRepliesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function mediaMessages(): array
    {
        return [
            'voice' => [['voice' => ['file_id' => 'v', 'duration' => 3]], 'لا أستطيع سماع الصوت'],
            'photo' => [['photo' => [['file_id' => 'p', 'width' => 1, 'height' => 1]], 'caption' => 'شوف'], 'لا أستطيع رؤية الصور'],
            'sticker' => [['sticker' => ['file_id' => 's']], 'ملصق جميل'],
            'video' => [['video' => ['file_id' => 'f']], 'لا أستطيع تشغيل المقاطع المرئية'],
            'document' => [['document' => ['file_id' => 'd']], 'لا أستطيع فتح الملفات'],
            'contact' => [['contact' => ['phone_number' => '1', 'first_name' => 'x']], 'لا أحتاجها ولم أحفظها'],
            'location' => [['location' => ['latitude' => 33.5, 'longitude' => 36.3]], 'لم أطلبه'],
            'poll' => [['poll' => ['id' => '1']], 'لم أفهمها'],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    #[DataProvider('mediaMessages')]
    public function test_each_kind_of_message_gets_its_own_kind_reply(array $fields, string $expected): void
    {
        app(TelegramUpdateProcessor::class)->handle($this->telegramMediaPayload($fields));

        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), $expected)
            && str_contains((string) ($request['text'] ?? ''), 'استخدم الأزرار')
            && str_contains((string) ($request['reply_markup'] ?? ''), '"keyboard"'));
    }

    public function test_plain_words_open_the_right_screen_and_settings_changes_ask_first(): void
    {
        $user = User::query()->where('telegram_id', 1001)->first();

        app(TelegramUpdateProcessor::class)->handle($this->telegramMessagePayload('وقّف التذكير', 1));
        $this->assertTrue($user->fresh()->reminders_enabled);
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'settings:pause_yes'));

        app(TelegramUpdateProcessor::class)->handle($this->telegramMessagePayload('شكرًا', 2));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'جزاك الله خيرًا'));

        app(TelegramUpdateProcessor::class)->handle($this->telegramMessagePayload('بطاطا', 3));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'لم أفهم «بطاطا»'));
    }

    public function test_an_old_button_explains_itself_and_brings_the_new_keyboard(): void
    {
        app(TelegramUpdateProcessor::class)->handle($this->telegramCallbackPayload('legacy:thing', 2));

        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'هذا الزر قديم')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'المساعدة والشرح'));
    }

    public function test_a_quick_second_press_of_the_same_button_does_nothing(): void
    {
        config(['telegram.double_press_seconds' => 2]);

        app(TelegramUpdateProcessor::class)->handle($this->telegramCallbackPayload('help:menu', 2));
        app(TelegramUpdateProcessor::class)->handle($this->telegramCallbackPayload('help:menu', 3));
        app(TelegramUpdateProcessor::class)->handle($this->telegramCallbackPayload('help:topic:font', 4));

        $edits = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'editMessageText'));
        $this->assertCount(2, $edits);
    }
}

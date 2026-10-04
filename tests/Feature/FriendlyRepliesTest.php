<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Http;
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
            'voice' => [['voice' => ['file_id' => 'v', 'duration' => 3]], 'ما بقدر أسمع الصوت'],
            'photo' => [['photo' => [['file_id' => 'p', 'width' => 1, 'height' => 1]], 'caption' => 'شوف'], 'ما بقدر أشوف الصور'],
            'sticker' => [['sticker' => ['file_id' => 's']], 'حلو الملصق'],
            'video' => [['video' => ['file_id' => 'f']], 'ما بقدر أشغّل الفيديوهات'],
            'document' => [['document' => ['file_id' => 'd']], 'ما بقدر أفتح الملفات'],
            'contact' => [['contact' => ['phone_number' => '1', 'first_name' => 'x']], 'ما بحتاجها وما حفظتها'],
            'location' => [['location' => ['latitude' => 33.5, 'longitude' => 36.3]], 'ما كنت طالبه'],
            'poll' => [['poll' => ['id' => '1']], 'ما قدرت أفهمها'],
        ];
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('mediaMessages')]
    public function test_each_kind_of_message_gets_its_own_kind_reply(array $fields, string $expected): void
    {
        app(TelegramUpdateProcessor::class)->handle($this->telegramMediaPayload($fields));

        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), $expected)
            && str_contains((string) ($request['text'] ?? ''), 'استعمل الأزرار')
            && str_contains((string) ($request['reply_markup'] ?? ''), '"keyboard"'));
    }

    public function test_an_old_button_explains_itself_and_brings_the_new_keyboard(): void
    {
        app(TelegramUpdateProcessor::class)->handle($this->telegramCallbackPayload('legacy:thing', 2));

        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'هالزر قديم')
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

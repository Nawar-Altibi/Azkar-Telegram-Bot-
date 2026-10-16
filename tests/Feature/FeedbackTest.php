<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\User;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    private const ADMIN = 777;

    protected function setUp(): void
    {
        parent::setUp();
        config(['telegram.admin_chat_id' => self::ADMIN, 'telegram.double_press_seconds' => 0]);
        Http::fake([
            'https://api.telegram.org/*' => Http::sequence()
                ->whenEmpty(Http::response(['ok' => true, 'result' => ['message_id' => 500]], 200)),
        ]);
    }

    public function test_bottom_keyboard_button_opens_the_feedback_prompt(): void
    {
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $this->handle($this->telegramMessagePayload('💬 أرسل ملاحظة أو اقتراحًا', 1));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'شاركنا رأيك')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'feedback:cancel'));
        $this->assertSame(0, Feedback::query()->count());

        $this->handle($this->telegramMessagePayload('اقتراح', 2));
        $this->assertSame('اقتراح', Feedback::query()->sole()->text);
    }

    public function test_feedback_reaches_the_admin_and_the_reply_goes_back(): void
    {
        $user = User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001, 'first_name' => 'نوار']);

        $this->handle($this->telegramCallbackPayload('help:menu', 1));
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'feedback:start'));

        $this->handle($this->telegramCallbackPayload('feedback:start', 2));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'شاركنا رأيك')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'feedback:cancel'));

        $this->handle($this->telegramMessagePayload('الخط صغير شوي بأذكار المسا', 3));

        $feedback = Feedback::query()->sole();
        $this->assertSame($user->id, $feedback->user_id);
        $this->assertSame('الخط صغير شوي بأذكار المسا', $feedback->text);
        $this->assertSame(500, (int) $feedback->admin_header_message_id);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && (int) $request['chat_id'] === self::ADMIN
            && str_contains((string) $request['text'], 'ملاحظة جديدة')
            && str_contains((string) $request['text'], 'الحساب: @nawar'));
        Http::assertNotSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === self::ADMIN
            && str_contains((string) ($request['text'] ?? ''), '1001'));
        Http::assertNotSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === 1001
            && str_contains((string) ($request['text'] ?? ''), '@nawar'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'copyMessage')
            && (int) $request['chat_id'] === self::ADMIN
            && (int) $request['from_chat_id'] === 1001
            && (int) $request['message_id'] === 3);
        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === 1001
            && str_contains((string) ($request['text'] ?? ''), 'وصلت ملاحظتك'));

        $this->handle($this->telegramMessagePayload('صباح', 4));
        $this->assertSame(1, Feedback::query()->count());

        $reply = $this->telegramMessagePayload('شكرًا، رح نكبّره بالتحديث الجاي', 5, self::ADMIN);
        $reply['message']['reply_to_message'] = ['message_id' => 500];
        $this->handle($reply);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'copyMessage')
            && (int) $request['chat_id'] === 1001
            && (int) $request['from_chat_id'] === self::ADMIN
            && (int) $request['message_id'] === 5);
        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === self::ADMIN
            && str_contains((string) ($request['text'] ?? ''), 'وصل ردّك'));
        $this->assertNotNull($feedback->fresh()->replied_at);
    }

    public function test_admin_header_uses_the_first_name_when_the_account_has_no_username(): void
    {
        User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'first_name' => 'سلمى',
            'username' => null,
        ]);

        $open = $this->telegramMessagePayload('/feedback', 1);
        unset($open['message']['from']['username']);
        $open['message']['from']['first_name'] = 'سلمى';
        $note = $this->telegramMessagePayload('ملاحظة', 2);
        unset($note['message']['from']['username']);
        $note['message']['from']['first_name'] = 'سلمى';

        $this->handle($open);
        $this->handle($note);

        Http::assertSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === self::ADMIN
            && str_contains((string) ($request['text'] ?? ''), 'الحساب: سلمى'));
        Http::assertNotSent(fn ($request) => (int) ($request['chat_id'] ?? 0) === 1001
            && str_contains((string) ($request['text'] ?? ''), 'سلمى'));
    }

    public function test_voice_feedback_is_copied_instead_of_the_cannot_hear_reply(): void
    {
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $this->handle($this->telegramMessagePayload('/feedback', 1));
        $this->handle($this->telegramMediaPayload(['voice' => ['file_id' => 'v1', 'duration' => 3]], 2));

        $this->assertSame('voice', Feedback::query()->sole()->kind);
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'لا أستطيع سماع'));
    }

    public function test_keyboard_buttons_and_cancel_close_the_prompt(): void
    {
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $this->handle($this->telegramCallbackPayload('feedback:start', 1));
        $this->handle($this->telegramMessagePayload('⚙️ الإعدادات', 2));
        $this->handle($this->telegramMessagePayload('كلام عادي', 3));

        $this->handle($this->telegramCallbackPayload('feedback:start', 4));
        $this->handle($this->telegramCallbackPayload('feedback:cancel', 5));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'لم نرسل شيئًا'));
        $this->handle($this->telegramMessagePayload('كلام تاني', 6));

        $this->assertSame(0, Feedback::query()->count());
    }

    public function test_feedback_is_limited_per_hour(): void
    {
        config(['telegram.feedback_per_hour' => 1]);
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $this->handle($this->telegramMessagePayload('/feedback', 1));
        $this->handle($this->telegramMessagePayload('أول ملاحظة', 2));
        $this->handle($this->telegramMessagePayload('/feedback', 3));
        $this->handle($this->telegramMessagePayload('تاني ملاحظة', 4));

        $this->assertSame(1, Feedback::query()->count());
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'ملاحظات كثيرة'));
    }

    public function test_admin_messages_that_are_not_replies_to_feedback_work_as_usual(): void
    {
        User::factory()->create(['telegram_id' => self::ADMIN, 'chat_id' => self::ADMIN]);

        $message = $this->telegramMessagePayload('/help', 1, self::ADMIN);
        $message['message']['reply_to_message'] = ['message_id' => 999];
        $this->handle($message);

        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'اختر سؤالك'));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handle(array $payload): void
    {
        app(TelegramUpdateProcessor::class)->handle($payload);
    }
}

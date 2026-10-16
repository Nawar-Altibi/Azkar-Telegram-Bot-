<?php

namespace Tests\Feature;

use App\Models\GroupChat;
use App\Services\ContentSyncService;
use App\Services\GroupPostService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupDeliveryFormatTest extends TestCase
{
    private const CHAT = -100888;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*/getChatMember' => Http::response(['ok' => true, 'result' => ['status' => 'administrator']]),
            'https://api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'AneesAdhkarBot']]),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]]),
        ]);
        app(ContentSyncService::class)->sync();
    }

    public function test_admin_can_switch_group_posts_to_text(): void
    {
        $group = GroupChat::query()->create([
            'chat_id' => self::CHAT,
            'title' => 'Test',
            'timezone' => 'Asia/Damascus',
            'enabled' => true,
        ]);
        $group->reschedule();
        $group->save();

        app(TelegramUpdateProcessor::class)->handle([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb1',
                'from' => ['id' => 1001, 'first_name' => 'Admin'],
                'data' => 'g:format',
                'message' => ['message_id' => 50, 'chat' => ['id' => self::CHAT, 'type' => 'supergroup', 'title' => 'Test']],
            ],
        ]);

        $this->assertSame('text', $group->fresh()->delivery_format);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'شكل النشر')
            && str_contains((string) ($request['text'] ?? ''), 'رسائل')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'g:format'));
    }

    public function test_text_format_posts_messages_instead_of_images(): void
    {
        $group = GroupChat::query()->create([
            'chat_id' => self::CHAT,
            'title' => 'Test',
            'timezone' => 'Asia/Damascus',
            'enabled' => true,
            'delivery_format' => 'text',
        ]);
        $group->forceFill(['next_morning_at' => now()->subMinute()])->save();

        $this->assertSame(1, app(GroupPostService::class)->postDue());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && ($request['chat_id'] ?? null) == self::CHAT
            && str_contains((string) ($request['text'] ?? ''), 'أذكار الصباح'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendMediaGroup'));
    }
}

<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroupChatGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
    }

    public function test_a_command_in_a_group_does_not_move_private_reminders_to_the_group(): void
    {
        $user = User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $payload = $this->telegramMessagePayload('/start', 1);
        $payload['message']['chat'] = ['id' => -100555, 'type' => 'supergroup'];
        app(TelegramUpdateProcessor::class)->handle($payload);

        $this->assertSame(1001, (int) $user->fresh()->chat_id);
        Http::assertNothingSent();
    }

    public function test_a_button_pressed_in_a_group_is_ignored(): void
    {
        $user = User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $payload = $this->telegramCallbackPayload('settings:pause_yes', 2);
        $payload['callback_query']['message']['chat'] = ['id' => -100555, 'type' => 'group'];
        app(TelegramUpdateProcessor::class)->handle($payload);

        $this->assertSame(1001, (int) $user->fresh()->chat_id);
        $this->assertNull($user->fresh()->paused_at);
    }

    public function test_someone_who_only_wrote_in_a_group_is_not_registered(): void
    {
        $payload = $this->telegramMessagePayload('/start', 1, 2002);
        $payload['message']['chat'] = ['id' => -100555, 'type' => 'group'];
        app(TelegramUpdateProcessor::class)->handle($payload);

        $this->assertDatabaseMissing('users', ['telegram_id' => 2002]);
    }
}

<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Models\GroupChat;
use App\Services\ContentSyncService;
use App\Services\GroupPostService;
use App\Services\Telegram\TelegramUpdateProcessor;
use App\Services\WakeScheduleService;
use Illuminate\Http\Client\Factory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class GroupChatTest extends TestCase
{
    private const CHAT = -100777;

    private string $memberStatus = 'administrator';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeTelegram();
        app(ContentSyncService::class)->sync();
    }

    public function test_adding_the_bot_creates_the_group_and_invites_admins_to_set_it_up(): void
    {
        $this->handle($this->membership('left', 'member'));

        $group = GroupChat::query()->where('chat_id', self::CHAT)->first();
        $this->assertNotNull($group);
        $this->assertTrue($group->bot_member);
        $this->assertFalse($group->enabled);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && ($request['chat_id'] ?? null) == self::CHAT
            && str_contains((string) ($request['reply_markup'] ?? ''), 'g:menu'));
    }

    public function test_admin_sets_country_and_times_and_posts_get_scheduled(): void
    {
        $this->travelTo('2026-10-06 03:00:00');
        $this->handle($this->membership('left', 'member'));

        $this->press('g:tz:0', 1);
        $group = GroupChat::query()->firstWhere('chat_id', self::CHAT);
        $this->assertSame('Asia/Damascus', $group->timezone);
        $this->assertTrue($group->enabled);
        $this->assertSame('2026-10-07 03:00:00', $group->next_morning_at->toDateTimeString());
        $this->assertSame('2026-10-06 14:00:00', $group->next_evening_at->toDateTimeString());

        $this->press('g:mt:05:30', 2);
        $this->press('g:mode', 3);
        $group->refresh();
        $this->assertSame('05:30', $group->morning_time);
        $this->assertSame(AdhkarMode::Full, $group->mode);
        $this->assertSame('2026-10-06 02:30:00', $group->next_morning_at->copy()->subDay()->toDateTimeString());

        $this->press('g:toggle', 4);
        $group->refresh();
        $this->assertFalse($group->enabled);
        $this->assertNull($group->next_morning_at);
        $this->assertNull(app(WakeScheduleService::class)->nextWakeAt());
    }

    public function test_members_who_are_not_admins_cannot_change_settings(): void
    {
        $this->memberStatus = 'member';
        $this->handle($this->membership('left', 'member'));

        $this->press('g:tz:0', 1);

        $this->assertNull(GroupChat::query()->firstWhere('chat_id', self::CHAT)->timezone);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery')
            && ($request['show_alert'] ?? false));
    }

    public function test_settings_command_for_another_bot_is_ignored(): void
    {
        $this->handle($this->groupMessage('/settings@SomeOtherBot'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendMessage'));

        $this->handle($this->groupMessage('/settings@AneesAdhkarBot'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'أَنِيس بهالغروب'));
    }

    public function test_removing_the_bot_stops_the_posts(): void
    {
        $group = $this->postingGroup();

        $this->handle($this->membership('member', 'kicked'));

        $group->refresh();
        $this->assertFalse($group->bot_member);
        $this->assertNull($group->next_morning_at);
    }

    public function test_due_morning_wird_is_posted_once_as_images(): void
    {
        config(['adhkar.pdftoppm_binary' => 'pdftoppm']);
        Process::fake(function (PendingProcess $process) {
            $prefix = (string) end($process->command);
            foreach ([1, 2] as $page) {
                File::put($prefix.'-'.$page.'.png', 'png'.$page);
            }

            return Process::result();
        });
        $group = $this->postingGroup();
        $group->forceFill(['next_morning_at' => now()->subMinute()])->save();

        $this->assertSame(1, app(GroupPostService::class)->postDue());
        $this->assertSame(0, app(GroupPostService::class)->postDue());

        $posts = Http::recorded(fn ($request) => str_contains($request->url(), 'sendMediaGroup'));
        $this->assertCount(1, $posts);
        $this->assertStringContainsString((string) self::CHAT, json_encode($posts->first()[0]->data()));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'sp:'));
        $this->assertTrue($group->fresh()->next_morning_at->isFuture());
    }

    public function test_a_group_that_removed_the_bot_is_switched_off_when_posting_fails(): void
    {
        Http::swap(new Factory(app('events')));
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was kicked from the supergroup chat'], 403)]);
        $group = $this->postingGroup();
        $group->forceFill(['next_morning_at' => now()->subMinute()])->save();

        $this->assertSame(0, app(GroupPostService::class)->postDue());

        $this->assertFalse($group->fresh()->bot_member);
        $this->assertNull($group->fresh()->next_morning_at);
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/pdf/*-pages')) ?: [] as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    private function postingGroup(): GroupChat
    {
        $group = GroupChat::query()->create(['chat_id' => self::CHAT, 'title' => 'Test', 'timezone' => 'Asia/Damascus', 'enabled' => true]);
        $group->reschedule();
        $group->save();

        return $group;
    }

    private function fakeTelegram(): void
    {
        Http::fake([
            'https://api.telegram.org/*/getChatMember' => fn () => Http::response(['ok' => true, 'result' => ['status' => $this->memberStatus]]),
            'https://api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'AneesAdhkarBot']]),
            'https://api.telegram.org/*/sendMediaGroup' => Http::response(['ok' => true, 'result' => [
                ['message_id' => 1, 'photo' => [['file_id' => 'p1']]],
                ['message_id' => 2, 'photo' => [['file_id' => 'p2']]],
            ]]),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handle(array $payload): void
    {
        app(TelegramUpdateProcessor::class)->handle($payload);
    }

    private function press(string $data, int $updateId): void
    {
        $this->handle([
            'update_id' => $updateId,
            'callback_query' => [
                'id' => 'cb'.$updateId,
                'from' => ['id' => 1001, 'first_name' => 'Admin'],
                'data' => $data,
                'message' => ['message_id' => 50, 'chat' => ['id' => self::CHAT, 'type' => 'supergroup', 'title' => 'Test']],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function groupMessage(string $text): array
    {
        return [
            'update_id' => 9,
            'message' => [
                'message_id' => 70,
                'from' => ['id' => 1001, 'first_name' => 'Admin'],
                'chat' => ['id' => self::CHAT, 'type' => 'supergroup', 'title' => 'Test'],
                'text' => $text,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function membership(string $old, string $new): array
    {
        return [
            'update_id' => 8,
            'my_chat_member' => [
                'chat' => ['id' => self::CHAT, 'type' => 'supergroup', 'title' => 'Test'],
                'from' => ['id' => 1001, 'first_name' => 'Admin'],
                'old_chat_member' => ['status' => $old],
                'new_chat_member' => ['status' => $new],
            ],
        ];
    }
}

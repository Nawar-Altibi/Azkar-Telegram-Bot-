<?php

namespace Tests\Feature;

use App\Enums\AdhkarCategory;
use App\Enums\ReminderLogStatus;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use App\Models\ReminderLog;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\ContentSyncService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdditionalAndCompletionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        app(ContentSyncService::class)->sync();
    }

    public function test_additional_items_are_not_in_morning_collections(): void
    {
        $morning = AdhkarCollection::query()->where('key', 'morning_full')->first();
        $this->assertFalse(
            $morning->items()->where('category', AdhkarCategory::Additional)->exists()
        );
        $this->assertTrue(AdhkarItem::query()->where('key', 'additional.01')->exists());
    }

    public function test_additional_menu_is_one_flat_list_and_never_scheduled(): void
    {
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);
        $active = AdhkarItem::query()->where('category', AdhkarCategory::Additional)->where('active', true)->orderBy('order')->get();

        $this->process($this->telegramMessagePayload('🌟 أذكار إضافية', 12));

        Http::assertSent(function ($request) use ($active) {
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);
            $callbacks = array_map(fn (array $row) => $row[0]['callback_data'] ?? '', $markup['inline_keyboard'] ?? []);

            return str_contains((string) ($request['text'] ?? ''), 'أذكار إضافية')
                && array_slice($callbacks, 0, -1) === $active->map(fn (AdhkarItem $item) => 'add:item:'.$item->id)->all()
                && end($callbacks) === 'sv:t:z:a';
        });
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'add:group:'));
        $this->assertSame(0, ReminderLog::query()->where('collection_key', 'additional')->count());
    }

    public function test_send_all_button_delivers_every_additional_dhikr_as_one_file(): void
    {
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);
        config(['adhkar.pdftoppm_binary' => 'missing-pdftoppm-binary']);

        $this->process($this->telegramCallbackPayload('sv:t:z:a', 30));

        $document = collect(Http::recorded())->first(fn ($pair) => str_contains($pair[0]->url(), 'sendDocument'));
        $this->assertNotNull($document);
        $caption = collect($document[0]->data())->firstWhere('name', 'caption')['contents'] ?? '';
        $this->assertSame('أذكار إضافية', $caption);
        $this->assertSame(0, ReminderLog::query()->count());
    }

    public function test_salawat_is_one_item_and_moved_or_merged_items_are_hidden(): void
    {
        foreach (['additional.03', 'additional.10', 'additional.11'] as $key) {
            $this->assertFalse(AdhkarItem::query()->where('key', $key)->sole()->active, $key);
        }

        $salawat = AdhkarItem::query()->where('key', 'additional.09')->sole();
        $this->assertSame('الصلاة على النبي ﷺ', $salawat->title);
        $this->assertStringContainsString('كَمَا صَلَّيْتَ عَلَى إِبْرَاهِيمَ', $salawat->arabic_text);
        $this->assertStringContainsString('عشرًا', $salawat->virtue);
        $this->assertStringNotContainsString('درجتها', $salawat->source_name);
    }

    public function test_item_card_navigates_in_place_and_old_buttons_still_work(): void
    {
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);
        $items = AdhkarItem::query()->where('category', AdhkarCategory::Additional)->where('active', true)->orderBy('order')->get()->values();
        $second = $items[1];

        $this->process($this->telegramCallbackPayload('add:item:'.$second->id, 21));
        Http::assertSent(function ($request) use ($items) {
            $text = (string) ($request['text'] ?? '');
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($request->url(), 'editMessageText')
                && str_contains($text, 'الذكر 2 من '.$items->count())
                && ! str_contains($text, 'الراوي')
                && str_contains($markup, 'add:item:'.$items[0]->id)
                && str_contains($markup, 'add:item:'.$items[2]->id)
                && str_contains($markup, 'add:list');
        });

        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        $this->process($this->telegramCallbackPayload('add:item:'.$second->id.':short', 22));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'الذكر 2 من'));

        $this->process($this->telegramCallbackPayload('add:group:salawat', 23));
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'add:item:'.$items[0]->id)
            && ! str_contains((string) ($request['reply_markup'] ?? ''), 'add:group:'));
    }

    public function test_completion_callback_is_idempotent(): void
    {
        $user = User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);
        $log = ReminderLog::factory()->create([
            'user_id' => $user->id,
            'status' => ReminderLogStatus::Sent,
            'sent_at' => now(),
            'reading_started_at' => now(),
            'scheduled_local_date' => now()->timezone('Asia/Damascus')->toDateString(),
            'content_snapshot_hash' => str_repeat('ab', 32),
            'content_version_map' => [['order' => 1, 'key' => 'morning.01', 'content_version' => 1]],
        ]);

        $this->process($this->telegramCallbackPayload('reminder:done:'.$log->id, 40));
        $this->assertNotNull($log->fresh()->completed_at);

        $this->process($this->telegramCallbackPayload('reminder:done:'.$log->id, 41));
        $this->assertSame(ReminderLogStatus::Completed, $log->fresh()->status);
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

<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Models\AdhkarCollection;
use App\Models\AdhkarItem;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\AdhkarPdfService;
use App\Services\ContentSyncService;
use App\Services\Telegram\TelegramUpdateProcessor;
use App\Support\Telegram\KeyboardFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class BedtimeWakingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200),
        ]);
        app(ContentSyncService::class)->sync();
    }

    public function test_sync_creates_both_collections_in_source_order(): void
    {
        $this->assertSame(
            array_map(fn (int $n) => sprintf('bedtime.%02d', $n), range(1, 13)),
            $this->keys('bedtime'),
        );
        $this->assertSame(['waking.01', 'waking.02'], $this->keys('waking'));

        $this->assertSame('أذكار النوم', AdhkarCollection::query()->where('key', 'bedtime')->value('title'));
        $this->assertSame('أذكار الاستيقاظ', AdhkarCollection::query()->where('key', 'waking')->value('title'));
        $this->assertTrue(app(ContentSyncService::class)->isComplete());
    }

    public function test_existing_collections_are_unchanged_and_resync_is_idempotent(): void
    {
        $this->assertSame(9, count($this->keys('morning_short')));
        $this->assertSame(20, count($this->keys('morning_full')));
        $this->assertSame(9, count($this->keys('evening_short')));
        $this->assertSame(19, count($this->keys('evening_full')));
        $this->assertSame(10, count($this->keys('additional')));

        $second = app(ContentSyncService::class)->sync();
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['updated']);
        $this->assertSame(0, AdhkarItem::query()->whereIn('category', ['bedtime', 'waking'])
            ->whereHas('collections', fn ($q) => $q->whereNotIn('key', ['bedtime', 'waking']))->count());
    }

    public function test_waking_excludes_night_waking_narrations(): void
    {
        $texts = AdhkarItem::query()->where('category', 'waking')->pluck('arabic_text')->implode("\n");

        $this->assertStringContainsString('أَحْيَانَا بَعْدَ مَا أَمَاتَنَا', $texts);
        $this->assertStringContainsString('عَافَانِي فِي جَسَدِي', $texts);
        $this->assertStringNotContainsString('تعار', $texts);
        $this->assertStringNotContainsString('وَحْدَهُ لَا شَرِيكَ لَهُ', $texts);
        $this->assertStringNotContainsString('اخْتِلَافِ اللَّيْلِ', $texts);
        $this->assertFalse(AdhkarItem::query()->where('arabic_text', 'like', '%تعار%')->exists());
    }

    public function test_repetitions_and_sources_are_preserved(): void
    {
        $item = fn (string $key) => AdhkarItem::query()->where('key', $key)->sole();

        $this->assertSame(3, $item('bedtime.01')->repetitions_count);
        $this->assertStringContainsString('قُلْ هُوَ اللَّهُ أَحَدٌ', $item('bedtime.01')->arabic_text);
        $this->assertStringContainsString('قُلْ أَعُوذُ بِرَبِّ الْفَلَقِ', $item('bedtime.01')->arabic_text);
        $this->assertStringContainsString('قُلْ أَعُوذُ بِرَبِّ النَّاسِ', $item('bedtime.01')->arabic_text);
        $this->assertSame('5017', $item('bedtime.01')->hadith_number);

        $tasbih = $item('bedtime.08');
        $this->assertSame('33 · 33 · 34', $tasbih->repetitions_label);
        $this->assertStringContainsString('أربعاً وثلاثين', $tasbih->arabic_text);

        $this->assertSame('2311', $item('bedtime.02')->hadith_number);
        $this->assertSame('5009', $item('bedtime.03')->hadith_number);
        $this->assertSame('6312', $item('bedtime.07')->hadith_number);
        $this->assertSame('2713', $item('bedtime.09')->hadith_number);
        $this->assertSame('247', $item('bedtime.13')->hadith_number);
        $this->assertSame('6312', $item('waking.01')->hadith_number);
        $this->assertSame('3401', $item('waking.02')->hadith_number);

        $this->assertNull($item('bedtime.06')->repetitions_label);
        $this->assertNull($item('waking.01')->virtue);
        $this->assertSame(5, AdhkarItem::query()->where('category', 'bedtime')->whereNotNull('virtue')->count());
        $this->assertSame(0, AdhkarItem::query()->whereIn('category', ['bedtime', 'waking'])->whereNull('source_name')->count());
    }

    public function test_main_keyboard_has_each_new_button_once_on_its_own_row(): void
    {
        $keyboard = app(KeyboardFactory::class)->mainReplyKeyboard($this->user());
        $labels = collect($keyboard['keyboard'])->flatten(1)->pluck('text');

        $this->assertSame(1, $labels->filter(fn ($l) => $l === '🌙 أذكار النوم')->count());
        $this->assertSame(1, $labels->filter(fn ($l) => $l === '🌅 أذكار الاستيقاظ')->count());
        $this->assertSame('📖 أذكار اليوم', $keyboard['keyboard'][0][1]['text']);
        $this->assertSame('🌟 أذكار إضافية', $keyboard['keyboard'][0][0]['text']);
        // Telegram draws the first button on the left, so النوم (the first choice) sits on the right.
        $this->assertSame(['🌅 أذكار الاستيقاظ', '🌙 أذكار النوم'], array_column($keyboard['keyboard'][1], 'text'));
        $this->assertSame('⚙️ الإعدادات', $keyboard['keyboard'][2][1]['text']);
    }

    public function test_bedtime_button_opens_the_first_card_immediately(): void
    {
        $this->user();
        $this->message('🌙 أذكار النوم', 30);

        $request = $this->lastSent('sendMessage');
        $text = (string) $request['text'];
        $markup = $this->markup($request);

        $this->assertStringStartsWith('🌙 أذكار النوم', $text);
        $this->assertStringContainsString('الذكر 1 من 13', $text);
        $this->assertStringContainsString('المعوّذات قبل النوم', $text);
        $this->assertStringContainsString('📚 صحيح البخاري 5017', $text);
        $this->assertStringNotContainsString('مختصر', $text);
        $this->assertStringNotContainsString('كامل', $text);
        $this->assertStringNotContainsString('أذكار الصباح', $text);
        $this->assertStringNotContainsString('💡', $text);

        $this->assertSame([['text' => 'التالي', 'callback_data' => 'rt:b:a:1']], $markup['inline_keyboard'][0]);
        $this->assertSame('sv:t:b:a', $markup['inline_keyboard'][1][0]['callback_data']);
        $this->assertSame('📥 أرسل الورد كاملًا', $markup['inline_keyboard'][1][0]['text']);
        $this->assertCount(2, $markup['inline_keyboard']);
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['reply_markup'] ?? ''), 'rt:b:s:'));
    }

    public function test_waking_button_opens_the_first_card_immediately(): void
    {
        $this->user();
        $this->message('🌅 أذكار الاستيقاظ', 31);

        $request = $this->lastSent('sendMessage');
        $text = (string) $request['text'];

        $this->assertStringStartsWith('🌅 أذكار الاستيقاظ', $text);
        $this->assertStringContainsString('الذكر 1 من 2', $text);
        $this->assertStringContainsString('أَحْيَانَا بَعْدَ مَا أَمَاتَنَا', $text);
        $this->assertStringNotContainsString('مختصر', $text);
        $this->assertStringNotContainsString('أذكار الصباح', $text);
        $this->assertSame('rt:w:a:1', $this->markup($request)['inline_keyboard'][0][0]['callback_data']);
    }

    public function test_next_previous_progress_and_last_card_boundary(): void
    {
        $this->user();

        $this->press('rt:b:a:1', 40);
        $request = $this->lastSent('editMessageText');
        $row = $this->markup($request)['inline_keyboard'][0];
        $this->assertStringContainsString('الذكر 2 من 13', (string) $request['text']);
        $this->assertStringContainsString('آية الكرسي', (string) $request['text']);
        $this->assertStringContainsString('💡 الفضل', (string) $request['text']);
        // First button in a row renders on the left: السابق left, التالي right.
        $this->assertSame(['السابق', 'التالي'], array_column($row, 'text'));
        $this->assertSame(['rt:b:a:0', 'rt:b:a:2'], array_column($row, 'callback_data'));

        $this->press('rt:b:a:0', 41);
        $this->assertStringContainsString('الذكر 1 من 13', (string) $this->lastSent('editMessageText')['text']);

        $this->press('rt:b:a:12', 42);
        $last = $this->lastSent('editMessageText');
        $markup = json_encode($this->markup($last), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('الذكر 13 من 13', (string) $last['text']);
        $this->assertStringContainsString('انتهت أذكار النوم', (string) $last['text']);
        $this->assertStringContainsString('rt:b:a:11', $markup);
        $this->assertStringNotContainsString('التالي', $markup);
        $this->assertStringNotContainsString('أكمل الورد الكامل', $markup);
        $this->assertStringNotContainsString('reminder:done:', $markup);

        $this->press('rt:w:a:1', 43);
        $waking = $this->lastSent('editMessageText');
        $this->assertStringContainsString('الذكر 2 من 2', (string) $waking['text']);
        $this->assertStringContainsString('انتهت أذكار الاستيقاظ', (string) $waking['text']);
    }

    public function test_out_of_range_and_unknown_payloads_fail_calmly(): void
    {
        $this->user();

        foreach (['rt:b:s:0', 'rt:w:f:0', 'rt:x:a:0', 'sv:t:b:s', 'sv:t:x:a'] as $index => $data) {
            $this->press($data, 50 + $index);
        }
        $toasts = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'answerCallbackQuery'))
            ->map(fn ($pair) => (string) ($pair[0]['text'] ?? ''));
        $this->assertCount(5, $toasts->filter(fn ($t) => $t === __('bot.unknown')));

        $this->press('rt:b:a:13', 60);
        Http::assertSent(fn ($r) => (string) ($r['text'] ?? '') === __('bot.content_missing'));
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['text'] ?? ''), 'أذكار الصباح'));
    }

    public function test_full_ward_sends_correctly_titled_pdfs(): void
    {
        $this->user();

        $this->press('sv:t:b:a', 70);
        $this->press('sv:t:w:a', 71);

        $documents = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendDocument'))->values();
        $this->assertCount(2, $documents);
        $this->assertSame('أذكار النوم', $this->multipartField($documents[0][0], 'caption'));
        $this->assertSame('أذكار الاستيقاظ', $this->multipartField($documents[1][0], 'caption'));
        $this->assertStringStartsWith('%PDF', $this->multipartField($documents[0][0], 'document'));
    }

    public function test_full_ward_text_fallback_keeps_the_right_title(): void
    {
        $this->user();
        $this->app->instance(AdhkarPdfService::class, new class(app(\App\Services\AdhkarContentService::class)) extends AdhkarPdfService
        {
            public function build(string $collectionKey, \Illuminate\Support\Collection $items, bool $fullOnly = false): array
            {
                throw new RuntimeException('pdf unavailable');
            }
        });

        $this->press('sv:t:w:a', 80);

        $texts = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'sendMessage'))
            ->map(fn ($pair) => (string) ($pair[0]['text'] ?? ''))
            ->values();
        $this->assertSame(__('bot.pdf_failed'), $texts[0]);
        $this->assertStringStartsWith('🌅 أذكار الاستيقاظ', $texts[1]);
        $this->assertStringContainsString('الذكر 2 من 2', $texts[1]);
        $this->assertStringNotContainsString('أذكار الصباح', $texts->implode("\n"));
    }

    public function test_manual_reading_creates_no_reminder_state(): void
    {
        $user = $this->user();
        $before = ReminderLog::query()->count();

        $this->message('🌙 أذكار النوم', 90);
        $this->press('rt:b:a:12', 91);
        $this->message('🌅 أذكار الاستيقاظ', 92);
        $this->press('rt:w:a:1', 93);
        $this->press('sv:t:b:a', 94);

        $this->assertSame($before, ReminderLog::query()->count());
        $this->assertSame(AdhkarMode::Short, $user->fresh()->mode);
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['reply_markup'] ?? ''), 'reminder:'));
        Http::assertNotSent(fn ($r) => str_contains((string) ($r['reply_markup'] ?? ''), '✅'));
    }

    public function test_today_menu_and_existing_readers_are_unchanged(): void
    {
        $this->user();

        $this->message('/today', 100);
        $today = $this->markup($this->lastSent('sendMessage'));
        $this->assertSame(
            ['today:morning', 'today:evening', 'add:list'],
            collect($today['inline_keyboard'])->flatten(1)->pluck('callback_data')->all(),
        );

        $this->press('rt:m:s:0', 101);
        $morning = (string) $this->lastSent('editMessageText')['text'];
        $this->assertStringStartsWith("🌅 أذكار الصباح\n🌿 مختصر", $morning);

        $this->press('rt:e:a:0', 102);
        $evening = (string) $this->lastSent('editMessageText')['text'];
        $this->assertStringStartsWith("🌙 أذكار المساء\n🌿 كامل", $evening);

        $this->message('🌟 أذكار إضافية', 103);
        Http::assertSent(fn ($r) => str_contains((string) ($r['reply_markup'] ?? ''), 'add:group:all'));
    }

    /**
     * @return list<string>
     */
    private function keys(string $collectionKey): array
    {
        return AdhkarCollection::query()->where('key', $collectionKey)->sole()
            ->items()->where('adhkar_items.active', true)->pluck('adhkar_items.key')->all();
    }

    private function user(): User
    {
        return User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'timezone' => 'Asia/Damascus',
            'timezone_label' => 'سوريا',
            'mode' => AdhkarMode::Short,
        ]);
    }

    private function message(string $text, int $updateId): void
    {
        app(TelegramUpdateProcessor::class)->handle($this->telegramMessagePayload($text, $updateId, 1001));
    }

    private function press(string $data, int $updateId): void
    {
        app(TelegramUpdateProcessor::class)->handle($this->telegramCallbackPayload($data, $updateId, 1001));
    }

    private function lastSent(string $method): Request
    {
        $pair = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/'.$method))->last();
        $this->assertNotNull($pair, "No {$method} request was sent.");

        return $pair[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function markup(Request $request): array
    {
        return json_decode((string) ($request['reply_markup'] ?? ''), true) ?? [];
    }

    private function multipartField(Request $request, string $name): string
    {
        foreach ($request->data() as $part) {
            if (is_array($part) && ($part['name'] ?? null) === $name) {
                return (string) ($part['contents'] ?? '');
            }
        }

        return '';
    }
}

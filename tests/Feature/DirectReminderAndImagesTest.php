<?php

namespace Tests\Feature;

use App\Enums\AdhkarMode;
use App\Enums\ReminderLogStatus;
use App\Enums\ReminderType;
use App\Jobs\SendAdhkarReminderJob;
use App\Models\ReminderLog;
use App\Models\User;
use App\Services\ContentSyncService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DirectReminderAndImagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200),
        ]);
        app(ContentSyncService::class)->sync();
    }

    public function test_settings_switch_between_nudge_and_direct_reminders(): void
    {
        $user = $this->user();

        $this->press('settings:style', 1);
        $this->assertSame('direct', $user->fresh()->reminder_style);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'رح يوصلك أول ذكر مباشرة')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'الأذكار بتوصل مباشرة'));

        $this->press('settings:style', 2);
        $this->assertSame('nudge', $user->fresh()->reminder_style);
    }

    public function test_direct_reminder_opens_on_the_first_card_of_the_remembered_wird(): void
    {
        $user = $this->user(['reminder_style' => 'direct', 'mode' => AdhkarMode::Full, 'mode_chosen_at' => now()]);
        $log = $this->pendingLog($user, 'morning_full');

        SendAdhkarReminderJob::dispatchSync($log->id);

        $log->refresh();
        $this->assertSame(ReminderLogStatus::Sent, $log->status);
        $this->assertNotNull($log->reading_started_at);
        $this->assertNotEmpty($log->content_snapshot_hash);
        Http::assertSent(function ($request) use ($log) {
            $text = (string) ($request['text'] ?? '');
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($text, 'حان وقت أذكار الصباح')
                && str_contains($text, 'الذكر 1 من')
                && ! str_contains($text, 'اختر الورد')
                && str_contains($markup, 'rd:'.$log->id.':a:1')
                && str_contains($markup, 'rsw:'.$log->id.':s')
                && str_contains($markup, 'بدّل للورد المختصر');
        });

        $this->press('rd:'.$log->id.':a:1', 3);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageText')
            && str_contains((string) ($request['text'] ?? ''), 'الذكر 2 من'));

        $this->press('rsw:'.$log->id.':s', 4);
        $this->assertSame('morning_short', $log->fresh()->collection_key);
        $this->assertSame(AdhkarMode::Short, $user->fresh()->mode);
    }

    public function test_nudge_style_still_sends_the_choice_message(): void
    {
        $user = $this->user();
        $log = $this->pendingLog($user, 'evening_short');

        SendAdhkarReminderJob::dispatchSync($log->id);

        $this->assertNull($log->fresh()->reading_started_at);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'اختر الورد'));
    }

    public function test_full_wird_is_sent_as_photos_with_a_print_button_when_pages_can_be_made(): void
    {
        config(['adhkar.pdftoppm_binary' => 'pdftoppm']);
        Process::fake(function (PendingProcess $process) {
            $prefix = (string) end($process->command);
            foreach ([1, 2, 3] as $page) {
                File::put($prefix.'-'.$page.'.png', 'png'.$page);
            }

            return Process::result();
        });
        $this->user();

        $this->press('sv:t:m:s', 5);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMediaGroup'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendDocument'));
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'sp:t:m:s')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'نسخة PDF للطباعة'));

        $this->press('sp:t:m:s', 6);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendDocument'));
        Process::assertRanTimes(fn (PendingProcess $process) => true, 1);
    }

    public function test_full_wird_falls_back_to_pdf_when_pages_cannot_be_made(): void
    {
        config(['adhkar.pdftoppm_binary' => 'pdftoppm']);
        Process::fake(fn () => Process::result(exitCode: 127));
        $this->user();

        $this->press('sv:t:e:s', 7);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendDocument'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendMediaGroup'));
    }

    public function test_a_wiped_disk_reuses_telegram_copies_instead_of_rendering_again(): void
    {
        config(['adhkar.pdftoppm_binary' => 'pdftoppm']);
        Process::fake(function (PendingProcess $process) {
            $prefix = (string) end($process->command);
            foreach ([1, 2, 3] as $page) {
                File::put($prefix.'-'.$page.'.png', 'png'.$page);
            }

            return Process::result();
        });
        // setUp's catch-all fake would answer first, so start from a clean HTTP factory.
        Http::swap(new HttpFactory(app('events')));
        Http::fake([
            'https://api.telegram.org/*/sendMediaGroup' => Http::response(['ok' => true, 'result' => array_map(
                fn (int $page) => ['message_id' => $page, 'photo' => [['file_id' => 'small'.$page], ['file_id' => 'page'.$page]]],
                [1, 2, 3],
            )]),
            'https://api.telegram.org/*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 9, 'document' => ['file_id' => 'doc1']]]),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]]),
        ]);
        $this->user();

        $this->press('sv:t:m:s', 8);
        $this->press('sp:t:m:s', 9);
        Process::assertRanTimes(fn (PendingProcess $process) => true, 1);

        // A new container starts with an empty storage directory.
        File::cleanDirectory(storage_path('app/pdf'));

        $this->press('sv:t:m:s', 10);
        $this->press('sp:t:m:s', 11);

        Process::assertRanTimes(fn (PendingProcess $process) => true, 1);
        $this->assertSame([], glob(storage_path('app/pdf/*.pdf')) ?: []);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMediaGroup')
            && str_contains((string) ($request['media'] ?? ''), '"page2"'));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendDocument')
            && ($request['document'] ?? null) === 'doc1');
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/pdf/*-pages')) ?: [] as $directory) {
            File::deleteDirectory($directory);
        }
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function user(array $overrides = []): User
    {
        return User::factory()->create($overrides + [
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'timezone' => 'Asia/Damascus',
            'timezone_label' => 'سوريا',
            'mode' => AdhkarMode::Short,
        ]);
    }

    private function pendingLog(User $user, string $collectionKey): ReminderLog
    {
        return ReminderLog::factory()->create([
            'user_id' => $user->id,
            'reminder_type' => str_starts_with($collectionKey, 'evening') ? ReminderType::Evening : ReminderType::Morning,
            'collection_key' => $collectionKey,
            'status' => ReminderLogStatus::Pending,
            'scheduled_local_date' => now()->timezone($user->timezone)->toDateString(),
        ]);
    }

    private function press(string $data, int $updateId): void
    {
        app(TelegramUpdateProcessor::class)->handle($this->telegramCallbackPayload($data, $updateId, 1001));
    }
}

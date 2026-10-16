<?php

namespace Tests\Feature;

use App\Enums\OnboardingState;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\ContentSyncService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200),
        ]);
        app(ContentSyncService::class)->sync();
    }

    public function test_fresh_user_can_complete_onboarding(): void
    {
        $this->process($this->telegramMessagePayload('/start', 1));

        $user = User::query()->where('telegram_id', 1001)->first();
        $this->assertNotNull($user);
        $this->assertSame(OnboardingState::New, $user->onboarding_state);
        $this->assertNull($user->timezone);
        $this->assertNull($user->morning_time);
        $this->assertNull($user->evening_time);
        $this->assertFalse($user->reminders_enabled);
        Http::assertSent(function ($request) {
            $text = (string) ($request['text'] ?? '');
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($request->url(), 'sendMessage')
                && str_contains($text, 'أهلًا بك في بوت الأذكار')
                && str_contains($text, 'أذكار الصباح والمساء')
                && substr_count($text, "\n") <= 3
                && str_contains($markup, 'onb:begin')
                && str_contains($markup, 'ابدأ الإعداد')
                && str_contains($markup, 'help:topic:idea')
                && ! str_contains($markup, 'nav:back');
        });

        $this->process($this->telegramCallbackPayload('onb:begin', 2));
        $user->refresh();
        $this->assertSame(OnboardingState::WaitingTimezone, $user->onboarding_state);
        $this->assertNull($user->timezone);
        Http::assertSent(function ($request) {
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($request->url(), 'editMessageText')
                && str_contains((string) ($request['text'] ?? ''), 'اختر منطقتك')
                && ! str_contains($markup, 'nav:back');
        });

        $this->process($this->telegramCallbackPayload('onb:tz:0', 3));
        $user->refresh();
        $this->assertSame('Asia/Damascus', $user->timezone);
        $this->assertSame(OnboardingState::WaitingMorningTime, $user->onboarding_state);

        $this->process($this->telegramCallbackPayload('onb:time:morning:0600', 4));
        $user->refresh();
        $this->assertSame('06:00:00', $user->morning_time);
        $this->assertSame(OnboardingState::WaitingEveningTime, $user->onboarding_state);

        $this->process($this->telegramCallbackPayload('onb:time:evening:1800', 5));
        $user->refresh();
        $this->assertSame('18:00:00', $user->evening_time);
        $this->assertSame(OnboardingState::Completed, $user->onboarding_state);
        $this->assertTrue($user->reminders_enabled);
        $this->assertSame('direct', $user->reminder_style);
        $this->assertTrue($user->follow_up_enabled);
        $this->assertTrue($user->weekly_summary_enabled);
        $this->assertNotNull($user->next_morning_at);
        $this->assertNotNull($user->next_evening_at);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains((string) ($request['text'] ?? ''), 'كل شيء جاهز')
                && ! str_contains((string) ($request['text'] ?? ''), 'الورد:')
                && str_contains((string) ($request['reply_markup'] ?? ''), '"keyboard"');
        });
    }

    public function test_user_stuck_on_the_old_mode_step_finishes_setup(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'onboarding_state' => OnboardingState::WaitingMode,
            'reminders_enabled' => false,
        ]);

        $this->process($this->telegramCallbackPayload('onb:mode:full', 1));

        $this->assertSame(OnboardingState::Completed, $user->fresh()->onboarding_state);
        $this->assertTrue($user->fresh()->reminders_enabled);
    }

    public function test_time_errors_explain_the_mistake_and_suggest_a_fix(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'morning_time' => '06:00:00',
            'awaiting_input' => 'settings:morning_time_custom',
        ]);

        $this->process($this->telegramMessagePayload('7;42', 1));
        Http::assertSent(function ($request) {
            $text = (string) ($request['text'] ?? '');
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($text, 'ضع نقطتين ( : ) بين الساعة والدقائق')
                && str_contains($text, "كتبت:\n\u{200E}7;42\n")
                && str_contains($markup, 'onb:time:morning:0742')
                && str_contains($markup, 'nav:cancel');
        });
        $this->assertSame('06:00:00', $user->fresh()->morning_time);

        $this->process($this->telegramMessagePayload('7:090', 2));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'تُكتب الدقائق برقمين'));

        $this->process($this->telegramMessagePayload('25:00', 3));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'يجب أن تكون الساعة رقمًا من 1 إلى 12'));

        $this->process($this->telegramMessagePayload('<b>صباحًا</b>', 4));
        Http::assertSent(function ($request) {
            $text = (string) ($request['text'] ?? '');

            return str_contains($text, 'لا يتضمن ساعة') && str_contains($text, '&lt;b&gt;');
        });

        $this->process($this->telegramCallbackPayload('onb:time:morning:0742', 5));
        $this->assertSame('07:42:00', $user->fresh()->morning_time);
        $this->assertNull($user->fresh()->awaiting_input);
    }

    public function test_unknown_country_names_what_was_typed(): void
    {
        $this->process($this->telegramMessagePayload('/start', 1));
        $this->process($this->telegramCallbackPayload('onb:begin', 2));
        $this->process($this->telegramCallbackPayload('onb:tz:custom', 3));
        $this->process($this->telegramMessagePayload('زحل', 4));
        $this->process($this->telegramMessagePayload('123', 5));

        Http::assertSent(function ($request) {
            return str_contains((string) ($request['text'] ?? ''), 'لم أجد «زحل»')
                && str_contains((string) ($request['reply_markup'] ?? ''), 'nav:back');
        });
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'يُكتب اسم البلد بالحروف'));
    }

    public function test_unrelated_text_during_onboarding_shows_the_current_step(): void
    {
        User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'onboarding_state' => OnboardingState::WaitingMorningTime,
            'timezone' => 'Asia/Damascus',
            'morning_time' => null,
            'evening_time' => null,
        ]);

        $this->process($this->telegramMessagePayload('مرحبا', 1));

        Http::assertSent(function ($request) {
            return str_contains((string) ($request['text'] ?? ''), 'ما زلنا نُكمل الإعداد')
                && str_contains((string) ($request['text'] ?? ''), 'اخترنا لك وقتين')
                && str_contains((string) ($request['reply_markup'] ?? ''), 'onb:quick:yes');
        });
    }

    public function test_invalid_custom_time_is_reprompted(): void
    {
        $this->process($this->telegramMessagePayload('/start', 1));
        $this->process($this->telegramCallbackPayload('onb:tz:0', 2));
        $this->process($this->telegramCallbackPayload('onb:time:morning:custom', 3));
        $this->process($this->telegramMessagePayload('not-a-time', 4));

        $user = User::query()->where('telegram_id', 1001)->first();
        $this->assertSame(OnboardingState::WaitingMorningTime, $user->onboarding_state);
        $this->assertNull($user->morning_time);
        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', '5:30 صباحًا'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'HH:MM'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), '24 ساعة'));
    }

    public function test_america_asks_for_a_region_before_saving(): void
    {
        $index = collect(config('adhkar.timezones'))->search(fn ($row) => ($row['label'] ?? '') === 'أمريكا');
        $this->assertNotFalse($index);

        $this->process($this->telegramMessagePayload('/start', 1));
        $this->process($this->telegramCallbackPayload('onb:begin', 2));
        $this->process($this->telegramCallbackPayload('onb:tz:'.$index, 3));

        $user = User::query()->where('telegram_id', 1001)->first();
        $this->assertNull($user->timezone);
        $this->assertSame(OnboardingState::WaitingTimezone, $user->onboarding_state);

        $this->process($this->telegramCallbackPayload('onb:tzr:'.$index.':0', 4));
        $user->refresh();
        $this->assertSame('America/New_York', $user->timezone);
        $this->assertStringContainsString('نيويورك', (string) $user->timezone_label);
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'America/New_York'));
    }

    public function test_back_and_arabic_time_during_onboarding(): void
    {
        $this->process($this->telegramMessagePayload('/start', 1));
        $this->process($this->telegramCallbackPayload('onb:tz:0', 2));
        $this->process($this->telegramCallbackPayload('onb:time:morning:custom', 3));
        $this->process($this->telegramCallbackPayload('nav:back', 4));

        $user = User::query()->where('telegram_id', 1001)->first();
        $this->assertNull($user->awaiting_input);
        $this->assertSame(OnboardingState::WaitingMorningTime, $user->onboarding_state);

        $this->process($this->telegramCallbackPayload('onb:time:morning:custom', 5));
        $this->process($this->telegramMessagePayload('٥:٣٠', 6));
        $user->refresh();
        $this->assertSame('05:30:00', $user->morning_time);
        $this->assertSame(OnboardingState::WaitingEveningTime, $user->onboarding_state);
    }

    public function test_onboarding_custom_time_offers_back_without_cancel(): void
    {
        $this->process($this->telegramMessagePayload('/start', 1));
        $this->process($this->telegramCallbackPayload('onb:tz:0', 2));
        $this->process($this->telegramCallbackPayload('onb:time:morning:custom', 3));

        Http::assertSent(function ($request) {
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($markup, 'nav:back')
                && str_contains((string) ($request['text'] ?? ''), '6 ونصف صباحًا')
                && ! str_contains($markup, 'nav:cancel');
        });
    }

    public function test_settings_custom_time_keeps_cancel_and_confirms_ambiguous_evening_hour(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'evening_time' => '18:00:00',
        ]);

        $this->process($this->telegramCallbackPayload('onb:time:evening:custom', 1));
        Http::assertSent(function ($request) {
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($markup, 'nav:back') && str_contains($markup, 'nav:cancel');
        });

        $this->process($this->telegramMessagePayload('08:00', 2));
        $user->refresh();
        $this->assertSame('18:00:00', $user->evening_time);
        $this->assertSame('settings:evening_time_confirm', $user->awaiting_input);

        $this->process($this->telegramCallbackPayload('onb:time:evening:2000', 3));
        $user->refresh();
        $this->assertSame('20:00:00', $user->evening_time);
        $this->assertNull($user->awaiting_input);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), '8:00 م'));
    }

    public function test_returning_start_does_not_restart_onboarding(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'timezone_label' => 'سوريا',
            'onboarding_state' => OnboardingState::Completed,
        ]);

        $this->process($this->telegramMessagePayload('/start', 1));

        $this->assertSame(OnboardingState::Completed, $user->fresh()->onboarding_state);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'أهلًا بعودتك'));
        Http::assertSent(function ($request) {
            $markup = json_decode((string) ($request['reply_markup'] ?? ''), true);

            return str_contains((string) ($request['text'] ?? ''), 'أيقونة الأزرار')
                && is_array($markup)
                && isset($markup['keyboard'])
                && ! isset($markup['is_persistent']);
        });
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'سوريا'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'Asia/Damascus'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'أهلًا بك في بوت الأذكار'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'onb:begin'));
    }

    public function test_explain_button_resends_the_guide_without_restarting_setup(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'timezone_label' => 'سوريا',
            'onboarding_state' => OnboardingState::Completed,
            'reminders_enabled' => true,
        ]);

        $this->process($this->telegramMessagePayload('🌿 شرح البوت', 1));

        $fresh = $user->fresh();
        $this->assertSame(OnboardingState::Completed, $fresh->onboarding_state);
        $this->assertTrue($fresh->reminders_enabled);
        $this->assertSame('سوريا', $fresh->timezone_label);
        Http::assertSent(function ($request) {
            $text = (string) ($request['text'] ?? '');
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($text, 'فكرة البوت')
                && str_contains($text, 'أذكار الصباح والمساء')
                && str_contains($text, 'أذكار إضافية')
                && str_contains($markup, 'help:menu')
                && ! str_contains($markup, 'onb:begin');
        });

        $this->process($this->telegramCallbackPayload('guide:explain', 2));
        $this->assertSame(OnboardingState::Completed, $user->fresh()->onboarding_state);
    }

    public function test_help_menu_ends_with_back_once_set_up_and_with_start_setup_before(): void
    {
        $user = User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $this->process($this->telegramCallbackPayload('help:menu', 1));
        Http::assertSent(fn ($request) => str_contains((string) ($request['reply_markup'] ?? ''), 'nav:home')
            && ! str_contains((string) ($request['reply_markup'] ?? ''), 'onb:begin'));

        $this->process($this->telegramCallbackPayload('nav:home', 2));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'أهلًا بعودتك'));

        $user->update(['onboarding_state' => OnboardingState::New]);
        $this->process($this->telegramCallbackPayload('help:menu', 3));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'اختر سؤالك')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'onb:begin')
            && ! str_contains((string) ($request['reply_markup'] ?? ''), 'nav:home'));
    }

    public function test_help_lists_questions_and_answers_each_one(): void
    {
        User::factory()->create(['telegram_id' => 1001, 'chat_id' => 1001]);

        $this->process($this->telegramMessagePayload('❓ المساعدة والشرح', 1));
        Http::assertSent(function ($request) {
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains((string) ($request['text'] ?? ''), 'اختر سؤالك')
                && str_contains($markup, 'help:topic:font')
                && str_contains($markup, 'help:topic:missing')
                && ! str_contains($markup, 'help:topic:night')
                && str_contains($markup, 'help:topic:style')
                && str_contains($markup, 'help:topic:groups')
                && ! str_contains((string) ($request['text'] ?? ''), '/start');
        });

        $this->process($this->telegramCallbackPayload('help:topic:style', 6));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'الأذكار مباشرة')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'settings:style'));

        $this->process($this->telegramCallbackPayload('help:topic:groups', 7));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'ضبط الأوقات'));

        $this->process($this->telegramCallbackPayload('help:topic:font', 2));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'editMessageText')
            && str_contains((string) ($request['text'] ?? ''), 'حجم نص الرسائل')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'help:menu'));

        $this->process($this->telegramCallbackPayload('help:topic:modes', 3));
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'نحو 5 دقائق'));

        $this->process($this->telegramMessagePayload('❓ مساعدة', 4));
        $this->process($this->telegramMessagePayload('/help', 5));
        $this->assertSame(3, collect(Http::recorded())->filter(
            fn ($pair) => str_contains((string) ($pair[0]['text'] ?? ''), 'اختر سؤالك')
        )->count());
    }

    public function test_new_user_can_read_the_idea_before_setup(): void
    {
        $this->process($this->telegramMessagePayload('/start', 1));
        $this->process($this->telegramCallbackPayload('help:topic:idea', 2));

        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'فكرة البوت')
            && str_contains((string) ($request['reply_markup'] ?? ''), 'onb:begin'));
        $this->assertSame(OnboardingState::New, User::query()->where('telegram_id', 1001)->first()->onboarding_state);
    }

    public function test_commands_are_registered_with_arabic_descriptions(): void
    {
        $this->artisan('telegram:commands:set')->assertSuccessful();

        Http::assertSent(function ($request) {
            $commands = json_decode((string) ($request['commands'] ?? ''), true);

            return str_contains($request->url(), 'setMyCommands')
                && (collect($commands)->firstWhere('command', 'today')['description'] ?? null) === 'أذكار اليوم';
        });
    }

    public function test_start_during_onboarding_resumes_the_current_step(): void
    {
        $user = User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'onboarding_state' => OnboardingState::WaitingEveningTime,
            'timezone' => 'Asia/Damascus',
            'timezone_label' => 'سوريا',
            'morning_time' => '06:00:00',
            'evening_time' => null,
            'reminders_enabled' => false,
        ]);

        $this->process($this->telegramMessagePayload('/start', 1));

        $fresh = $user->fresh();
        $this->assertSame(OnboardingState::WaitingEveningTime, $fresh->onboarding_state);
        $this->assertSame('06:00:00', $fresh->morning_time);
        $this->assertNull($fresh->evening_time);
        $this->assertSame('سوريا', $fresh->timezone_label);
        Http::assertSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'تحب أن نذكّرك بأذكار المساء'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'أهلًا بك في بوت الأذكار'));
        Http::assertNotSent(fn ($request) => str_contains((string) ($request['text'] ?? ''), 'Asia/Damascus'));
    }

    public function test_settings_timezone_keyboard_keeps_back(): void
    {
        User::factory()->create([
            'telegram_id' => 1001,
            'chat_id' => 1001,
            'onboarding_state' => OnboardingState::Completed,
            'timezone_label' => 'سوريا',
        ]);

        $this->process($this->telegramCallbackPayload('settings:timezone', 1));

        Http::assertSent(function ($request) {
            $markup = (string) ($request['reply_markup'] ?? '');

            return str_contains($request->url(), 'editMessageText')
                && str_contains((string) ($request['text'] ?? ''), 'اختر منطقتك')
                && str_contains($markup, 'nav:back')
                && ! str_contains((string) ($request['text'] ?? ''), 'Asia/Damascus');
        });
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

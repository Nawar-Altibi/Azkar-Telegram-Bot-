# Current Bot Audit

> Read-only inspection of the repository at commit `0c6677b` (2026-10-01, "feat: typeset the wird PDF in Amiri with a cleaner card layout"), working tree as found on 2026-10-03.
> The only pre-existing working-tree change is a staged deletion of `POSTGRES_PRODUCTION_SETUP_REPORT.md`; it was not touched.
> No code, migration, content, or configuration was modified. Tests were **not executed** (to avoid writing PDF/cache artifacts); test coverage below is from reading test code.
>
> Labels used throughout:
> - **FACT** — directly established by reading the cited file/line.
> - **INFERENCE** — follows from the code but was not executed/observed.
> - **UNKNOWN** — `Unknown — not established by repository inspection.`
>
> `.env` and `database/database.sqlite` exist locally but are git-ignored; they were deliberately not read.

---

## 1. Project Overview

| Topic | Finding | Evidence |
|---|---|---|
| What it is | Arabic-first Telegram bot that reminds users of morning/evening adhkar at user-chosen local times, lets them read adhkar one card at a time, and offers an optional "additional adhkar" library. | FACT — `composer.json` description; `lang/ar/bot.php` `welcome`, `explain_body` |
| Framework | Laravel 11 (`laravel/framework ^11.31`, locked **v11.56.1**) | FACT — `composer.json`, `composer.lock` |
| PHP requirement | `^8.2` | FACT — `composer.json` `require.php` |
| Other runtime deps | `mpdf/mpdf 8.3.1` (needs `ext-gd`, `ext-mbstring`), `laravel/tinker` | FACT — `composer.json`, `composer.lock` |
| Docker PHP | `php:8.3-fpm-bookworm` with `pdo_mysql pdo_pgsql bcmath opcache gd` | FACT — `Dockerfile` |
| DB default | `DB_CONNECTION` default `sqlite` | FACT — `config/database.php` `'default'`, `.env.example` |
| DB in deploy examples | MySQL (`deploy/production.env.example`, `docker-compose.yml` uses `mysql:8.4`) | FACT |
| DB per production docs | **Neon PostgreSQL** on Vercel; entrypoint refuses to start unless `DB_CONNECTION=pgsql` when `VERCEL_ENV=production` | FACT — `docs/PRODUCTION_DATABASE.md`, `docker/entrypoint.sh` `require_production_database()` |
| Which DB is live | UNKNOWN — repository contains both a MySQL path and a Neon/PostgreSQL path; README says "MySQL 8 (production)", `docs/PRODUCTION_DATABASE.md` says Neon. | |
| Queue | Laravel `database` queue (`jobs` table) by default; forced to `sync` when `VERCEL` env var is set | FACT — `config/queue.php`, `.env.example`, `docker/entrypoint.sh` |
| Scheduler | Two mechanisms: (a) Laravel scheduler (`routes/console.php`) driven by `schedule:run` cron; (b) HTTP `POST /internal/cron` → `CronTickService` (intended for cron-job.org on Vercel) | FACT — `routes/console.php`, `bootstrap/app.php`, `app/Services/CronTickService.php` |
| Telegram integration | Hand-written HTTP client over the Bot API (`Illuminate\Support\Facades\Http`), no SDK. Webhook in production, long-polling command for local dev. `parse_mode=HTML` for all text. | FACT — `app/Services/Telegram/TelegramClient.php`, `app/Console/Commands/PollTelegramCommand.php` |
| Cache / locks | `CACHE_STORE=database` (`cache`, `cache_locks` tables); used for content-sync marker, cron/recovery locks, PDF `file_id` cache | FACT — `.env.example`, `ContentSyncService`, `CronTickService`, `StaleRecoveryService`, `TelegramUpdateProcessor::sendWirdPdf` |
| Locale | `App::setLocale('ar')` in `AppServiceProvider::boot` | FACT |

### Entry points

| Entry point | File |
|---|---|
| `POST /telegram/webhook/{secret}` (middleware `VerifyTelegramWebhookSecret`, `throttle:telegram-webhook` = 30/min per chat) | `bootstrap/app.php`, `app/Http/Controllers/Telegram/TelegramWebhookController.php`, `app/Providers/AppServiceProvider.php` |
| `POST /internal/cron` (Bearer `CRON_SECRET`) | `app/Http/Controllers/Internal/SchedulerCronController.php` |
| `GET /internal/health` (Bearer `CRON_SECRET`) | `app/Http/Controllers/Internal/DatabaseHealthController.php` |
| `GET /up` (Laravel health) | `bootstrap/app.php` |
| `GET /` → `welcome` view | `routes/web.php` |
| Artisan: `adhkar:sync`, `adhkar:dispatch-due-reminders`, `adhkar:recover-stale`, `adhkar:health`, `adhkar:smoke`, `adhkar:db-status`, `telegram:poll`, `telegram:webhook:set` | `app/Console/Commands/*` |
| Queue jobs: `ProcessTelegramUpdateJob`, `SendAdhkarReminderJob` | `app/Jobs/*` |

### Important directories

| Path | Purpose |
|---|---|
| `app/Services/Telegram/TelegramUpdateProcessor.php` (1,087 lines) | All bot routing: commands, reply-keyboard text, callbacks, reading engine, PDF send, completion |
| `app/Support/Telegram/KeyboardFactory.php` | Every keyboard and callback string |
| `app/Services/AdhkarContentService.php` | Collection key logic, card/message rendering, snapshot hashing |
| `app/Services/ContentSyncService.php` | Content import from PHP data files into DB |
| `app/Services/ReminderDispatchService.php`, `app/Jobs/SendAdhkarReminderJob.php` | Reminder claim + send |
| `database/data/adhkar/{morning,evening,additional}.php` | Machine-readable religious content (the sync input) |
| `resources/adhkar/*.md` | Editorial Markdown (not parsed by code) |
| `app adhkar/*.md` | Byte-identical copies of `resources/adhkar/*.md` (verified by SHA-256) |
| `adhkar-research.md`, `adhkar_additional.md`, `ar_hisnulmuslim.pdf` | Research/provenance material (not used by code) |
| `config/adhkar.php` | Timezones, aliases, time presets, additional groups, reminder tuning |
| `lang/ar/bot.php` | All non-religious UI strings |
| `resources/views/pdf/wird.blade.php`, `resources/fonts/amiri/` | PDF template and font |
| `deploy/`, `docker/`, `Dockerfile`, `docker-compose.yml` | Deployment |
| `docs/` | Prior design docs (several are stale — see §22) |

### Deployment files

`deploy/azkar-worker.service`, `deploy/crontab.example`, `deploy/nginx.conf.example`, `deploy/production.env.example`, `deploy/supervisor-azkar-worker.conf`, `Dockerfile`, `docker-compose.yml`, `docker/entrypoint.sh`, `docker/nginx.conf`, `docker/php-fpm.conf`, `docker/php.ini`. No `vercel.json` exists in the repository (FACT).

---

## 2. Architecture

### 2.1 Incoming updates

```
Telegram Bot API
   │  POST /telegram/webhook/{secret}  (+ X-Telegram-Bot-Api-Secret-Token header)
   ▼
VerifyTelegramWebhookSecret ── 403 if path or header secret mismatch
   ▼
throttle:telegram-webhook (30/min keyed by chat id or IP)
   ▼
TelegramWebhookController
   ├─ insert telegram_updates (unique update_id; duplicate → 200 {"ok":true}, no reprocess)
   └─ ProcessTelegramUpdateJob::dispatch(id)
          │   database queue  → queue:work (Supervisor/systemd/docker "queue" role, or
          │                                  CronTickService's queue:work --stop-when-empty)
          │   sync queue (VERCEL) → runs inline inside the webhook request
          ▼
ProcessTelegramUpdateJob → TelegramUpdateProcessor::handle(payload)
   ├─ findOrCreateUser (users by telegram_id), set last_interaction_at
   ├─ callback_query → routeCallback(data)        (prefix-based router)
   └─ message.text   → command | reply-keyboard label | awaiting_input | unknown
          ▼
   OnboardingService / UserSettingsService / AdhkarContentService / AdhkarPdfService / KeyboardFactory
          ▼
   TelegramClient (sendMessage / editMessageText / answerCallbackQuery / sendDocument / sendChatAction)
```

### 2.2 Reminders

```
users.next_morning_at / users.next_evening_at  (UTC, computed by ReminderScheduleCalculator)
   ▼
Trigger A: cron "* * * * * php artisan schedule:run"
             → adhkar:dispatch-due-reminders (everyMinute, withoutOverlapping)
             → adhkar:recover-stale          (everyFiveMinutes, withoutOverlapping)
Trigger B: POST /internal/cron (cron-job.org per entrypoint comment)
             → CronTickService::run (cache lock 120 s, claim budget 25 s,
                                     stale recovery every ≥5 min, then queue:work --stop-when-empty if not sync)
   ▼
ReminderDispatchService::claimDueLogIds
   └─ per due user/type: DB transaction { insert reminder_logs(pending) ; advance next_*_at }
       → SendAdhkarReminderJob::dispatch(logId)
   ▼
SendAdhkarReminderJob (ShouldBeUnique, tries 3, backoff 5/30/120)
   └─ reserveOutboundAttempt (row lock) → sendMessage(nudge + Start buttons) → status=sent
   ▼
User taps 🌿 مختصر / 📖 كامل on nudge → reminder:start:{id}:{s|a}
   └─ TelegramUpdateProcessor::claimReading (row lock, snapshot) → reading cards → ✅ تم
```

---

## 3. User Flows

All handlers below are in `app/Services/Telegram/TelegramUpdateProcessor.php` unless stated otherwise. "Edit" = `editMessageText` on the pressed message; "New" = `sendMessage`.

**Edit vs new rule (FACT, `TelegramUpdateProcessor::show`)**: callbacks pass the pressed `message_id`; `show()` edits it **unless** the markup contains a reply `keyboard` (Telegram cannot attach one on edit) or the edit throws (then it falls back to a new message; "message is not modified" is silently ignored). Text messages/commands always go out as new messages (`reply()` or `show()` with `messageId = null`).

### 3.1 Brand-new user: `/start` → completion

| # | Trigger | User sees (text key → content) | Keyboard / callbacks | State & DB changes | Edit/New | Code |
|---|---|---|---|---|---|---|
| 0 | Any update | — | — | `users` row created on first update: `telegram_id`, `chat_id`, `first_name`, `username`, `language_code`, `onboarding_state='new'`, `reminders_enabled=false` (defaults: `mode='short'`). `last_interaction_at` updated on **every** update. | — | `handle`, `findOrCreateUser` |
| 1 | `/start` | `bot.tutorial` = "🌿 أهلاً بك في بوت الأذكار" + `explain_body` + "للبدء، سنضبط معًا: 🌍 منطقتك / 🌅 وقت أذكار الصباح / 🌙 وقت أذكار المساء" | inline `🚀 ابدأ الإعداد` → `onb:begin` | none | New | `handleCommand` → `OnboardingService::start` → `tutorialScreen`; `KeyboardFactory::tutorialStart` |
| 2 | `onb:begin` | `bot.ask_timezone` = "🌍 اختر منطقتك\n\nحتى نرسل التذكيرات في وقتها المناسب لك." | 22 country buttons, 2 per row (RTL-reversed) → `onb:tz:{index}`; `دولة أخرى` → `onb:tz:custom`; **no Back** on this first screen | `onboarding_state: new → waiting_timezone`, `awaiting_input=null` | Edit | `OnboardingService::beginSetup`, `KeyboardFactory::timezoneOptions(false)` |
| 2a | `onb:tz:{i}` where entry has `regions` (أمريكا) | `bot.ask_region` "اختر منطقتك:" | one button per region → `onb:tzr:{country}:{region}`; `🔙 رجوع` → `nav:back` | `awaiting_input='onboarding:timezone_region:{i}'` | Edit | `handleTimezoneCallback`, `KeyboardFactory::regionOptions` |
| 2b | `onb:tz:custom` | `bot.ask_timezone_custom` "اكتب اسم بلدك، مثل: سوريا، مصر، السعودية" | `🔙 رجوع` only | `awaiting_input='onboarding:timezone_custom'` | Edit | `handleTimezoneCallback` |
| 2c | Typed country text | If multi-zone (أمريكا aliases) → region step; if unresolved → `bot.timezone_digits` / `bot.timezone_unknown` + `🌍 اختر بلدك من القائمة` (`nav:back`) | — | resolved via `TimezoneCatalog::resolveWithLabel` (config `adhkar.timezone_aliases`, normalisation) | New | `handleCustomTimezone` |
| 3 | Timezone chosen (`onb:tz:{i}`, `onb:tzr:…`, or typed) | `bot.ask_morning_time` "🌅 متى تفضّل تذكير أذكار الصباح؟" | presets `05:00 05:30 06:00 06:30 07:00` (3/row, RTL) → `onb:time:morning:{HHMM}`; `⏰ وقت آخر` → `onb:time:morning:custom`; `🔙 رجوع` | `timezone` (IANA), `timezone_label` (e.g. "سوريا", "أمريكا · نيويورك"), `onboarding_state='waiting_morning_time'`, `awaiting_input=null` | Edit (callback) / New (typed) | `OnboardingService::handleTimezone`, `KeyboardFactory::timePresets` |
| 3a | `onb:time:morning:custom` | `bot.ask_time_custom_morning` (24-hour instructions) | `🔙 رجوع` | `awaiting_input='onboarding:morning_time_custom'` | Edit | `handleTimeCallback` |
| 3b | Typed time | Saved directly, or `bot.confirm_time_period` "أي وقت تقصد؟" with ص/م choices (bare 12 for morning; bare 1–11 or 12 for evening), or a specific error (`time_letters`, `time_bad_separator`, `time_missing_minutes`, …) with an optional "هل تقصد HH:MM؟" button | `onb:time:{kind}:{HHMM}` | `awaiting_input='onboarding:{kind}_time_confirm'` when confirming | New | `handleCustomTime`, `replyTimeError`, `ReminderScheduleCalculator::classifyTime` |
| 4 | Morning time chosen | `bot.ask_evening_time` "🌙 متى تفضّل تذكير أذكار المساء؟" | presets `16:00 … 19:00` (7 values) → `onb:time:evening:{HHMM}`; `⏰ وقت آخر`; `🔙 رجوع` | `morning_time='HH:MM:SS'`, `onboarding_state='waiting_evening_time'` | Edit (callback) / New (typed) | `OnboardingService::handleMorningTime` |
| 5 | Evening time chosen | `bot.onboarding_complete` = "✅ تم ضبط تذكيراتك\n\n" + summary (🌍 المنطقة / 🌅 الصباح / 🌙 المساء / 🔔 التذكير: مفعّل) | **Main reply keyboard** (§4) | `evening_time`, `onboarding_state='completed'`, `awaiting_input=null`, `reminders_enabled=true`, `paused_at=null`, `next_morning_at` & `next_evening_at` computed (UTC) | **New** (reply keyboard forces new message; the evening-presets message stays as-is) | `OnboardingService::handleEveningTime` → `finish` → `UserSettingsService::completeOnboarding` |

**Short/full selection during onboarding: does not exist.** FACT — the mode step was removed. `OnboardingState::WaitingMode` remains as an enum case; a user stuck there is finished directly (`OnboardingService::screenForState` → `finish`), and legacy `onb:mode:*` buttons also call `finish` (`routeCallback`, comment "Buttons from the removed mode step on old messages"). The short/full choice is now made **per reading** (§7, §8, §11).

**Back during onboarding** (`nav:back` → `OnboardingService::back`): clears `awaiting_input` and returns to the screen named in it, otherwise rewinds `onboarding_state` one step (`waiting_evening_time → waiting_morning_time → waiting_timezone`). Edit. FACT.

**Unrelated text during onboarding**: `bot.unknown_onboarding` + the current step's screen, new message (`handleUnknownText`). FACT.

**`/start` mid-onboarding**: re-shows the current step (`OnboardingService::start` → `screenForState`), no second tutorial. FACT.

### 3.2 A. Returning user `/start`

FACT — `OnboardingService::start` when `onboarding_state === completed`: text `bot.welcome_returning` = "🌿 أهلاً بعودتك\n\n" + summary; **inline** keyboard `KeyboardFactory::returningStart`:

| Button | Callback | Effect |
|---|---|---|
| `📖 أذكار اليوم` | `today:menu` | Edit → today menu (§9) |
| `⚙️ الإعدادات` | `settings:open` | Edit → settings (falls through to `sendSettings`) |
| `🔄 إعادة الإعداد` | `onb:reset` | `onboarding_state='waiting_timezone'`, `awaiting_input=null`, `reminders_enabled=false` (`next_*_at` are **not** cleared); edit → timezone screen (no Back) |
| `🌿 شرح البوت` | `guide:explain` | `bot.explain` + main reply keyboard → New |

New message; the reply keyboard is not re-sent by `/start`.

### 3.3 B. `/today`

See §9. Typed `/today` and the `📖 أذكار اليوم` reply button both send a **new** message `bot.today_prompt` "📖 ماذا تريد أن تقرأ؟" with `KeyboardFactory::todayMenu`. No DB writes other than `last_interaction_at`.

### 3.4 C. `/settings`

FACT — `sendSettings`: if `! $user->isOnboarded()` → `bot.need_start` "ابدأ أولًا بـ /start لإكمال الإعداد." Otherwise `bot.settings_header` "⚙️ الإعدادات" + summary, inline `KeyboardFactory::settingsMenu`:

| Button | Callback | Effect |
|---|---|---|
| `🌍 المنطقة` | `settings:timezone` | Edit → timezone list **with** Back. Choosing saves via `UserSettingsService::updateTimezone` (recalculates `next_*_at`) then edit → "تم حفظ المنطقة: …" + summary + settings menu |
| `🌅 وقت الصباح` / `🌙 وقت المساء` (one RTL row) | `settings:morning_time` / `settings:evening_time` | Edit → same presets keyboard as onboarding (`onb:time:*` callbacks). `handleTimeCallback` treats it as settings because `onboarding_state === completed`. Save → `updateMorningTime/updateEveningTime` (recalculate next) → edit to saved summary. Custom text prompts show `🔙 رجوع` + `❌ إلغاء` (`nav:cancel`) |
| `⏸ إيقاف التذكير` / `▶️ تفعيل التذكير` | `settings:toggle_reminders` | `UserSettingsService::toggleReminders` → pause/resume (D below). Reply is a **new** message with the main reply keyboard; the settings message is not edited |
| `🔙 رجوع` | `settings:close` | Edit → today menu |

There is **no mode/short-full control** in settings (FACT; legacy `settings:mode` buttons fall through to `sendSettings`, per the comment in `handleSettingsCallback`).

### 3.5 D. Pause / resume

FACT — `UserSettingsService::pause/resume`, reached by `/pause`, `/resume`, reply buttons (`⏸ إيقاف التذكير`, `▶️ تفعيل التذكير`, legacy aliases `إيقاف التذكير`, `استئناف التذكير`, `▶️ استئناف التذكير`) and `settings:toggle_reminders`.

| Result | DB | Text |
|---|---|---|
| `paused` | `reminders_enabled=false`, `paused_at=now`, `next_morning_at=null`, `next_evening_at=null` | `bot.paused` |
| `already_paused` | — | `bot.already_paused` |
| `resumed` | `reminders_enabled=true`, `paused_at=null`, recalculate `next_*_at` | `bot.resumed` with both 12-hour times |
| `already_active` | — | `bot.already_active` |
| `not_onboarded` | — | `bot.need_start` |

Always a **new** message (`replyPauseResume` → `reply`) carrying a refreshed main reply keyboard (label toggles) when onboarded.

### 3.6 E. `/help`

FACT — `bot.help` (lists 🌅/🌙/🌟/⚙️/⏸/▶️, mentions `🌿 شرح البوت`, and the commands `/start /settings /today /pause /resume /help`) + main reply keyboard, new message. `❓ مساعدة` reply button does the same.

Other: unknown command → `bot.unknown`; unknown text after onboarding → `bot.unknown_text` + main reply keyboard (`handleUnknownText`).

---

## 4. Main Navigation

### 4.1 Main reply keyboard

FACT — `KeyboardFactory::mainReplyKeyboard` (rows reversed by `rtl()` so the first logical item is on the right):

```
[ 🌟 أذكار إضافية ][ 📖 أذكار اليوم ]
[ ⏸ إيقاف التذكير | ▶️ تفعيل التذكير ][ ⚙️ الإعدادات ]
[ ❓ مساعدة ]
[ 🌿 شرح البوت ]
```
`resize_keyboard: true`. Sent with: onboarding completion, `/help`, pause/resume replies, unknown text, `🌿 شرح البوت`.

Labels are matched as exact strings in `handleReplyKeyboard` (both with and without emoji).

| Label | Handler | Opens | DB writes | Reminder log? | User sees next |
|---|---|---|---|---|---|
| `📖 أذكار اليوم` | `handleReplyKeyboard` → `reply(today_prompt, todayMenu)` | Inline today menu | none | No | New message "📖 ماذا تريد أن تقرأ؟" with 🌅 أذكار الصباح / 🌙 أذكار المساء / 🌟 أذكار إضافية |
| `🌟 أذكار إضافية` | `sendAdditionalList($user, null)` | Inline groups keyboard | none | No | New message "🌟 أذكار وفضائل إضافية" + group buttons (§10) |
| `⚙️ الإعدادات` | `sendSettings` | Inline settings | none | No | New message settings summary (or `need_start`) |
| `⏸ إيقاف التذكير` / `▶️ تفعيل التذكير` | `handlePause` / `handleResume` | — | yes (§3.5) | No | New message + refreshed reply keyboard |
| `❓ مساعدة` | `reply(help, mainReplyKeyboard)` | — | none | No | Help text |
| `🌿 شرح البوت` | `OnboardingService::explanation` | — | none | No | `bot.explain` + reply keyboard (or tutorial if state `new`) |

### 4.2 Today menu (inline)

FACT — `KeyboardFactory::todayMenu`: three single-button rows, **hard-coded**:
`🌅 أذكار الصباح` → `today:morning`; `🌙 أذكار المساء` → `today:evening`; `🌟 أذكار إضافية` → `add:list`.

---

## 5. Adhkar Content Architecture

### 5.1 Pipeline (actual)

```
Research / editorial (human-only, never parsed)
  adhkar-research.md, adhkar_additional.md, ar_hisnulmuslim.pdf
  resources/adhkar/{morning,evening,additional}.md   (== "app adhkar/*.md")
        │  manual transcription (no parser exists)
        ▼
database/data/adhkar/{morning,evening,additional}.php   ← PHP arrays, one array per item,
        │                                                   incl. 'collections' => [collection keys]
        ▼
ContentSyncService (php artisan adhkar:sync [--if-changed]; also docker/entrypoint.sh on every start)
  ├─ collectionDefinitions(): 5 hard-coded collections → updateOrCreate by key
  └─ per item: upsert by `key`, sha256 of canonical fields → source_hash/content_version,
               pivot sync (order = item 'order')
        ▼
DB: adhkar_collections, adhkar_items, adhkar_collection_items
        ▼
AdhkarContentService: collectionKey(), itemsForPhase(), fullOnlyItems(), renderCard(),
                      formatItem(), offlineMessages(), snapshotVersions()
AdhkarPdfService: build() → storage/app/pdf/*.pdf (mPDF + resources/views/pdf/wird.blade.php)
        ▼
TelegramUpdateProcessor: presentReading() / additional handlers / handleOfflineSave()
        ▼
Telegram (HTML text cards, inline keyboards, PDF document)
```

FACT: there is **no Markdown parser**; `resources/adhkar/*.md` is referenced only in PHP docblocks (`database/data/adhkar/*.php` headers) and docs. `database/seeders/DatabaseSeeder.php` does **not** seed adhkar (it creates one factory user; see §22).

### 5.2 Item shape (data file → `adhkar_items` columns)

FACT — every item array in `database/data/adhkar/*.php` has these keys (and `AdhkarItem::$fillable` matches):

| Key | Meaning | Rendered in Telegram? | Rendered in PDF? |
|---|---|---|---|
| `key` | Stable identifier, e.g. `morning.01`, `morning.salat_nabi`, `evening.08`, `additional.03` | No | No |
| `category` | `morning` / `evening` / `additional` (cast to `App\Enums\AdhkarCategory`) | No | No |
| `title` | Card title | Yes (bold) | Yes |
| `arabic_text` | Dhikr text (Unicode Arabic with tashkeel, `\n\n` separators) | Yes (HTML-escaped) | Yes (`nl2br`) |
| `repetitions_count` | Integer or null | **No** | No |
| `repetitions_label` | Display string, e.g. `3 مرات` | Yes "🔁 التكرار: …" | Yes |
| `timing` | e.g. `إذا أصبح`, `دُبُر كل صلاة` | **No** | No |
| `virtue` | Virtue text | Yes, blockquote "💡 الفضل: …" (`expandable` if > 200 chars) | Yes |
| `source_text` | Hadith wording | **No** | No |
| `source_name` | Source/takhrij line | Yes "<i>📚 …</i>" (appends ` · {hadith_number}` if not already contained) | Yes |
| `hadith_number` | String | Yes (appended to source) | No (only via source_name) |
| `hadith_grade` | Grade text | Yes "✅ …" | Yes (after source) |
| `narrator` | Narrator | Only in additional item detail view (`detailed: true`) | No |
| `guidance_note` | "هدي النبي ﷺ"-type note | Only in additional item detail view | No |
| `notes` | Editorial notes | **No** | No |
| `order` | Integer; becomes pivot `order` in **every** collection the item belongs to | Ordering only | Ordering only |
| `active` | Optional (default true); `additional.11` is `false` | Filters | Filters |
| `collections` | List of collection keys; removed before insert and used for the pivot | — | — |

Rendering: `AdhkarContentService::formatItem` and `resources/views/pdf/wird.blade.php`.

### 5.3 Collections / identifiers

FACT — `ContentSyncService::collectionDefinitions()` (hard-coded):

```php
['key' => 'morning_short', 'category' => 'morning', 'variant' => 'short', 'title' => 'أذكار الصباح — المختصر', 'order' => 1],
['key' => 'morning_full',  'category' => 'morning', 'variant' => 'full',  'title' => 'أذكار الصباح — الكامل',  'order' => 2],
['key' => 'evening_short', 'category' => 'evening', 'variant' => 'short', 'title' => 'أذكار المساء — المختصر', 'order' => 3],
['key' => 'evening_full',  'category' => 'evening', 'variant' => 'full',  'title' => 'أذكار المساء — الكامل',  'order' => 4],
['key' => 'additional',    'category' => 'additional', 'variant' => null, 'title' => 'أذكار وفضائل إضافية', 'order' => 5],
```

- `adhkar_collections.title`, `description`, `order` are **not used by any live UI** (FACT; `title` only appears in the unused `buildMessages()`). Headers/footers are derived from the key string (§20).
- Enums: `AdhkarCategory {morning, evening, additional}`, `AdhkarVariant {short, full}`, `AdhkarMode {short, full}`, `ReminderType {morning, evening}` (`app/Enums/*`).
- Data file list is hard-coded: `ContentSyncService::dataFiles()` → `['morning', 'evening', 'additional']`.

### 5.4 Other aspects

| Aspect | Finding |
|---|---|
| Stable keys | `adhkar_items.key` unique; sync upserts by key (FACT) |
| Ordering | Pivot `adhkar_collection_items.order` = item `order`; `AdhkarCollection::items()` uses `orderByPivot('order')` (FACT). Same order value in short and full. |
| Repetition | Display only via `repetitions_label`; no counter UI (FACT) |
| Arabic storage | `text` column `arabic_text`; MySQL connection charset `utf8mb4` (FACT, `config/database.php`) |
| Source/virtue/guidance | Separate nullable columns (§5.2) |
| Short/full | Collection membership in data files (§7) |
| Versioning | `content_version` (int, +1 when `source_hash` changes) and `source_hash` (sha256) per item; per-reading snapshot on `reminder_logs` (§16) |
| Caching | None for content; every card queries the DB (§19) |

---

## 6. Current Collections

Counts are FACT from the data files (`collections` arrays) and match `isComplete()` logic.

### 6.1 Morning — category `morning`, items `database/data/adhkar/morning.php`

| | `morning_short` | `morning_full` |
|---|---|---|
| User-facing name | "🌅 أذكار الصباح" + "🌿 مختصر" (card header) | "🌅 أذكار الصباح" + "🌿 كامل" |
| Items | **9** | **20** (all 20 morning items) |

Order (pivot order → key → title):
1 `morning.01` آية الكرسي · 2 `morning.02` المعوّذات · 3 `morning.salat_nabi` الصلاة على النبي ﷺ — 10 مرات · 4 `morning.03` سيد الاستغفار · 5 `morning.04` اللهم ما أصبح بي من نعمة · 6 `morning.05` بسم الله الذي لا يضر · 7 `morning.06` رضيت بالله رباً · 8 `morning.07` لا إله إلا الله وحده (10) · 9 `morning.08` سبحان الله وبحمده: عدد خلقه — **end of short** — 10 `morning.09` … 20 `morning.19` اللهم إني أسألك علماً نافعاً.

### 6.2 Evening — category `evening`, items `database/data/adhkar/evening.php`

| | `evening_short` | `evening_full` |
|---|---|---|
| Items | **9** | **19** |

Order: 1 `evening.01` · 2 `evening.02` · 3 `evening.salat_nabi` · 4 `evening.03` · 5 `evening.04` · 6 `evening.05` · 7 `evening.06` · 8 `evening.07` · 9 `evening.08` أعوذ بكلمات الله التامات — **end of short** — 10 `evening.09` … 19 `evening.18`. Docblock: Markdown full item 19 duplicates item 8, stored once as `evening.08` (FACT, file header).

### 6.3 Additional — category `additional`, collection `additional`, items `database/data/adhkar/additional.php`

- 11 items, **10 active** (`additional.11` "صلى الله عليه وسلم" has `'active' => false`). No short/full variant (`variant = null`).
- User-facing name: "🌟 أذكار وفضائل إضافية" (`bot.additional_intro`).
- Order 1–10 by `order`. Grouping in §10.

### 6.4 Summary table

| Internal key | Name in UI | Items (active) | Where defined | Where selected | Where rendered |
|---|---|---|---|---|---|
| `morning_short` | أذكار الصباح · مختصر | 9 | `ContentSyncService::collectionDefinitions`, `morning.php` | `rt:m:s`, `reminder:start:{id}:s`, claim default (`users.mode`) | `presentReading`, `renderCard`, PDF |
| `morning_full` | أذكار الصباح · كامل | 20 | same | `rt:m:a`, `reminder:start:{id}:a`; `f` phase uses full−short | same |
| `evening_short` | أذكار المساء · مختصر | 9 | same, `evening.php` | `rt:e:s`, `reminder:start…:s` | same |
| `evening_full` | أذكار المساء · كامل | 19 | same | `rt:e:a`, `reminder:start…:a` | same |
| `additional` | أذكار وفضائل إضافية | 10 | same, `additional.php`, groups in `config/adhkar.php` | `add:*` callbacks (queries by `category`, not by the collection) | `formatItem(detailed: true)` single item |

No other category or collection exists. No bedtime/waking content exists anywhere in data, Markdown, or research files (FACT — grep for النوم/الاستيقاظ/استيقظ/bedtime/wake finds only a mention in `adhkar-research.md` §"Duplicates Merged" that Ayat al-Kursi and the Mu'awwidhat also appear in Hisn al-Muslim's "عند النوم" chapter and were not duplicated).

---

## 7. Short/Full Logic

| Question | Answer |
|---|---|
| Where is the mode stored? | `users.mode` (string 16, default `short`, cast `AdhkarMode`) **exists**, but no current UI writes it (FACT: grep shows no assignment outside factory/defaults). For reminders, the mode in effect is stored as `reminder_logs.collection_key`. For `/today`, the mode lives only in callback data (`rt:{m\|e}:{s\|a\|f}:{i}`). |
| Per user? | The per-user column is vestigial (INFERENCE: users who chose "full" before commit `8ac1ce7` may still have `mode='full'`). The effective choice is **per reading**. |
| How is the subset selected? | Membership: each item's `collections` array in the data file lists `*_short` and/or `*_full`. FACT. |
| Short list manually configured? | Yes, hand-written in the data files. |
| Does full mean all items? | For morning/evening currently yes (every morning/evening item is in its `_full`). Code-wise "full" is simply the `_full` collection; nothing enforces "all". |
| Ordering preserved? | Yes, pivot order. `fullOnlyItems()` = full collection minus short keys, in full order. |
| Used by `/today`? | No stored mode. User picks 🌿 مختصر / 📖 كامل each time (`KeyboardFactory::modeChoice`). |
| Used by scheduled reminders? | At claim time `collection_key = collectionKey(users.mode ?? Short, type)` (`ReminderDispatchService::claim`). At Start, the nudge button's phase (`s`/`a`) **overwrites** `collection_key` (`claimReading`). So the reading follows the button pressed. |
| Reusable by another collection? | Only by string convention: `collectionKey()` builds `"{ReminderType}_{short\|full}"`, `fullKey()` does `str_replace('_short', '_full', …)`. The entry points (`rt:` periods `m/e`, `ReminderType`) are hard-coded to morning/evening. See §20. |

Relevant code:

```php
// app/Services/AdhkarContentService.php
public function collectionKey(AdhkarMode $mode, ReminderType $type): string
{
    $variant = $mode === AdhkarMode::Full ? 'full' : 'short';
    return $type->value.'_'.$variant;
}
public function itemsForPhase(string $baseKey, string $phase): Collection
{
    if ($phase === 'f') { return $this->fullOnlyItems($baseKey); }
    $key = $phase === 'a' ? $this->fullKey($baseKey) : $baseKey;
    return $this->collectionItems($key);
}
public function fullKey(string $collectionKey): string
{
    return str_replace('_short', '_full', $collectionKey);
}
```

Phases (FACT): `s` = short collection; `a` = full collection from item 1; `f` = "تتمة" — only full-only items, reached from the last short card via `أكمل الورد الكامل`.

---

## 8. Reading Engine

### 8.1 How a session starts

| Origin | Callback | Scope | Base key |
|---|---|---|---|
| Today menu → mode choice | `rt:{m\|e}:{s\|a}:0` | `today` | `s` → `{type}_short`; `a` → `{type}_full` (`handleTodayReadingNav`) |
| Reminder nudge | `reminder:start:{logId}:{s\|a}` → after claim → `deliverScheduledReading` | `log` | `reminder_logs.collection_key`; phase `a` if key contains `_full`, else `s` |
| Subsequent nav | `rd:{logId}:{s\|f\|a}:{index}` / `rt:{m\|e}:{s\|f\|a}:{index}` | | |

### 8.2 Mechanics (FACT, `presentReading`, `readingActions`, `readingCallback`)

- **Current item**: `index` parsed from callback data. Items are re-queried each time via `itemsForPhase($baseKey, $phase)`.
- **Progress**: `"الذكر {index+1} من {total}"` where `total` = count of the current phase list (9 / 20 / 19, or 11 / 10 for the `f` continuation).
- **Card text** (`renderCard`): header (`🌅 أذكار الصباح` or `🌙 أذكار المساء` + `\n🌿 مختصر|كامل`), blank, progress line, blank, `formatItem(..., detailed: false)`; footer only on the last card ("🌿 انتهت أذكار الصباح\n\nبارك الله في ذكرك" / المساء).
  - Header key: `s` → base key; `a`/`f` → `fullKey(base)`, so the continuation is labelled "كامل".
- **Buttons** (`KeyboardFactory::readingNav`, nav row intentionally **not** RTL-reversed: `السابق` left, `التالي` right):
  - `السابق` if `index > 0`; on `f` index 0 it jumps to the last short card.
  - `التالي` if not last.
  - `📥 أرسل الورد كاملًا` always (`sv:…`).
  - `أكمل الورد الكامل` on the last `s` card when full-only items exist → `…:f:0`.
  - `✅ تم` → `reminder:done:{logId}` on the last card, **only** in `log` scope (both on the last `s` card and the last `f`/`a` card).
- **State storage**: callback data only. No session table, no DB cursor. FACT (`docs/bot-flows.md` also states this).
- **Collection identification**: `log` scope → DB `collection_key`; `today` scope → period letter in callback.
- **Mode identification**: phase letter in callback (+ `_full` in `collection_key`).
- **Display**: every navigation edits the same message (`show` with `messageId`).
- **Next pressed repeatedly**: each press is a separate Telegram update, re-rendering the requested index. An out-of-range index → new message `bot.content_missing`. Pressing a button whose card is already displayed → "message is not modified" swallowed. FACT.
- **Telegram retry**: webhook redelivery has the same `update_id` → unique constraint → 200 with no reprocessing (`TelegramWebhookController`). INFERENCE: a queue-level retry of `ProcessTelegramUpdateJob` (tries 3) after a partial failure would re-run the handler and could re-send messages.
- **Persistence**: none for position. `rd:` navigation checks only ownership and `reading_started_at`; it does **not** re-check same-day validity or status. FACT.
- **Errors**: if `show()` throws, `recordReadingFailure` logs, writes `error_message` on the log (if any) and sends `bot.reading_failed` + `📖 أذكار اليوم` shortcut.

### 8.3 `📥 أرسل الورد كاملًا` — what it actually does

FACT — `handleOfflineSave` (`sv:l:{logId}:{phase}` or `sv:t:{m|e}:{phase}`):

1. Resolves the same item list as the current phase (`itemsForPhase`). For `log` scope it requires `reading_started_at`; no same-day check.
2. Builds a **PDF** with `AdhkarPdfService::build($headerKey, $items, $phase === 'f')`:
   - A5, RTL, Amiri font, file `storage/app/pdf/{key}[-extra]-{hash16}.pdf`; the hash covers key, phase, the Blade template file, the service file, and every `key@content_version`. Reused if the file exists.
   - Title e.g. "أذكار الصباح — مختصر", or "… — تتمة الورد الكامل" for `f`.
3. `sendChatAction(upload_document)`, then `sendDocument` with caption = title. A Telegram `file_id` is cached forever in the cache store (`adhkar:pdf_file_id:{hash}`) and reused; if that fails, it re-uploads.
4. **Fallback** if PDF generation/sending throws: sends `bot.pdf_failed` "تعذّر تجهيز ملف PDF، فأرسلت لك الورد نصًا." then the list as plain `sendMessage` calls (`offlineMessages`: header + `bot.offline_note`, items packed up to 3,500 chars per message, progress line per item), **no keyboards attached**. If that also fails → `bot.reading_failed`.
5. Nothing is persisted in the DB; it does not create or modify a reminder log or a reading session. The reading card stays as it was (no edit).

The button label and `bot.offline_note` text ("هذا الورد كاملًا في المحادثة…") describe text delivery; the primary path is now a PDF document (see §14.2).

---

## 9. /today

FACT — trace:

1. `/today` (command) or `📖 أذكار اليوم` (reply button) → **new** message `bot.today_prompt` "📖 ماذا تريد أن تقرأ؟" + `todayMenu` (🌅 أذكار الصباح `today:morning`, 🌙 أذكار المساء `today:evening`, 🌟 أذكار إضافية `add:list`). `today:menu` callback (from returning-start, settings `🔙 رجوع`, `add:back`, error shortcuts) shows the same menu by **editing**.
2. `today:morning|evening` → edit to `AdhkarContentService::modeChoiceText` = `bot.choose_mode`: "🌅 أذكار الصباح\n\nأي ورد تريد قراءته الآن؟\n\n🌿 مختصر: تقريبًا 5 دقائق\n📖 كامل: تقريبًا 10–15 دقيقة" with `[📖 كامل][🌿 مختصر]` (RTL; مختصر on the right) → `rt:{m|e}:{s|a}:0`, and `🔙 رجوع` → `today:menu`.
3. `rt:…` → reading engine in `today` scope (§8).

| Question | Answer |
|---|---|
| Choose morning/evening? | Yes, explicitly. There is no time-of-day auto-selection. |
| Mode selected? | Yes, every time. |
| Uses user settings? | No (does not read `users.mode`, timezone, or times). |
| Reminder log created? | No (asserted in `tests/Feature/ReminderReadingTest::test_manual_today_does_not_create_a_log_or_done_button`). |
| Completion tracked? | No. |
| Done appears? | No (`done` action only when `scope === 'log'`). |
| Implementation | `handleCommand`, `handleReplyKeyboard`, `routeCallback` (`today:*`), `handleTodayReadingNav`, `presentReading`, `KeyboardFactory::todayMenu/modeChoice` |

---

## 10. Additional Adhkar

### 10.1 Definition

- Items: `database/data/adhkar/additional.php`, keys `additional.01`–`additional.11`, `category='additional'`, `collections=['additional']`. `additional.11` inactive. File header: "The ⚠️ … section is editorial guidance and is intentionally not imported."
- Groups: `config/adhkar.php` → `additional_groups` (comment: "Navigation only. Keys already exist in additional.php; this does not add a religious classification."). **Configuration-driven** by item key.

### 10.2 Current grouping exactly as implemented

| Group key | Button label | Items (in this order) |
|---|---|---|
| `short` | أذكار قصيرة | `additional.01`, `.02`, `.04`, `.05`, `.06`, `.07`, `.08` |
| `prayer` | أذكار بعد الصلاة | `additional.03` |
| `salawat` | الصلاة على النبي ﷺ | `additional.09`, `.10` |
| `all` (synthetic, not in config) | 📚 جميع الأذكار | all active `category=additional` items ordered by `order` (10 items) |

Keyboard (`KeyboardFactory::additionalGroups`): one row per config group (`add:group:{key}`), then `📚 جميع الأذكار` (`add:group:all`), then `🔙 رجوع` (`add:back` → today menu).

### 10.3 Flow

| Step | Callback | Behaviour |
|---|---|---|
| Open | reply `🌟 أذكار إضافية` (new msg) or `add:list` (edit) | text "🌟 أذكار وفضائل إضافية" + groups |
| Group | `add:group:{key}` | `additionalItems($key)`: query `category=Additional, active=true`; for config groups `whereIn('key', $keys)` then sort by position in config; unknown key → toast `bot.item_not_found`. Edit to "🌟 أذكار وفضائل إضافية\n\n<b>{label}</b>" + one button per item (button text = item `title`) → `add:item:{id}:{groupKey}`, `🔙 رجوع` → `add:list` |
| Item | `add:item:{id}:{groupKey}` | Loads item by **DB id**, requires `category=Additional` and active. Edit to `formatItem($item, $item->order, detailed: true)` (adds narrator 🗣️ and guidance 📌). `🔙 رجوع` → `add:group:{groupKey}` |

| Question | Answer |
|---|---|
| Same reading engine as morning/evening? | **No.** Single-item view; no Previous/Next, no progress line, no PDF button. |
| Short/full? | No. |
| Reminder logs? | No (test `test_additional_menu_is_grouped_and_never_scheduled`). |
| Manual-only? | Yes. |
| Uses `adhkar_collections`/pivot? | Not for display; the `additional` collection exists and is linked, but handlers query `adhkar_items.category` directly. |

---

## 11. Reminder Architecture

### 11.1 Complete flow

```
UserSettingsService::recalculateNextReminders  (onboarding finish, timezone/time change, resume)
   └─ next_morning_at = ReminderScheduleCalculator::calculateNext(tz, morning_time)
      next_evening_at = … evening_time                (UTC; today if still ahead, else tomorrow)
        ▼
Scheduler tick (every minute; §1/§2.2)
        ▼
ReminderDispatchService::claimDueLogIds(?deadline)
   SELECT id, next_morning_at, next_evening_at FROM users
     WHERE reminders_enabled AND (next_morning_at <= now OR next_evening_at <= now)
     chunkById(100)
   per user: fresh() reload → isStillDue(Morning) → claim(Morning) → fresh() → isStillDue(Evening) → claim(Evening)
        ▼
claim(): DB::transaction {
   INSERT reminder_logs (user_id, reminder_type, collection_key = "{type}_{users.mode}",
                         scheduled_local_date = dueAt in user tz, scheduled_for = dueAt, status=pending)
   users.next_{type}_at = calculateNext(tz, localTime, after: dueAt)
}
   unique violation → return null (no job)   → after commit: SendAdhkarReminderJob::dispatch(logId)
        ▼
queue (database → worker; sync on Vercel)
        ▼
SendAdhkarReminderJob::handle
   skip if sent/completed/skipped, or failed with nudge_unconfirmed/blocked_by_user
   collection must exist & be active, else failed 'missing_user_or_collection'
   reserveOutboundAttempt(): lockForUpdate; if pending with last_dispatch_attempt_at → failed 'nudge_unconfirmed', stop
                             else set last_dispatch_attempt_at=now, dispatch_attempts++
   sendMessage(nudgeText, reminderNudge keyboard)
   → status=sent, sent_at, telegram_message_id=[message_id], error_message=null
   TelegramApiException → markFailed (blocked → disable user reminders) ; rethrow unless blocked
        ▼
Telegram nudge:
   "🌅 حان وقت أذكار الصباح\n\nاختر الورد الذي يناسبك الآن:\n🌿 مختصر: تقريبًا 5 دقائق\n📖 كامل: تقريبًا 10–15 دقيقة"
   [📖 كامل reminder:start:{id}:a][🌿 مختصر reminder:start:{id}:s]
   [⚙️ تعديل الوقت settings:{morning|evening}_time]
```

### 11.2 Components

| Concern | Implementation (FACT) |
|---|---|
| Scheduler frequency | `routes/console.php`: `adhkar:dispatch-due-reminders` everyMinute withoutOverlapping; `adhkar:recover-stale` everyFiveMinutes withoutOverlapping. `CronTickService`: per HTTP tick, lock `adhkar:cron-tick` 120 s, claim budget `ADHKAR_CRON_CLAIM_BUDGET_SECONDS` (25), recovery when ≥5 min since marker `adhkar:last_stale_recovery_at`. |
| Dispatch command | `DispatchDueRemindersCommand` → `claimDue()`, writes `adhkar:last_scheduler_tick` cache key. |
| Queue connection | `QUEUE_CONNECTION=database` (`jobs` table, `retry_after` 90 s); `sync` when `VERCEL` is set. |
| Jobs | `SendAdhkarReminderJob` (tries 3, backoff [5,30,120], `ShouldBeUnique` by log id for 180 s); `ProcessTelegramUpdateJob` (tries 3, unique 120 s). |
| Worker command | `php artisan queue:work --sleep=1 --tries=3 --backoff=5,30,120 --max-time=3600` (Supervisor, systemd, docker `queue` role). CronTickService also runs `queue:work --stop-when-empty --max-time=20` when not sync. |
| Supervisor | `deploy/supervisor-azkar-worker.conf`, 1 process, user `www-data`, log `storage/logs/worker.log`. |
| Statuses | `ReminderLogStatus`: `pending`, `sent`, `failed`, `completed`, `skipped`. `skipped` is never assigned anywhere (FACT). |
| Claim/locking | Transaction + unique index; `lockForUpdate` in `reserveOutboundAttempt` and `claimReading`; cache locks for cron tick and stale recovery. |
| Duplicate prevention | Unique `reminder_logs_unique_day (user_id, reminder_type, scheduled_local_date)`; `ShouldBeUnique`; status checks; webhook `update_id` unique. |
| Retry | Telegram API errors (non-blocked): log set `failed` with message, job rethrows → queue retry re-sends (status is `failed`, not pending, so reservation passes). Non-Telegram exceptions after reservation (e.g. HTTP timeout) leave `pending` + `last_dispatch_attempt_at` → next attempt marks `nudge_unconfirmed` and does **not** resend. `failed()` marks a still-pending log failed. INFERENCE: with the `sync` queue, `tries/backoff` do not apply; the exception is caught by `ReminderDispatchService::dispatchJob` and logged. |
| Stale recovery | `ReminderDispatchService::recoverStalePending`: pending logs older than `reminder_stale_minutes` (3): never-attempted → `dispatch_attempts++` and re-dispatch until `ADHKAR_REMINDER_DISPATCH_MAX_ATTEMPTS` (5) → `dispatch_exhausted_retries`; attempted → `failed`/`nudge_unconfirmed`. `StaleRecoveryService::recoverTelegramUpdates` re-dispatches `pending`/`processing` updates older than 5 min (max 5 attempts). `failed` updates are not recovered. |
| Start button | `reminder:start:{id}:{s\|a}` → `handleReminderStart`: owner check → `isCurrentLocalDay` (log `scheduled_local_date` == today in user's **current** tz; else `bot.reminder_expired` + today shortcut, new message) → `claimReading` (lock; already started/snapshotted/completed → toast "بدأت هذه الأذكار من قبل."; status ≠ sent → toast error; sets `collection_key` from phase; collection must be active; writes `reading_started_at`, `content_version_map`, `content_snapshot_hash`) → `deliverScheduledReading` edits the nudge into card 1. |
| Done button | `reminder:done:{id}` → `handleCompletion`: owner; already completed → toast "تم تسجيلها من قبل"; `completionProblem`: same local day, `reminder_type ∈ {Morning, Evening}`, started with snapshot, status `sent` → set `completed`, `completed_at`; toast `bot.completed_morning/evening`. Message is not edited; the button remains. |
| Same-day validation | Start and Done only (not navigation, not PDF, not the nudge send). |
| `collection_key` | Set at claim from `users.mode`; overwritten at Start by phase. |
| Content snapshot | `snapshotVersions(items)` → `[{order, key, content_version}]` sorted by (order, key), sha256 of JSON. Stored at Start. Not used afterwards except as a "started" flag in `claimReading`/`completionProblem`. Cards render **live** DB content. |
| Telegram message id | `telegram_message_id` JSON `[message_id]` of the nudge; not read anywhere afterwards (FACT, grep). |
| Blocked users | `TelegramApiException::isBlockedByUser()` (HTTP 403, or text contains "blocked", "user is deactivated", "chat not found") → log `blocked_by_user`; `users.reminders_enabled=false`, both `next_*_at=null`. |
| Failure handling | Errors logged to `telegram` channel (daily file `storage/logs/telegram.log`); token redacted via `TelegramClient::redact`. |
| Health | `adhkar:health` prints last tick, failed jobs, pending logs, exhausted logs, stuck updates, active items. |

### 11.3 Late/catch-up behaviour (FACT from code path)

`claim()` uses the stored past `dueAt` both for `scheduled_local_date` and as the `after` value for the next calculation. If a user's `next_*_at` is several days in the past (e.g. scheduler downtime), each tick creates one log for the oldest missed day and advances by one day, so missed days are nudged one per tick until caught up. Those nudges' Start would answer "هذا التذكير انتهى" because the date is not today. The send job itself has no lateness check.

Note: this describes committed `HEAD`. During the audit an **uncommitted** working-tree change appeared (not made by this audit). It changes `claim()` to calculate the next run from `max($dueAt, now())` and adds `test_long_outage_sends_one_late_reminder_instead_of_one_per_missed_day` to `tests/Feature/ReminderDispatchTest.php`. If that change is committed, the catch-up behaviour above no longer applies.

---

### 11.4 Reminder collection selection

| Question | Answer (FACT) |
|---|---|
| How does a scheduled reminder know which collection? | `ReminderDispatchService::claim` writes `collection_key = AdhkarContentService::collectionKey($user->mode ?? AdhkarMode::Short, $type)`, i.e. `morning_short` / `evening_short` (or `_full` for legacy `mode=full`). The job uses it only to check the collection exists and to pick nudge text (`nudgeText` → `str_starts_with($key, 'evening')`). At Start, `claimReading` sets `collection_key = collectionKey(phase-mode, $locked->reminder_type)`. |
| Uses user's current mode? | At claim, `users.mode` (vestigial, effectively `short`). At Start, the button pressed. |
| Stores a collection key? | Yes, `reminder_logs.collection_key` (string 255). |
| Generic or hard-coded? | The key is **derived** from `ReminderType` (`morning`/`evening`) + variant; not selected from data. |
| Multiple collection types schedulable? | Only the two `ReminderType` cases. Per-type state lives in dedicated `users` columns (`morning_time`, `evening_time`, `next_morning_at`, `next_evening_at`), and `claimDueLogIds` runs exactly two explicit passes. |
| Coupled to morning/evening? | Yes — see §20 for the full list. |

Code path: `ReminderDispatchService::claimDueLogIds` → `isStillDue(…, ReminderType::Morning|Evening)` → `claim()` (ternaries on `ReminderType::Morning` for `next_*_at` and `*_time`) → `SendAdhkarReminderJob::handle` → `KeyboardFactory::reminderNudge($id, $type->value)` (`'evening'` vs else) → `TelegramUpdateProcessor::claimReading` → `completionProblem` (`in_array($log->reminder_type, [Morning, Evening])`) → `handleCompletion` (Evening vs else text).

---

## 12. Database

Migrations: `database/migrations/*`. Models: `app/Models/*`.

### 12.1 `users` (`0001_01_01_000000_create_users_table.php` + `2026_09_22_000001_add_ux_reminder_fields.php`)

| Column | Type / null / default | Notes |
|---|---|---|
| `id` | bigint PK | |
| `telegram_id` | unsigned bigint **unique** | |
| `chat_id` | unsigned bigint **unique** | |
| `first_name`, `username` | string null | |
| `language_code` | string(10) null | |
| `timezone` | string(64) null | IANA id |
| `timezone_label` | string null | display label (added 2026-09-22) |
| `morning_time` ⚑ | time null | **morning-specific** |
| `evening_time` ⚑ | time null | **evening-specific** |
| `mode` ⚑ | string(16) default `short` | cast `AdhkarMode`; not written by current UI |
| `reminders_enabled` | bool default false | single switch for both reminders |
| `onboarding_state` | string(32) default `new` | cast `OnboardingState` |
| `awaiting_input` | string(64) null | free-text state machine (e.g. `settings:morning_time_custom`, `onboarding:timezone_region:22`) |
| `next_morning_at` ⚑ | timestamp null | **morning-specific** |
| `next_evening_at` ⚑ | timestamp null | **evening-specific** |
| `paused_at`, `last_interaction_at` | timestamp null | |
| timestamps | | |
| Indexes | `users_reminders_morning_idx (reminders_enabled, next_morning_at)` ⚑, `users_reminders_evening_idx (reminders_enabled, next_evening_at)` ⚑ | |

Relation: `hasMany ReminderLog`. Same migration creates Laravel `sessions` table (unused by the bot logic).

### 12.2 `adhkar_collections` (`2026_01_01_000003`)

`id`, `key` string **unique** ⚑(collection), `category` string(32) ⚑ (cast `AdhkarCategory`), `variant` string(16) null ⚑ (cast `AdhkarVariant`), `title` string, `description` text null, `active` bool default true, `order` uint default 0, timestamps. Relation: `belongsToMany AdhkarItem` via pivot, `orderByPivot('order')`.

### 12.3 `adhkar_items` (`2026_01_01_000004`)

`id`, `key` string **unique**, `category` string(32) ⚑ (cast `AdhkarCategory`), `title` string null, `arabic_text` text, `repetitions_count` uint null, `repetitions_label` string null, `timing` string null, `virtue` text null, `source_text` text null, `source_name` string null, `hadith_number` string null, `hadith_grade` string null, `narrator` string null, `guidance_note` text null, `notes` text null, `order` uint default 0, `active` bool default true, `content_version` uint default 1, `source_hash` char(64) **not null**, timestamps. No secondary indexes besides `key`.

### 12.4 `adhkar_collection_items` (`2026_01_01_000005`)

`id`, `collection_id` FK → `adhkar_collections` cascadeOnDelete, `item_id` FK → `adhkar_items` cascadeOnDelete, `order` uint default 0, **unique (`collection_id`, `item_id`)**. No timestamps.

### 12.5 `reminder_logs` (`2026_01_01_000006` + `2026_09_22_000001`)

| Column | Notes |
|---|---|
| `user_id` | FK users cascadeOnDelete |
| `reminder_type` ⚑ | string(16), cast `ReminderType` (morning/evening) |
| `collection_key` ⚑ | string (255), not null |
| `scheduled_local_date` | date |
| `scheduled_for` | timestamp |
| `status` | string(16) default `pending` — state field |
| `telegram_message_id` | json null |
| `sent_at`, `reading_started_at`, `completed_at`, `last_dispatch_attempt_at` | timestamps null |
| `error_message` | text null (values used as codes: `nudge_unconfirmed`, `blocked_by_user`, `dispatch_exhausted_retries`, `missing_user_or_collection`, or exception text) |
| `retry_count`, `dispatch_attempts` | uint default 0 |
| `content_version_map` | json null |
| `content_snapshot_hash` | char(64) null |
| Indexes | **unique `reminder_logs_unique_day (user_id, reminder_type, scheduled_local_date)`** ⚑, `status`, `scheduled_for` |

### 12.6 `telegram_updates` (`2026_01_01_000007`)

`update_id` **unique**, `chat_id` null, `payload` json, `status` string(16) (`pending/processing/processed/failed`), `attempts`, `error_message`, `received_at`, `processed_at`; index `(status, received_at)`.

### 12.7 Framework tables

`cache`, `cache_locks` (`0001_01_01_000001`, not opened but standard), `jobs`, `job_batches`, `failed_jobs` (`0001_01_01_000002`), `sessions`.

### 12.8 Tables that do not exist

No reading-session table, no user-settings table, no content-version/release table, no per-collection reminder table. FACT (also stated in `docs/database-design.md`).

### 12.9 Schema reuse observations (FACT)

- ⚑ columns depending on morning/evening: `users.morning_time`, `evening_time`, `next_morning_at`, `next_evening_at` and their two indexes.
- ⚑ columns depending on collection/category/mode/type but stored as **free strings**: `adhkar_items.category`, `adhkar_collections.key/category/variant`, `users.mode`, `reminder_logs.reminder_type/collection_key`. The DB imposes no value lists; the restriction is in PHP enum casts (`AdhkarCategory`, `AdhkarVariant`, `AdhkarMode`, `ReminderType`). INFERENCE: loading a row whose cast column holds a value not in the enum raises a `ValueError` in Eloquent.
- `reminder_type` is limited to 16 characters by the migration.

---

## 13. Callback & State

### 13.1 Full callback inventory (FACT — `KeyboardFactory` + `routeCallback`)

| Pattern | Origin | Handler |
|---|---|---|
| `noop` | (none currently emitted) | answer only |
| `nav:back`, `nav:cancel` | back/cancel rows | `OnboardingService::back/cancel` |
| `today:menu` | returning start, today shortcut, mode choice back | `showTodayMenu` |
| `today:morning`, `today:evening` | today menu | mode choice |
| `onb:begin`, `onb:reset`, `guide:explain` | tutorial / returning start | onboarding |
| `onb:tz:{index}`, `onb:tz:custom` | timezone list (index into `config('adhkar.timezones')`) | `handleTimezoneCallback` |
| `onb:tzr:{countryIndex}:{regionIndex}` | region list | `handleRegionCallback` |
| `onb:time:{morning\|evening}:{HHMM\|custom}` | presets, period choice, suggestion | `handleTimeCallback` |
| `onb:mode:*` | legacy only | `finish` |
| `settings:open\|timezone\|morning_time\|evening_time\|toggle_reminders\|close` (+ legacy `settings:mode*`) | settings menu, nudge "تعديل الوقت" | `handleSettingsCallback` |
| `add:list`, `add:back`, `add:group:{key\|all}`, `add:item:{itemId}:{groupKey}` | additional | `handleAdditionalCallback` |
| `rt:{m\|e}:{s\|f\|a}:{index}` | today reading | `handleTodayReadingNav` |
| `rd:{logId}:{s\|f\|a}:{index}` | reminder reading | `handleScheduledReadingNav` |
| `sv:t:{m\|e}:{phase}`, `sv:l:{logId}:{phase}` | PDF button | `handleOfflineSave` |
| `reminder:start:{logId}:{s\|a}` | nudge | `handleReminderStart` |
| `reminder:done:{logId}` | last reading card (and unused `KeyboardFactory::completion`) | `handleCompletion` |
| anything else | | toast `bot.unknown` |

Conventions: `namespace:action[:args]`, colon-separated, short letters for period (`m`/`e`) and phase (`s`/`f`/`a`). Collection identity is never put in callbacks directly; it is encoded as period letter (today) or log id (reminder). Pagination = integer index. Callback data is untrusted input: indices are cast with `(int)`, periods/phases validated with `in_array`.

### 13.2 State machines

- `users.onboarding_state`: `new → waiting_timezone → waiting_morning_time → waiting_evening_time → completed` (`waiting_mode` legacy). Reset returns to `waiting_timezone`.
- `users.awaiting_input`: free-text sub-state `{onboarding|settings}:{timezone_custom|timezone_region:{i}|morning_time_custom|morning_time_confirm|evening_time_custom|evening_time_confirm}`. The `settings:` vs `onboarding:` prefix drives Cancel visibility and save path.
- `reminder_logs.status` + `reading_started_at` + `content_snapshot_hash` + `completed_at`: `pending → sent → (started) → completed`, or `→ failed`.
- `telegram_updates.status`: `pending → processing → processed | failed`.

### 13.3 Constraints relevant to introducing two more collection keys (description only)

FACT-based constraints:

1. `rt:` and `sv:t:` accept only `m`/`e` and map them to `ReminderType` (`handleTodayReadingNav`, `handleOfflineSave`). `today:` accepts only `today:morning`/`today:evening`.
2. `rd:`/`sv:l:`/`reminder:*` are generic by log id, but the base key comes from `collection_key` and all labelling is derived from string prefixes (`evening…` vs everything else = morning).
3. `add:item` refuses items whose `category` is not `additional`; `add:group:all` lists only `category=additional`.
4. Collection keys must follow `{x}_short` / `{x}_full` for `fullKey()`/`fullOnlyItems()`/phase logic; a key without `_short` makes `fullKey()` a no-op and the `f` continuation empty.
5. Enum casts (`AdhkarCategory`, `ReminderType`, `AdhkarVariant`) reject unknown values.
6. Telegram limits callback data to 64 bytes (external constraint); the longest current pattern (`reminder:start:{id}:s`, `add:item:{id}:salawat`) is well under that.

---

## 14. UX / Arabic

### 14.1 Conventions (FACT)

- **Language**: all UI strings in `lang/ar/bot.php` except: keyboard labels (hard-coded Arabic in `KeyboardFactory`), card headers/footers/progress/labels (hard-coded in `AdhkarContentService`), PDF strings (`AdhkarPdfService`, Blade), additional group labels (`config/adhkar.php`), reply-label matching (`handleReplyKeyboard`).
- **Formatting**: `parse_mode=HTML` on every `sendMessage`/`editMessageText`; DB text escaped with `htmlspecialchars`; title `<b>`, source `<i>`, virtue `<blockquote>`/`<blockquote expandable>`; additional group title `<b>`. User input echoed through `e()` truncated to 40 chars; digits prefixed with U+200E (LRM) for LTR display (`quoteLtr`).
- **Emoji vocabulary**: 🌅 morning, 🌙 evening, 🌟 additional, 📖 today / full, 🌿 short / explain / footer, ⚙️ settings, 🌍 region, ⏸/▶️ pause/resume, ❓ help, 🔙 back, ❌ cancel/error, ✅ done/grade/success, 🔁 repetitions, 💡 virtue, 📚 source / "all", 🗣️ narrator, 📌 guidance, 📥 PDF, 🔄 reset, 🚀 begin, ⏰ custom time, 🔔 reminders status.
- **Card layout**: header (period + mode), progress "الذكر N من M", bold title, "🔁 التكرار: …", text, blockquote "💡 الفضل: …", italic "📚 source · number", "✅ grade". Footer on last card only.
- **Repetition display**: `repetitions_label` verbatim; `repetitions_count` unused.
- **Progress**: text only; no bar, no percentage.
- **RTL ordering**: `KeyboardFactory::rtl()` reverses multi-button rows so the logical first option is on the right. Exception: reading nav row keeps `السابق` left / `التالي` right (explicit comment).
- **Times**: presets shown as 24-hour `05:30`; saved times shown 12-hour `5:30 ص` (`ReminderScheduleCalculator::format12Hour`).
- **Toasts**: `answerCallbackQuery` text for short outcomes (already started, done, not found, errors).
- **Edit vs send**: §3 rule.

### 14.2 Directly observable inconsistencies (no fixes proposed)

1. `📥 أرسل الورد كاملًا` and `bot.offline_note` ("هذا الورد كاملًا في المحادثة… التنقّل بزر التالي يحتاج اتصالًا") describe in-chat text, while the primary behaviour is a PDF document; `offline_note` is shown only in the fallback text path.
2. The continuation phase `f` header says "🌿 كامل" although only the remaining items are shown, while the PDF for the same phase is titled "تتمة الورد الكامل".
3. Toggle label is "▶️ تفعيل التذكير" (singular) on buttons; `bot.help` says "▶️ تفعيل التذكيرات" / "⏸ إيقاف التذكيرات" (plural). Reply matcher still accepts "▶️ استئناف التذكير", which is never shown.
4. Reading buttons `السابق`, `التالي`, `أكمل الورد الكامل` carry no emoji; most other buttons do.
5. `settings:toggle_reminders` replies with a new message; the settings message keeps the old toggle label.
6. Tapping `⚙️ تعديل الوقت` on a nudge edits **the nudge itself** into the time-preset screen, removing the 🌿/📖 Start buttons from that message.
7. Done gives only a toast; the `✅ تم` button stays on the card.
8. Mode-choice prompt wording differs between nudge ("اختر الورد الذي يناسبك الآن") and today ("أي ورد تريد قراءته الآن؟").
9. Additional items render `source_name` and grade, but `source_text`, `notes`, `timing` (present in data) are not displayed anywhere; morning/evening cards do not show `guidance_note`/`narrator` although some items have them (e.g. `morning.03`).
10. `lang/ar/bot.php` key `closed` ("تم") is unused.

---

## 15. Content Provenance

### 15.1 Materials in the repository (FACT)

| File | Role | Used by code? |
|---|---|---|
| `adhkar-research.md` (950 lines) | Research collection for morning/evening: 24 unique adhkar; sources table (Hisn al-Muslim PDF ch. 27 pp. 32–41; "ورد الصباح والمساء" on IslamHouse; Dorar link for Sayyid al-Istighfar; sunnah.com `hisn:92`); per-item classification, text, virtue, Prophetic-practice note, narrator, source, number, grade, links; sections "Variations in Wording", "Scholarly / Source Differences", "Duplicates Merged", "Unverified / Needs Review" (~8 items), summary statistics | No |
| `ar_hisnulmuslim.pdf` (≈4.9 MB) | Primary source PDF referenced by page in the research file | No |
| `adhkar_additional.md` | Research for additional adhkar incl. a "⚠️ أحاديث وفضائل مشهورة تحتاج تنبيه" section (weak/disputed items), a sources table with sunnah.com links, and a QA table | No |
| `resources/adhkar/{morning,evening,additional}.md` | "نسخة البوت" editorial selections (MVP short/full split, notes on exclusions); `additional.md` is a variant of `adhkar_additional.md` **without** the ⚠️ section and sources/QA tables, plus "🧩 ملاحظات تنفيذية للبوت" | No (docblock reference only) |
| `app adhkar/*.md` | Identical copies of `resources/adhkar/*.md` | No |
| `database/data/adhkar/*.php` | Authoritative runtime content | **Yes** (sync input) |

INFERENCE: inside the repository, the PHP data files are authoritative at runtime; the Markdown files under `resources/adhkar/` are the declared editorial source (README: "Editorial sources: `resources/adhkar/*.md` … Structured seed: `database/data/adhkar/*.php`").

### 15.2 How provenance is modelled

- Source: `source_name` (free text, may include several works and grading attributions) + optional `source_text` (hadith wording; populated for additional items only).
- Hadith number: `hadith_number` (single string; where multiple numbers exist only one is stored, e.g. `morning.02` stores `5082` while source names "أبو داود 5082؛ الترمذي 3575").
- Grading: `hadith_grade` (free text, e.g. `صحيح`, `مختلف في الرفع والوقف`, `صحيح (متفق عليه)`).
- Narrator: `narrator`. Guidance: `guidance_note`. Editorial caveats: `notes`.
- No URL/link field, no page-number field, no structured "source book" or "chapter" field, no reviewer/verification status field.

### 15.3 Entries with incomplete provenance (FACT, as stored)

- `morning.salat_nabi`, `evening.salat_nabi`: `source_name`, `hadith_number`, `hadith_grade`, `virtue` all null; `notes` says it was added "بناءً على طلب المستخدم، دون إسناد فضل خاص في ملف البحث الأصلي لهذا العدد تحديدًا". `docs/implementation-checklist.md` keeps an open item: wording/virtue do not match `adhkar-research.md` §24; `resources/adhkar/morning.md` lists the same dhikr under "لم أضع في الـMVP الحالي" while also including it in the short list.
- Items with `source_name` but null `hadith_number` (e.g. `morning.01`, `morning.06`, `morning.07`, `morning.16`, and evening equivalents) and many with null `hadith_grade` (grade embedded in `source_name` text instead).
- `additional.10`: `source_name` "صحيح البخاري (وردت بألفاظ متقاربة في الصحيحين)…", `hadith_number` null.
- `additional.11` (inactive): no source.
- The research file itself flags narrator names as "[غير متحقق من المصدر المعتمد]" and lists items needing review; the data files do not carry those flags.

No independent verification of religious content was performed.

---

## 16. Content Sync / Versioning

### 16.1 `php artisan adhkar:sync` (FACT — `SyncAdhkarContentCommand`, `ContentSyncService`)

Without options → `syncLocked()`: acquires cache lock `adhkar:content-sync` (300 s, waits up to 120 s), runs `sync()`, then writes cache marker `adhkar:content_sync_hash` = sha256 over the three data files **and** `ContentSyncService.php` itself. With `--if-changed` → `syncIfChanged()`: skip if marker equals current source hash **and** `isComplete()`; otherwise lock, re-check, sync, mark. `docker/entrypoint.sh` runs `adhkar:sync --if-changed` on every container start.

`sync()` inside one DB transaction:

1. `syncCollections()`: `updateOrCreate` each of the 5 hard-coded definitions by `key`, forcing `active=true`.
2. For each data file (`morning`, `evening`, `additional`), for each item:
   - remove `collections`; default `active=true`; compute `hash()` over (`arabic_text`, `repetitions_count`, `repetitions_label`, `timing`, `virtue`, `source_text`, `source_name`, `hadith_number`, `hadith_grade`, `narrator`, `guidance_note`, `notes`, `title`). **Not hashed**: `order`, `category`, `active`, collection membership.
   - **New key** → insert with `content_version=1`, `source_hash`.
   - **Hash differs** → `fill($data)` (all fields incl. order/category/active), `source_hash` updated, `content_version += 1`.
   - **Hash same** → only `active` is reconciled; `order`/`category` changes are **not** written to `adhkar_items` in this branch.
   - Pivot: `$item->collections()->sync([collection_id => ['order' => $data['order'] ?? $index]])` — replaces the item's memberships; collection keys not found are silently skipped.
3. Returns `{created, updated, unchanged, deactivated}`.

| Question | Answer |
|---|---|
| Inserts | Yes (new collections/items/pivot rows) |
| Updates | Yes (items with changed hash; collection definitions; pivot order/membership) |
| Deletes | Never deletes `adhkar_items` or `adhkar_collections`. Pivot rows for an item **are** detached when removed from its `collections` list. Items removed from data files remain in DB untouched (still active, still linked). |
| Preserves IDs | Yes (upsert by key) |
| Preserves stable keys | Yes |
| Affects reminder snapshots | No — `reminder_logs.content_version_map/hash` are never rewritten (test `test_send_job_stores_content_version_snapshot_that_survives_later_sync`). Ongoing readings render live content. |
| Can it introduce new collections? | Only by editing `collectionDefinitions()` (PHP) and `dataFiles()` (PHP); and any new `category` must exist in `AdhkarCategory`. |
| Completeness check | `isComplete()` compares active collection count, item key count, active item count, and pivot link count against data files. |

Other "versioning": `AdhkarPdfService::contentHash` (file name + Telegram `file_id` cache key) changes with any `content_version`, template, or service change. No migrations seed content. No other import scripts exist.

---

## 17. Tests

17 test files, 117 test methods (6 PostgreSQL-only, skipped unless `DB_CONNECTION=pgsql`). PHPUnit 11; in-memory SQLite; `QUEUE_CONNECTION=database`; `Http::preventStrayRequests()` + `Http::fake` for Telegram (`tests/TestCase.php`, `phpunit.xml`). All Telegram tests are simulated; no live API.

| Area | Coverage (files) |
|---|---|
| Onboarding / `/start` | `Feature/OnboardingTest` (14): full flow, legacy mode step, time error messages/suggestions, unknown country, unrelated text, America region, back, Arabic digits, cancel only in settings, returning `/start`, explain button, `/start` mid-onboarding, settings timezone back |
| `/today` | `Feature/ReminderReadingTest::test_manual_today_does_not_create_a_log_or_done_button` |
| Settings / pause / resume | `Feature/SettingsPauseResumeTest` (4); keyboard label `ReminderReadingTest::test_main_keyboard_…` |
| Morning/evening content | `Unit/ContentSyncServiceTest` (first 3 short keys, full > short), `Unit/AdhkarContentServiceTest` (chunking of unused `buildMessages`, snapshot stability, nudge vs card wording) |
| Additional | `Feature/AdditionalAndCompletionTest` (4): not in morning, grouped & never scheduled, salawat group, inactive `.11` hidden |
| Navigation | `ReminderReadingTest::test_reading_pages_one_item_and_can_continue_into_the_full_wird`, `…choosing_full_from_the_reminder…`, `…pdf_is_uploaded_once_then_resent_by_file_id` |
| Reminders | `Feature/ReminderDispatchTest` (15), `Feature/SchedulerCronTest` (18), `Feature/ReminderDeliveryAndVersioningTest` (2), `Unit/ReminderScheduleCalculatorTest` (10), `ReminderReadingTest` (start/done/expiry/ownership/failures) |
| Queue | indirectly (jobs dispatched/asserted, `dispatchSync`) |
| Webhook | `Feature/WebhookTest` (5) |
| Duplicate prevention | `ReminderDispatchTest`, `SchedulerCronTest::test_back_to_back_ticks_send_only_once`, `Feature/DatabaseConstraintTest` (3), `PostgresBehaviourTest` |
| Content sync | `Feature/ContentSyncIfChangedTest` (11), `Unit/ContentSyncServiceTest` (2) |
| Stale recovery | `Feature/RecoverStaleUpdatesTest` (1), dispatch/cron tests |
| Deploy | `Feature/SmokeTestCommandTest` (3) |

Types: Unit = 3 files (calculator, content service, sync service — they still use the DB via `RefreshDatabase`). Feature/integration = 14 files exercising the processor directly or via the webhook + `ProcessTelegramUpdateJob::dispatchSync`.

Not covered by any test found (FACT from test inventory): `/help` text; `🌟 أذكار إضافية` from today menu (`add:list` edit path) and `add:back`; `sv:t:` (today PDF) path; text-fallback path when PDF fails; `rt:` previous/next at boundaries and out-of-range index; `rd:` navigation on a past-day log; settings toggle message behaviour; `nav:cancel` from region step; late catch-up nudges after downtime; the exact item counts per collection (only relative assertions); additional item detail rendering; `telegram:poll`; `CronTickService` running `queue:work` with a non-sync queue on real workers; Vercel/`sync`-queue end-to-end.

---

## 18. Deployment

### 18.1 Classic server (`deploy/`)

| File | Content |
|---|---|
| `nginx.conf.example` | 443 TLS (Let's Encrypt paths), root `/var/www/azkar/public`, `fastcgi_pass unix:/run/php/php8.2-fpm.sock`, deny dotfiles |
| `supervisor-azkar-worker.conf` | `php /var/www/azkar/artisan queue:work --sleep=1 --tries=3 --backoff=5,30,120 --max-time=3600`, 1 proc, `www-data` |
| `azkar-worker.service` | systemd equivalent, `Restart=always` |
| `crontab.example` | `* * * * * cd /var/www/azkar && php artisan schedule:run` |
| `production.env.example` | `APP_ENV=production`, `DB_CONNECTION=mysql`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`, Telegram vars, `CRON_SECRET`, `ADHKAR_*` tuning |

Deployment order (`docs/testing-and-deployment.md`): env → `composer install --no-dev -o` → `migrate --force` → `adhkar:sync` → `config:cache && route:cache` → cron → worker → `queue:restart` → `/up` + `adhkar:health` → `telegram:webhook:set` → `adhkar:smoke --live` → real `/start`.

### 18.2 Docker (`Dockerfile`, `docker-compose.yml`, `docker/`)

Roles via `entrypoint.sh`: `web` (migrate, `adhkar:sync --if-changed`, `optimize` in production, php-fpm + nginx), `queue` (worker), `scheduler` (loop `schedule:run` each minute). Compose: `mysql:8.4` + web/queue/scheduler sharing a `storage` volume. `php.ini`: `memory_limit 256M`, opcache `validate_timestamps=0`.

### 18.3 Vercel + Neon path

`entrypoint.sh`: if `VERCEL` set → `QUEUE_CONNECTION=sync`, `LOG_CHANNEL=stderr`; if `VERCEL_ENV=production` → require `pgsql` + `DB_URL|DATABASE_URL`, wait via `adhkar:db-status --wait=60`. Reminders driven by external cron calling `POST /internal/cron` (comment: cron-job.org every minute). `config/database.php` pgsql disables PDO named prepares for Neon PgBouncer. No `vercel.json` in repo; how the Docker image is built/deployed on Vercel is UNKNOWN. External cron configuration is UNKNOWN (not in repo).

### 18.4 Requirements

| Item | Finding |
|---|---|
| PHP extensions | From Dockerfile: `pdo_mysql`, `pdo_pgsql`, `bcmath`, `opcache`, `gd` (+ base image `mbstring`, etc.). mPDF requires `gd` and `mbstring` (`composer.lock`). Classic `deploy/` files do not list extensions. |
| Queue | `jobs`/`failed_jobs` tables; a running worker (classic/Docker), or `sync` (Vercel) |
| MySQL | utf8mb4 / `utf8mb4_unicode_ci` (config default); compose uses 8.4; README says MySQL 8 |
| Writable dirs | `storage/` (logs incl. `logs/telegram.log`, `framework/*`, `app/pdf`, `app/mpdf`), `bootstrap/cache`, `database/` (SQLite) |
| Env vars | `APP_KEY`, `APP_URL` (HTTPS), `DB_*` or `DB_URL`/`DATABASE_URL` (+ `DB_SSLMODE`), `QUEUE_CONNECTION`, `CACHE_STORE`, `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, optional `TELEGRAM_WEBHOOK_URL`, `TELEGRAM_API_BASE`, `CRON_SECRET`, `ADHKAR_REMINDER_STALE_MINUTES`, `ADHKAR_REMINDER_DISPATCH_MAX_ATTEMPTS`, `ADHKAR_UPDATE_STALE_MINUTES`, `ADHKAR_UPDATE_MAX_ATTEMPTS`, optional `ADHKAR_CRON_CLAIM_BUDGET_SECONDS`, `ADHKAR_CRON_COUNT_QUERIES`, `DB_PGSQL_DISABLE_PREPARES` |

### 18.5 Can it support more manual adhkar collections without infrastructure changes?

INFERENCE: yes for manual (non-scheduled) reading. Additional manual collections would add rows to existing tables and use the existing webhook → queue → processor path. They would need no new services, cron entries, queue types or extensions. Content changes reach production through `adhkar:sync --if-changed` at container start, or a manual `adhkar:sync`. Scheduled collections would also run on the existing cron/queue infrastructure. The constraint there is code and schema (§20, §21), not infrastructure.

---

## 19. Performance

FACT unless marked:

| Aspect | Behaviour |
|---|---|
| Content in memory | No global load or cache. Each card loads the **entire current phase list** (all columns, ≤ 20 items) to pick one item. |
| Queries per reading card (`rt:` `s`/`a`) | user select + user update (`last_interaction_at`) + collection select + items select (+ for last short card, `fullOnlyItems` = 2 more collection + 2 more item queries). Phase `f`: `fullOnlyItems` = 4 queries, + 2 more on index 0 for the Previous target. `rd:` adds a reminder_logs select. Plus inbox: `telegram_updates` insert, job insert/delete (database queue), status updates. |
| Eager/lazy | Items via `belongsToMany` query each time; `SendAdhkarReminderJob` uses `with('user')`. |
| Caching | Only PDF files (disk) and Telegram `file_id` (cache store). |
| Telegram calls per card | 2 (`answerCallbackQuery` + `editMessageText`). |
| Calls per reminder | 1 `sendMessage` (nudge). Start: answer + edit. |
| PDF button | answer + `sendChatAction` + `sendDocument` (first time: mPDF render + multipart upload; later: `file_id`). Fallback: 1 + N `sendMessage` (3,500-char packing). |
| Long messages | Cards have no length guard; `adhkar.message_limit` (3900) and `items_per_message` are used only by unused `buildMessages()`. Virtues > 200 chars use expandable blockquote. INFERENCE: current longest items fit in Telegram's 4,096-char limit (no failure path observed in tests). |
| Callback count | One update per button press; webhook throttle 30/min per chat. INFERENCE: rapid paging over 30 presses/min would receive HTTP 429 and rely on Telegram webhook redelivery. |
| Dispatch scan | `chunkById(100)` over due users; two indexes on `(reminders_enabled, next_*_at)`; per user `fresh()` reloads (1–2 extra queries) and a transaction per claim. With `sync` queue each claim sends the Telegram nudge inline within the cron request (bounded by the 25 s claim budget). |
| PDF storage | `storage/app/pdf` files are never cleaned up (FACT: no deletion code). INFERENCE: on ephemeral containers they are regenerated after restarts. |

---

## 20. Coupling / Assumptions

| # | File | Class::function / location | Current assumption |
|---|---|---|---|
| 1 | `app/Enums/ReminderType.php` | enum | Only `morning`, `evening` reminder types |
| 2 | `app/Enums/AdhkarCategory.php` | enum | Only `morning`, `evening`, `additional` categories (cast on items and collections) |
| 3 | `app/Enums/AdhkarVariant.php`, `AdhkarMode.php` | enums | Only `short`/`full` |
| 4 | `database/migrations/0001_01_01_000000_create_users_table.php` | `users` columns + indexes | Exactly two reminder slots: `morning_time`/`evening_time`, `next_morning_at`/`next_evening_at` |
| 5 | `app/Services/ReminderDispatchService.php` | `claimDueLogIds` | WHERE clause and two explicit passes (Morning then Evening) |
| 6 | same | `isStillDue`, `claim` | Ternary `Morning ? next_morning_at : next_evening_at`; same for `*_time` |
| 7 | `app/Services/UserSettingsService.php` | `recalculateNextReminders`, `pause` | Recomputes/clears exactly the two `next_*` columns; requires both times present |
| 8 | `app/Models/User.php` | `isOnboarded` | Onboarded requires both `morning_time` and `evening_time` |
| 9 | `app/Services/OnboardingService.php` | whole flow, `summary` | Onboarding asks exactly timezone → morning → evening; summary lists those two times |
| 10 | `app/Jobs/SendAdhkarReminderJob.php` | `markFailed` | Blocking clears exactly `next_morning_at`/`next_evening_at` |
| 11 | `app/Services/AdhkarContentService.php` | `collectionKey` | Key = `{ReminderType}_{short|full}` |
| 12 | same | `fullKey`, `fullOnlyItems` | Keys contain `_short`/`_full`; short ⊂ full |
| 13 | same | `nudgeText`, `sessionHeader`, `wirdTitle` | `str_starts_with($key, 'evening')` → evening, **otherwise morning** |
| 14 | same | `footerForKey` | `morning…`/`evening…` footers; anything else → "هذه أذكار اختيارية، خذ منها ما تيسّر." |
| 15 | same | `modeChoiceText` | `Evening ? '🌙 أذكار المساء' : '🌅 أذكار الصباح'` |
| 16 | `app/Services/AdhkarPdfService.php` | `render` (`heading`), `subtitle` | `evening…` vs morning heading; `_full` detection |
| 17 | `app/Services/ContentSyncService.php` | `dataFiles` | Exactly three files `morning`, `evening`, `additional` |
| 18 | same | `collectionDefinitions` | Exactly five collections |
| 19 | `app/Services/Telegram/TelegramUpdateProcessor.php` | `routeCallback` (`today:morning`/`today:evening`) | Two today periods |
| 20 | same | `handleTodayReadingNav`, `handleOfflineSave` | Period ∈ {`m`,`e`} mapped to ReminderType |
| 21 | same | `readingCallback`, `saveCallback` | Defaults period to `m` |
| 22 | same | `deliverScheduledReading` | Phase from `_full` substring |
| 23 | same | `completionProblem` | Done allowed only for Morning/Evening types |
| 24 | same | `handleCompletion` | Completion text Evening vs else morning |
| 25 | same | `handleAdditionalCallback`, `additionalItems` | Single additional category; items queried by `category=additional` |
| 26 | `app/Support/Telegram/KeyboardFactory.php` | `todayMenu` | Three fixed buttons (morning, evening, additional) |
| 27 | same | `modeChoice` | Period letter `m`/`e`; two mode buttons |
| 28 | same | `reminderNudge` | `'evening' ? settings:evening_time : settings:morning_time` |
| 29 | same | `settingsMenu` | Two time buttons |
| 30 | `config/adhkar.php` | `morning_presets`, `evening_presets`, `additional_groups` | Two preset lists; groups reference only `additional.*` keys |
| 31 | `lang/ar/bot.php` | `nudge_*`, `completed_*`, `summary_*`, `resumed`, `tutorial`, `help`, `explain_body`, `welcome` | Copy names only morning/evening (and additional) |
| 32 | `app/Services/Telegram/TelegramUpdateProcessor.php` | `replyPauseResume` | Resume message shows the two times |
| 33 | Progress | `renderCard` | Generic (`N من total`); no fixed item count assumed. Durations "تقريبًا 5 دقائق / 10–15 دقيقة" are fixed strings in `bot.duration_*` |
| 34 | `database/migrations/…000006_create_reminder_logs_table.php` | `reminder_type` string(16), unique per type/day | One reminder per type per local day |

---

## 21. Future Feature Readiness

Factual answers only; no implementation is proposed.

**A. Can the content model represent `bedtime` and `waking` without changing the core model?**
Tables: yes. All identity columns are free strings, and items, collections and the ordered pivot are generic. PHP layer: no. `AdhkarItem.category` and `AdhkarCollection.category` are cast to `AdhkarCategory`, which has only three cases. `ContentSyncService` hard-codes both the data-file list and the collection list. FACT.

**B. Can the current reading engine display them?**
The renderer (`presentReading` / `renderCard` / `formatItem`) is item-generic, but it is reachable only through `rt:` (periods `m`/`e`) or `rd:` (reminder logs). Headers, footers and PDF titles come from key prefixes: any key not starting with `evening` is labelled "🌅 أذكار الصباح", and the footer falls back to the "additional" text. FACT.

**C. Can the current short/full mechanism represent them?**
Structurally, yes. Membership in an `{x}_short` and an `{x}_full` collection works with `fullKey()` and `fullOnlyItems()`. But the keys are built from `ReminderType` in `collectionKey()`, and the entry points accept only `m`/`e`. Only `morning`/`evening` exist as `AdhkarVariant`-bearing categories. FACT.

**D. Can the current Additional Adhkar navigation contain them?**
Only items whose `category` is `additional`: `add:item` and `add:group:all` filter by that category. Groups are configured in `config/adhkar.php` by item key, but only as a flat list of single items. There is no multi-item reading, Previous/Next or PDF. FACT.

**E. Can `/today` open them manually?**
No. `todayMenu`, `today:*` and `rt:` are hard-coded to morning/evening. FACT.

**F. Can the current reminder architecture schedule them?**
No. `ReminderType` has two cases, the `users` table has per-type time and next-run columns for morning/evening only, and the dispatch loop has two explicit passes. The `reminder_logs` table itself is type-agnostic, apart from the enum cast and `string(16)`. FACT.

**G. Which would require code changes?**
All six (A–F): the enums, `ContentSyncService` lists, `AdhkarContentService` labelling, today/reading callbacks and keyboards, the additional handlers (for non-`additional` categories), and reminders. INFERENCE from §20.

**H. Which would require database/schema changes?**
Content (A–E): none. The columns are generic strings. Scheduling (F): the current `users` schema has no columns for a third or fourth time slot. Per-type state would need new storage. `reminder_logs` and its unique key could hold new type strings ≤ 16 characters. FACT / INFERENCE.

**I. Which would require only content/config changes?**
None of A–F in full. The only config-only possibility today: items stored with `category='additional'` and listed in `config('adhkar.additional_groups')`, shown as single-item cards in the additional library (FACT, `additionalItems`). The data file and collection lists live in PHP, so even that needs a `ContentSyncService` edit unless the items go into the existing `additional.php`.

**J. Which current assumptions would block a clean implementation?**
Items 1–2, 4–6, 11–13, 17–20, 23 and 26 in §20. In short: the `ReminderType`/`AdhkarCategory` enums; dedicated `users` columns per slot; prefix-based labelling with a morning default; the `m`/`e` period letters; hard-coded today menu; hard-coded collection and data-file lists; and Done restricted to Morning/Evening.

---

## 22. Current Risks

Only issues observed in the repository.

### Blocker

- None found that prevents current operation of morning/evening/additional features. (Tests were not executed.)

### Important

1. **Docs contradict the code.**
   - *Why:* `docs/bot-flows.md`, `docs/implementation-checklist.md`, `docs/architecture.md`, `docs/testing-and-deployment.md` and `docs/database-design.md` were last changed 2026-09-26, before commit `8ac1ce7` (2026-10-01). `bot-flows.md` still describes mode in settings, a "📖 ابدأ القراءة" nudge button, `/today` using the user's current mode, and the save button sending ordinary messages. All four are no longer true.
   - *Impact:* another engineer or AI reading the docs gets a wrong model.
2. **The production database target is ambiguous.**
   - *Why:* README, `docs/architecture.md`, `deploy/production.env.example` and `docker-compose.yml` say MySQL. `docs/PRODUCTION_DATABASE.md` and the `docker/entrypoint.sh` production guard say Neon PostgreSQL.
   - *Impact:* it is unclear which path is live, and which uniqueness and locking semantics apply in production. The code handles both drivers.
3. **Missed reminders are caught up one day per tick.**
   - *Why:* `ReminderDispatchService::claim` advances from the old `dueAt`, and the send job has no lateness check.
   - *Impact:* after downtime, a user can receive several stale nudges in a row, and each Start answers "expired" (§11.3).
4. **The snapshot is recorded but not used to render.**
   - *Why:* `content_version_map` is stored at Start, but cards are built from live DB rows and `rd:` indices.
   - *Impact:* a sync during a reading can change, shift or shorten the list mid-session, and indices may point to different items (`presentReading`).
5. **Enum casts will throw on unknown values.**
   - *Why:* `AdhkarCategory`, `AdhkarVariant` and `ReminderType` casts are applied to DB strings.
   - *Impact (INFERENCE):* a data row or log row with a new value raises a `ValueError` on read anywhere that model is loaded.
6. **Open provenance item.** `morning.salat_nabi` and `evening.salat_nabi` have no source, number, grade or virtue, and the checklist marks them "Source review … pending" (`docs/implementation-checklist.md`, data files).

### Minor

1. **Dead code and config:**
   - `AdhkarContentService::buildMessages`, `renderChunk` and `resolveCollection`
   - `KeyboardFactory::completion`
   - `config('adhkar.items_per_message')`, `message_limit` and `inter_message_delay_microseconds` (used only by tests or nothing)
   - `bot.closed`
   - `ReminderLogStatus::Skipped`, which is never set
   - `users.mode`, which no UI writes
   - `adhkar_collections.title/description/order`, which are not shown anywhere
2. `database/seeders/DatabaseSeeder.php` passes `name`/`email`, which are not `users` columns. INFERENCE: those attributes are discarded and a random onboarded user with reminders enabled is created.
3. `rd:` and `sv:l:` do not re-check same-day or status. Old reminder readings stay navigable and exportable (`handleScheduledReadingNav`, `handleOfflineSave`).
4. Tapping `⚙️ تعديل الوقت` on a nudge replaces that nudge, so the user loses its Start buttons (`show` with `messageId`).
5. `settings:toggle_reminders` leaves a stale label on the settings message (§14.2).
6. `isBlockedByUser()` treats any HTTP 403 and "chat not found" as blocked and disables reminders.
7. `telegram_updates` rows marked `failed` are never re-dispatched by stale recovery, which only handles `pending`/`processing`.
8. INFERENCE: on Vercel, `Log::channel('telegram')` still writes to a daily file under `storage/logs`, not stderr.
9. `database/data/adhkar/additional.php` claims the ⚠️ section in `resources/adhkar/additional.md` was excluded, but that section is no longer in that file. It exists only in root `adhkar_additional.md`.
10. Duplicate editorial folders (`app adhkar/` and `resources/adhkar/`) and two additional-research variants exist with no documented authority between them.
11. Sync's "unchanged" branch does not persist `order`/`category` changes to `adhkar_items`. Pivot order is updated, so display order still changes.
12. PDF files in `storage/app/pdf` are never cleaned up.

### Unclear

1. Which deployment (VPS, Docker, or Vercel) is production, and whether cron-job.org is configured. Unknown — not established by repository inspection.
2. How Vercel builds or runs this Docker-based app (no `vercel.json`). Unknown — not established by repository inspection.
3. Whether any users still have `mode='full'` from before the mode step was removed. Unknown — not established by repository inspection.
4. Why `POSTGRES_PRODUCTION_SETUP_REPORT.md` is staged for deletion. Unknown — not established by repository inspection.

---

## 23. Executive Summary

1. **How the bot works today.** Telegram sends updates to a secret webhook. Each update is stored in `telegram_updates` and processed by a queued job (the `sync` queue on Vercel) through `TelegramUpdateProcessor`, a single prefix-routed handler. Users onboard with region, morning time and evening time, then get a reply keyboard: أذكار اليوم، أذكار إضافية، الإعدادات، إيقاف/تفعيل، مساعدة، شرح البوت.
2. **Where adhkar content lives.** Runtime content is the PHP arrays in `database/data/adhkar/{morning,evening,additional}.php`, imported by `adhkar:sync` into `adhkar_items` / `adhkar_collections` / `adhkar_collection_items`. The Markdown and research files are human-only references.
3. **How collections are represented.** Five hard-coded rows: `morning_short` (9), `morning_full` (20), `evening_short` (9), `evening_full` (19), `additional` (10 active). Membership comes from each item's `collections` list; order comes from the item's `order`.
4. **How short/full works.** By collection membership and the key convention `{morning|evening}_{short|full}`. The user picks short or full on every reading (from the nudge or `/today`). `users.mode` is vestigial. From the last short card, "أكمل الورد الكامل" shows only the full-only items.
5. **How reading works.** One item per card, edited in place. The cursor lives in callback data (`rt:` for today, `rd:` for reminders). Previous/Next, a PDF export button (text fallback), continue-to-full, and ✅ تم (reminder readings only). No reading state is stored except the log's `reading_started_at` and version snapshot.
6. **How reminders work.** Each minute's tick (`schedule:run` or `POST /internal/cron`) claims due users in a transaction: insert a log (unique per user/type/local day), advance `next_*_at`, queue `SendAdhkarReminderJob`. The job sends one nudge with 🌿 مختصر / 📖 كامل buttons. Start re-checks the day, locks the row, records `collection_key` and the snapshot, then pages the cards. Done marks the log completed. Stale recovery, retry guards and blocked-user handling exist.
7. **How `/today` works.** Pick morning or evening, then pick short or full, then the same reading engine. No log, no Done, no stored state.
8. **How Additional Adhkar works.** A config-driven group menu (قصيرة 7، بعد الصلاة 1، الصلاة على النبي ﷺ 2، جميع الأذكار 10) of single-item detail cards, queried by `category='additional'`. Not the reading engine, no short/full, never scheduled.
9. **What is stored in the DB.**
   - `users`: identity, timezone and label, two times, two next-run timestamps, `reminders_enabled`, onboarding state.
   - Content tables, with `content_version` and `source_hash`.
   - `reminder_logs`: status, `collection_key`, snapshot, timestamps.
   - `telegram_updates` inbox, plus the framework's queue, cache and session tables.
10. **What is hard-coded to morning/evening.** The `ReminderType` and `AdhkarCategory` enums; the `users` time and next-run columns and their indexes; the dispatch loop; onboarding and settings; today menu and `m`/`e` callbacks; prefix-based headers, footers, nudge and PDF titles (anything else defaults to morning); Done validation; the sync service's data-file and collection lists; preset lists; most copy. Full list in §20.
11. **What to understand before adding bedtime/waking.**
    - The enum casts and hard-coded sync lists.
    - The string conventions behind `collectionKey`/`fullKey` and the labelling defaults.
    - Which entry points exist (today menu, additional library, reminders) and their period letters.
    - The per-slot `users` schema, if bedtime/waking would ever be scheduled.
    - The `additional` category filter.
    - Provenance fields and the fact that no bedtime/waking source material exists in the repo.
12. **Main technical constraints.**
    - A new collection cannot be added through data alone; PHP lists and enums gate it.
    - Reminders need a schema change for any new slot.
    - Cards render live content (the snapshot is informational only).
    - Each card re-queries the whole phase list (≤ 20 items), which is cheap at current sizes.
    - Webhook throttle is 30 updates/min per chat.
    - Vercel runs with a `sync` queue and an in-request cron tick.
13. **Unknowns that need clarification.**
    - Which production deployment and database are live.
    - Whether external cron is configured.
    - How Vercel runs the app.
    - Whether legacy `mode='full'` users exist.
    - Source authority between the duplicate editorial files.
    - The pending source review of `*.salat_nabi`.
    - The reason for the staged deletion of `POSTGRES_PRODUCTION_SETUP_REPORT.md`.

# Bot flows

Language: Arabic, in a warm, simple Levantine tone. UI strings live in `lang/ar/bot.php`. Religious text lives in adhkar items and is not rewritten by UX changes. Digits are always Western (0–9).

## Commands

| Command | Behavior |
|---|---|
| `/start` | New user: a three-line tutorial with `🚀 ابدأ الإعداد` and `💡 شو فكرة البوت؟`. A user already inside setup returns to the current step. A returning user sees a summary, today, help, and an explicit reset that asks for confirmation first. A returning `/start` also resends the bottom keyboard with a line that says it can be hidden and shown from the keyboard icon. |
| `/today` | The reading menu (see Reading). |
| `/settings` | Summary plus one control per row (see Settings). |
| `/help` | The help menu: one button per question. |
| `/pause` | Pauses at once and shows an undo button. The settings button and typed words ask for confirmation first. |
| `/resume` | Resumes and shows the saved times. |
| `/feedback` | Opens the feedback prompt (see Feedback). |

Command descriptions are registered in Arabic with `php artisan telegram:commands:set`.

Reply keyboard, four rows: [🌅 أذكار الصباح، 🌙 أذكار المساء], [☀️ أذكار الاستيقاظ، 🛌 أذكار النوم], [🕌 أذكار الصلاة، 🌟 أذكار إضافية], [⚙️ الإعدادات، ❓ المساعدة والشرح]. Morning and evening open the wird directly (remembered short/full, or the mode choice). It is not persistent: the user hides and shows it with Telegram's keyboard icon next to the input field. The placeholder is «اختر من الأزرار 👇». A returning `/start` resends it. Old keyboard labels (📖 أذكار اليوم، 🌙 أذكار النوم، 🌅 أذكار الاستيقاظ، مساعدة، شرح البوت، إيقاف التذكير، تفعيل التذكير) still work. Inline rows are reversed so the primary Arabic action sits on the right.

## Setup

1. Tutorial, then `🚀 ابدأ الإعداد`.
2. Eight featured countries (`config('adhkar.featured_timezones')`: سوريا، لبنان، الأردن، فلسطين، العراق، مصر، السعودية، تركيا), then `✍️ دولة أخرى (أكتب اسمها)` (blue, `style: primary`) and `📍 حدد بلدي من موقعي` (green, `style: success`). The full list in `config('adhkar.timezones')` still backs typed names and `onb:tz:{index}`.
   - The location button sends a bottom keyboard with Telegram's location request and «🌍 بختار من القائمة», plus the note that the location is not stored.
   - A shared location is matched to the nearest timezone from PHP's bundled tz database (`App\Support\NearestTimezone`). Only that timezone is cached for 30 minutes; coordinates are never saved.
   - The bot confirms with the local time: «الساعة عندك هلق 4:26 عصرًا، صح؟» with `✅ صح` (`loc:yes`) and `🌍 بختار بنفسي` (`loc:no`, back to the list).
   - A location sent when the bot did not ask for one gets a polite reply and is not used.
3. Quick setup: «اخترنالك وقتين مناسبين» with the suggested times from `config('adhkar.suggested_times')` (6:00 صباحًا and 5:00 عصرًا). `onb:quick:yes` saves both and finishes; `onb:quick:custom` opens the morning and evening time steps.
4. Time steps use 12-hour preset buttons and «⏰ وقت آخر (أكتبه بنفسي)».

## Time input

Typed times are understood the way people say them: «8 المسا»، «6 ونص الصبح»، «8 إلا ربع الصبح»، «5 العصر»، «12 الضهر»، «٨ م»، `5:30`. A bare hour that could be morning or evening asks which one. Every kind of mistake has its own message with the user's text and an example (`time_*` keys), and close guesses get a «قصدك …؟» button. Times are stored as `HH:MM:SS` and shown in 12-hour form (صباحًا، ظهرًا، عصرًا، مساءً).

## Settings

Rows: region, morning time, evening time, reminder style, follow-up, pause or resume, then `🔙 رجوع` (`nav:home`), the same back button as the help menu. `settings:close` is still handled for older messages. The summary above them lists region, both times, whether reminders are on, the reminder style, and the follow-up state.

- Reminder style (`users.reminder_style`): `nudge` (default) or `direct`. The `🔔 شكل التذكير: …` button (`settings:style`) opens a screen that explains both styles side by side and marks the current one with ✅. `settings:style:nudge` and `settings:style:direct` save the choice. See Reminders.
- Follow-up (`users.follow_up_enabled`, off by default). See Follow-up.
- Pause asks «بدك توقف التذكيرات؟» first. After pausing, an undo button resumes at once.
- Region changes can also use the location button.

## Help

`❓ المساعدة والشرح` opens one button per question: the idea of the bot, how to read, reminder styles and the follow-up, changing the time, stopping reminders, short vs full, bedtime, waking, prayer and additional adhkar, enlarging the font, adding the bot to a group, and missing reminders. Each answer has a button that leads to the related screen, and «🔙 باقي الأسئلة».

## Feedback

The last row of the bottom keyboard, `💬 أرسل ملاحظة أو اقتراحًا`, the last button of the help menu (`feedback:start`), and `/feedback` open a short prompt with `❌ إلغاء` (`feedback:cancel`). The next message, of any kind (text, voice, photo...), is the feedback. The prompt stays open for 30 minutes (a cache key, not `awaiting_input`, so setup steps are untouched). A command, a bottom keyboard button, or any other inline button closes it, and the message then works as usual.

`FeedbackService` stores each one in `feedback` and, when `TELEGRAM_ADMIN_CHAT_ID` is set, sends the owner a header (name, username, Telegram id, region, reminders, join date) followed by `copyMessage` of the user's message as a reply to it. When the owner replies to either message, the reply is copied to the user under «ردّ من فريق البوت على ملاحظتك» and `replied_at` is set; the owner gets a confirmation, or a warning when the user blocked the bot. Other messages from the owner work as usual. Each user can send `TELEGRAM_FEEDBACK_PER_HOUR` (default 5) per hour.

## Reading

`/today` greets by time of day (`App\Support\DayPeriod`, windows in `config('adhkar.day_windows')`) and puts the current period first, colored blue (`style: primary`). At night, bedtime goes first. No choice is removed. Additional adhkar have their own keyboard button and are no longer in this menu.

The first time the user opens morning or evening they pick short or full; the choice is remembered (`users.mode`, `users.mode_chosen_at`) and later readings open straight on the remembered wird. The first card has a switch button (`📖 بدّل للورد الكامل` or `🌿 بدّل للورد المختصر`) that also updates the remembered choice.

Each card is one adhkar: header, `الذكر 3 من 9`, title, `🔁 التكرار`, the Arabic text, then virtue, source, and grade. Virtue, source, and grade share an expandable blockquote. A source with no virtue is plain text, not a quote. Waking cards skip the item title and start at the dhikr. The narrator and guidance notes are never shown, and titles carry no repetition counts. «➡️ التالي» sits alone on the top row, «السابق ⬅️» below it. In the Arabic button the next arrow lands on the right and the previous arrow on the left. Both edit the same message. The cursor is the callback: `rd:{logId}:{s|f|a}:{index}` for a reminder, `rt:{m|e|b|w|p}:{s|f|a}:{index}` for Today. On the last short card, `أكمل الورد الكامل` opens only the items that are in the full wird and not the short one.

`📥 أرسل الورد كاملًا` (`sv:…`) sends the wird pages as photos (an album, rendered from the PDF by `pdftoppm` and cached next to it), followed by `🖨 نسخة PDF للطباعة` (`sp:…`) which sends the PDF. Without `pdftoppm` (for example on a local machine) the PDF is sent directly. If the PDF fails too, the wird is sent as text messages.

Bedtime, waking (3 adhkar), after-prayer (`after_prayer`, 8 adhkar from Hisn al-Muslim chapter 25, period code `p`) and the additional list (period code `z`, used for sending them all at once) are single-version collections, with no reminder, no Done button, and no short/full choice.

🌟 Additional adhkar: one flat list, one button per dhikr (`add:item:{id}`). The card is only that dhikr, with «📋 كل الأذكار الإضافية» (`add:list`) to return. Under the list, «📥 أرسل كل الأذكار الإضافية» (`sv:t:z:a`) sends them all at once, as page images with a printable PDF button, or as a PDF when page images are not available. Old `add:group:*` and `add:back` still open the list, and `add:item:{id}:{group}` still opens that one card. The salawat formulas and their virtue are one item; the after-prayer tasbih moved to `after_prayer`.

## Reminders

Due claim is unchanged: one log row, `collection_key` frozen from the user's mode at claim time, next occurrence advanced in the same transaction, then `SendAdhkarReminderJob`.

- Nudge style: one short message naming both durations, with `🌿 مختصر`, `📖 كامل` (`reminder:start:{id}:{s|a}`), and `⚙️ تعديل الوقت`. Start claims the log, snapshots the content, remembers the choice, and turns the same message into the first card.
- Direct style: the job sends the first card of the frozen collection right away, with «حان وقت أذكار الصباح» on top, the switch button (`rsw:{id}:{s|a}`), save, and Next. The log is claimed and snapshotted in the same update that marks it sent.

`rsw:{id}:{s|a}` switches between short and full inside a reminder: it re-snapshots the log under a lock, remembers the choice, and shows the first card of the other wird. `reminder:done:{id}` completes the log only when it is owned, for today, started, and not completed. Repeats are idempotent.

Expired reminders (not today in the user's timezone) reply with a short message and an أذكار اليوم button.

## Follow-up

When `follow_up_enabled` is on, `FollowUpReminderService` sends one gentle message if all of these hold:

- the reminder was sent at least an hour ago (and at most 6 hours ago),
- reading has not started and the log is not completed,
- the user has not used the bot since the reminder (which covers reading the same adhkar from the menu),
- the reminder is for today and its period (morning or evening) is still current.

The message has `📖 يلا نقرأ` (starts the reminder on the remembered wird) and `🔕 لا تبعتلي تذكير تاني` (turns the setting off). `reminder_logs.follow_up_sent_at` makes it once per reminder. It runs on the cron tick together with stale recovery (every 5 minutes, so idle ticks keep their query budget) and as `adhkar:send-follow-ups` in the Laravel scheduler.

## Messages the bot does not understand

- Typed words are matched by keywords only (`App\Support\IntentMatcher`, no AI): morning, evening, bedtime, waking, time, settings, help, pause, resume, thanks, salam, greetings. Words that change settings only open a confirmation.
- Voice, photos, stickers, videos, files, contacts, and locations each get their own short reply and a hint.
- Buttons on old messages reply «هالزر قديم» with the current keyboard.
- A double press within `config('telegram.double_press_seconds')` is ignored.

## Usage tracking

`BotEventRecorder` writes rows to `bot_events` and never breaks a reply if that fails. Events include setup steps, time errors (with the reason), unknown text (trimmed to 80 characters), media, intents, help topics, pause and resume (with seconds since the pause), reminders opened, readings completed, reminder style, follow-up setting and sends, and shared locations (only whether a timezone was found).

Newer events: `additional_item` (which additional dhikr was opened), `wird_saved` (collection and images or PDF), `feedback_sent`, and `bot_blocked` / `bot_unblocked` from Telegram's `my_chat_member` update in the private chat.

`ReadingSessionRecorder` keeps one `reading_sessions` row per opened wird: collection, phase, source (`reminder`, `direct`, `manual`, or `continue` for the full-only part after a short wird), the reminder log, the furthest card reached, the user's local hour and weekday, and when it was completed. Paging updates the row; a short/full switch on the first card within two minutes replaces it instead of counting a drop-off.

`AdminReportService` builds the owner's report: users (new, setup funnel, active, reminders on, pauses, blocks, and how many of the previous period's new users came back), reminders (open and Done rates per period, the median minutes until opened, follow-ups), readings per collection with completion and the short/full split, where people open from, the busiest local hours, where readers stop (the most common last card per collection), and other usage (additional adhkar, saved wirds, help topics, reminder styles, feedback, groups).

- `/stats` (or `/stats 30`) from `TELEGRAM_ADMIN_CHAT_ID` replies with the report; from anyone else it is an unknown command.
- The weekly report goes to the same chat on Friday at 10:00 in `TELEGRAM_ADMIN_TIMEZONE` (default `Asia/Damascus`). It runs on the cron tick and is part of the wake schedule, and `bot:weekly-report` (hourly in the Laravel scheduler, `--force` to send now) does the same. The sent slot is kept in the cache so it goes out once.
- `php artisan bot:stats --days=7` prints counts, the setup funnel, reminder completion, quick undos, and the top values per event.
- Unknown text is deleted after 30 days, after each new unknown text and daily by `bot:prune-events`.

## Recovery

`reminder_stale_minutes` must stay longer than the Telegram HTTP timeout plus queue latency and a processing buffer. The default is 3 minutes.

- Pending, no `last_dispatch_attempt_at`, older than the window: re-dispatch.
- Pending, attempt timestamp still inside the window: leave it.
- Pending, attempt timestamp older than the window: mark `nudge_unconfirmed` and do not send again.
- A failed Telegram call can be retried by the same job. A blocked user stays failed and reminders are disabled.

## Not in this version

Simple mode and family links, AI understanding, a weekly summary, a counter, audio, and prayer-linked times. The reading cursor lives in the inline button, so closing the chat does not reopen the same card.

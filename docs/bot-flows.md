# Bot flows

Language: Arabic. UI strings live in `lang/ar/bot.php`. Religious text lives in adhkar items and is not rewritten by UX changes.

## Commands

| Command | Behavior |
|---|---|
| `/start` | New user (`onboarding_state = new`): one tutorial message and a single button, `🚀 ابدأ الإعداد`. That button edits the same message into region selection and does not save settings. A user already inside onboarding is returned to the current step, without a second tutorial. Returning user: summary, today, settings, and an explicit reset. The tutorial is not shown again. |
| `/settings` | Summary plus one control at a time (region, morning, evening, mode, reminders). Each save returns to the updated summary. |
| `/help` | Short Arabic guide and the command list. |
| `/pause` | Disable reminders, keep settings, refresh the reply keyboard. |
| `/resume` | Enable reminders, show the saved times, refresh the reply keyboard. |
| `/today` | Choice menu, then the chosen reading starts immediately from the user's current mode. No reminder log and no Done button. |

Reply keyboard: أذكار اليوم، أذكار إضافية، أذكار النوم، أذكار الاستيقاظ، الإعدادات، إيقاف التذكير or تفعيل التذكير, مساعدة, and شرح البوت. النوم and الاستيقاظ share their own row. Existing users see the new row the next time the bot sends the main keyboard (for example `/start` or `/help`). شرح البوت resends the guide and does not restart setup. The pause/resume label follows `reminders_enabled`. Inline rows are reversed so the primary Arabic action sits on the right.

The first region screen has no Back button. Later choice screens (America region, time presets, mode) show Back only. Settings region selection still has Back. Cancel appears only on free-text prompts opened from settings, and it discards the unsaved text and returns to the settings summary. During first-time onboarding, free-text prompts show Back only. The settings root keeps its own close button.

## Timezone and time

Users pick a country. America then asks for a region. Coverage is limited to four zones: New York, Chicago, Denver, and Los Angeles. Other US zones are not offered in this version. Other countries save in one step. The chat shows `timezone_label` (for example سوريا or أمريكا · نيويورك). The IANA id stays in `users.timezone` and is never shown.

Custom text accepts Arabic country and city names. A multi-zone country name opens the region step instead of guessing a city. Preset times stay on a 24-hour clock. Custom times accept `5:30`, `05:30`, Arabic-Indic digits, and an optional ص/م (or am/pm) suffix, and are stored as `HH:MM:SS`. Saved times are shown in 12-hour form with ص or م, such as `6:00 ص` and `8:00 م`. A bare evening hour from 1 to 11, and any bare hour 12, is not saved until the user picks ص or م. Invalid input is a short Arabic error plus Back.

## Reminders

Due claim is unchanged: one log row, `collection_key` frozen from the mode at claim time, next occurrence advanced in the same transaction, then `SendAdhkarReminderJob`.

The job sends one short nudge and marks the log `sent`. It does not send the adhkar. The nudge names an approximate duration: about 5 minutes for the short wird, and about 10–15 minutes for the full wird. Cards do not repeat that estimate. `reading_started_at` and `content_snapshot_hash` stay empty until Start. The nudge buttons are `📖 ابدأ القراءة` and `⚙️ تعديل الوقت`.

`reminder:start:{id}`:

1. The log must belong to the callback user.
2. `scheduled_local_date` must still be today in the user's current timezone. Otherwise a short expired reply and an أذكار اليوم button. No send and no new log.
3. `lockForUpdate()`, then if reading has not been claimed, snapshot `collection_key` (not the user's current mode), set `reading_started_at`, and commit.
4. Only after that commit, send the first adhkar card. Later cards edit that message. Done is on the last card of the active list.
5. A second Start does not send again. If the first card fails to send, the claim and snapshot stay, the user is told they can open أذكار اليوم, and Start does not resend.

`reminder:done:{id}` completes the log only when it is owned, for today, already started, and not completed. A repeat is idempotent. Anything else does not write.

Each card is one adhkar: period title, مختصر or كامل, `الذكر 3 من 9`, then title, repetition, Arabic, virtue in a blockquote, and a compact source line. There is no duration estimate on the card. Next sits on the right and Previous on the left; both edit the same message. The cursor is the callback (`rd:{logId}:{s|f|a}:{index}` for a reminder, `rt:{m|e}:{s|f|a}:{index}` for Today), not a database column. On the last short card, `أكمل الورد الكامل` opens only the items that are in the full wird and not the short one, without changing the saved mode. Previous on the first of those returns to the last short card. A user already on كامل does not see that button. `📥 أرسل الورد كاملًا` sends the current list as ordinary messages with no keyboard and does not store a copy. Today uses the same pager and has no Done button. Narrator and guidance stay on a single additional item.

Additional adhkar are a grouped library (short, after prayer, salawat, all). They are never scheduled.

## Bedtime and waking (manual only)

`🌙 أذكار النوم` (13 items) and `🌅 أذكار الاستيقاظ` (2 items, for waking up from sleep) are reached only from their reply-keyboard buttons. They are not in `/today`, which stays morning, evening, and additional.

- One version each (collection keys `bedtime` and `waking`). No short/full choice, no مختصر or كامل on cards, and no duration estimate.
- The button opens the first card at once, using the same pager as Today: `rt:b:a:{index}` and `rt:w:a:{index}`. Any other phase for `b`/`w` answers `bot.unknown`.
- The header is the collection title only. The last card ends with its own closing line.
- `📥 أرسل الورد كاملًا` uses `sv:t:b:a` / `sv:t:w:a`: the same PDF (titled أذكار النوم / أذكار الاستيقاظ) and the same text fallback.
- No reminder log, no Done button, no stored reading state.
- There are no bedtime or waking reminders. Onboarding and settings are unchanged.
- Waking excludes «من تعار من الليل» and the night recitation of Aal-Imran 190–200. Sources: `resources/adhkar/bedtime.md`, `resources/adhkar/waking.md`.

## Recovery

`reminder_stale_minutes` must stay longer than the Telegram HTTP timeout plus queue latency and a processing buffer. The default is 3 minutes.

- Pending, no `last_dispatch_attempt_at`, older than the window: re-dispatch.
- Pending, attempt timestamp still inside the window: leave it.
- Pending, attempt timestamp older than the window: mark `nudge_unconfirmed` and do not send another nudge.
- A failed Telegram call can be retried by the same job. A blocked user stays failed and reminders are disabled.

## Not in this version

The reading cursor lives in the inline button, not in a stored column. Closing the chat and coming back does not reopen the same card. A scheduled reading whose first send fails is not continued automatically. The user can open `/today`, which uses the current mode and may differ from that log's `collection_key`.

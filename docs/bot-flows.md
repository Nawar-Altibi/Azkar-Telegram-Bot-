# Bot flows

Language: Arabic. UI strings live in `lang/ar/bot.php`. Religious text lives in adhkar items.

## Commands

| Command | Behavior |
|---|---|
| `/start` | New user: timezone → morning time → evening time → short/full. Returning user: summary + reset option. |
| `/settings` | Edit timezone, times, mode, reminders on/off. Recalculates `next_*_at`. |
| `/help` | Short command list. |
| `/pause` | Disable reminders, keep settings. |
| `/resume` | Enable reminders, recalculate next times. |
| `/today` | Inline: morning / evening / additional. |

Reply keyboard after onboarding: أذكار اليوم، أذكار إضافية، الإعدادات، إيقاف/استئناف، مساعدة.

## Timezone and time

No Telegram time picker. Curated city buttons plus custom IANA / Arabic alias input. Time presets plus `HH:MM` validation.

## Reminders

Chunked messages (Option A). Last reminder message has optional `✅ تم` (idempotent completion, no streaks). Additional adhkar is browse-only and never auto-sent.

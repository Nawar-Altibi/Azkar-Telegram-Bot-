# AZKAR BOT — Implementation Plan

Pinned stack: **Laravel `^11.31` / PHP `^8.2`** (local target PHP 8.2.12).

This file is the index for the documentation package.

- [Architecture](architecture.md)
- [Database design](database-design.md)
- [Bot flows](bot-flows.md)
- [Testing and deployment](testing-and-deployment.md)
- [Implementation checklist](implementation-checklist.md)

## Product

Arabic-first Telegram bot that helps a user keep a calm daily habit of authentic morning and evening adhkar, with an optional additional section. Religious content is transcribed from `resources/adhkar/*.md` (copied from `app adhkar/`) into structured seed files and synced with `php artisan adhkar:sync`. Wording, repetitions, virtues, and sources are not rewritten.

## Runtime shape

```
Telegram → POST /telegram/webhook/{secret}
        → persist telegram_updates
        → ProcessTelegramUpdateJob
        → TelegramUpdateProcessor

Cron → schedule:run every minute
    → adhkar:dispatch-due-reminders (atomic claim + after-commit dispatch)
    → adhkar:recover-stale every 5 minutes

Queue worker → SendAdhkarReminderJob (one nudge) / ProcessTelegramUpdateJob
             → Start callback claims the log, snapshots collection_key, then sends chunks
```

UX behavior is documented in [Bot flows](bot-flows.md). There is no reading-resume cursor in this version.

## First-time path

```text
/start
    ↓
🌿 First-time tutorial
    ↓
🚀 ابدأ الإعداد
    ↓
🌍 اختر منطقتك
    ↓
🌅 وقت الصباح
    ↓
🌙 وقت المساء
    ↓
📖 مختصر / كامل
    ↓
✅ تم ضبط التذكيرات
    ↓
🏠 Main keyboard
```

The tutorial appears only while `onboarding_state` is `new`. A returning `/start` does not show it. `/today` is manual and does not create reminder completion state. Scheduled Start stays on the log's `collection_key`. `📥 أرسل الورد كاملًا` sends the list; it does not persist a copy. America offers four zones only: نيويورك، شيكاغو، دنفر، لوس أنجلوس. Interactive reading stays separate from that full-ward send.

`morning.salat_nabi` and `evening.salat_nabi` are flagged for source review against `adhkar-research.md` §24 and were not rewritten in the UX pass.

## Local commands

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan adhkar:sync
php artisan telegram:poll
php artisan queue:work --tries=3 --backoff=5,30,120
php artisan schedule:work
php artisan test
```

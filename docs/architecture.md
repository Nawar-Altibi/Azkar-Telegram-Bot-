# Architecture

## Pinned versions

| Constraint | Value | Why |
|---|---|---|
| PHP | `^8.2` | Inspected local/runtime PHP **8.2.12**. Laravel 11 requires PHP >= 8.2. PHP 8.3 was not available. |
| Laravel | `^11.31` (installed `11.56.1`) | Laravel 11 is the chosen major. This pin is a fixed constraint for this implementation. |
| Database | SQLite locally/tests, MySQL 8 in production | Zero extra services for development. |
| Queue | `database` | No Redis required for MVP. |
| Tests | PHPUnit 11 | Ships with Laravel 11; equivalent coverage to the Pest plan. |

## Layers

- **HTTP**: `TelegramWebhookController` verifies the secret, validates `update_id`, persists `telegram_updates`, dispatches `ProcessTelegramUpdateJob`, returns 200/400/403. It never runs bot business logic.
- **Queue**: `ProcessTelegramUpdateJob` and `SendAdhkarReminderJob` (`ShouldBeUnique`).
- **Domain services**: `OnboardingService`, `UserSettingsService`, `ReminderScheduleCalculator`, `ReminderDispatchService`, `AdhkarContentService`, `ContentSyncService`.
- **Telegram**: `TelegramClient` (HTTP wrapper), DTOs under `App\Support\Telegram`, `KeyboardFactory`, `TelegramUpdateProcessor`.

## Reminder guarantee

Claim insert + next-occurrence advance run in one DB transaction. The send job is dispatched only if that transaction commits. Unique `(user_id, reminder_type, scheduled_local_date)` is the duplicate guard. Stale `pending` rows are recovered; after 5 attempts they are marked `dispatch_exhausted_retries` and never silently dropped.

## Content

Markdown in `resources/adhkar/` is editorial source. `database/data/adhkar/*.php` is the machine input. `adhkar:sync` upserts by stable `key`, hashes canonical fields, bumps `content_version` on change, and never deletes. Reminder logs store `content_version_map` + `content_snapshot_hash` at send time.

## Security

- `TELEGRAM_BOT_TOKEN` and `TELEGRAM_WEBHOOK_SECRET` from environment only.
- Webhook path secret + `X-Telegram-Bot-Api-Secret-Token` header (`hash_equals`).
- Logs must not print the bot token.
- GitHub tokens must never be embedded in remotes or committed.

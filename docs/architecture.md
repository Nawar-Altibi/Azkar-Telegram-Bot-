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

Claim insert + next-occurrence advance run in one DB transaction. The send job is dispatched only if that transaction commits. Unique `(user_id, reminder_type, scheduled_local_date)` is the duplicate guard. The job sends one nudge, then marks the log `sent`. Start claims the same row with `lockForUpdate()` and stores the content snapshot before any chunk. `collection_key` is the collection that gets read, not the user's later mode.

`reminder_stale_minutes` must exceed the Telegram HTTP timeout (`telegram.timeout`), expected queue latency, and a processing buffer, so recovery does not race a request still in flight. Recovery re-dispatches only a stale pending log whose `last_dispatch_attempt_at` is still null. If an outbound attempt was recorded and the row is still pending after the window, it is marked `nudge_unconfirmed` and not resent. Never-attempted stale logs still retry until `dispatch_exhausted_retries`.

## Content

Markdown in `resources/adhkar/` is editorial source. `database/data/adhkar/*.php` is the machine input. `adhkar:sync` upserts by stable `key`, hashes canonical fields, bumps `content_version` on change, and never deletes. `snapshotVersions()` sorts by collection order then item key and hashes that canonical JSON. The same order and versions always produce the same hash. The snapshot is written when Start is claimed, before the first chunk, and is not a completion flag.

## Security

- `TELEGRAM_BOT_TOKEN` and `TELEGRAM_WEBHOOK_SECRET` from environment only.
- Webhook path secret + `X-Telegram-Bot-Api-Secret-Token` header (`hash_equals`).
- Logs must not print the bot token.
- GitHub tokens must never be embedded in remotes or committed.

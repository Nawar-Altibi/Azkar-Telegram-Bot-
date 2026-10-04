# Database design

## Tables

- `users` — Telegram identity + settings + `timezone` (IANA, internal) + `timezone_label` (shown in chat) + `next_morning_at` / `next_evening_at` (UTC) + onboarding state.
- `adhkar_collections` — `morning_short`, `morning_full`, `evening_short`, `evening_full`, `additional`, `bedtime`, `waking` (the last two are single-version, manual-only).
- `adhkar_items` — one row per dhikr (morning and evening are never merged). Includes `title`, text, repetitions, virtue, source, grade, `content_version`, `source_hash`.
- `adhkar_collection_items` — ordered pivot. Full collections reuse short items.
- `reminder_logs` — unique `(user_id, reminder_type, scheduled_local_date)`. Status stays `pending|sent|failed|completed|skipped`. `sent_at` is the nudge. `reading_started_at` plus `content_version_map` / `content_snapshot_hash` are the Start claim, written before chunks. `completed_at` is optional Done. `last_dispatch_attempt_at` is set immediately before the nudge HTTP call. `dispatch_attempts` counts handoffs.
- `telegram_updates` — webhook inbox, unique `update_id`.
- Laravel `jobs`, `job_batches`, `failed_jobs`, `cache`, `sessions`.

## Important indexes

- `users(telegram_id)` unique, `users(chat_id)` unique
- `users(reminders_enabled, next_morning_at)`
- `users(reminders_enabled, next_evening_at)`
- `reminder_logs(status)`, `reminder_logs(scheduled_for)`
- `telegram_updates(status, received_at)`

No `user_settings` or `onboarding_sessions` table: settings and state live on `users`.

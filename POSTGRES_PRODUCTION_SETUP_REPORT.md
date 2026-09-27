# Azkar Bot — PostgreSQL production setup report

Date: 2026-09-27. Secrets appear only as `[REDACTED]`; the database URL only as "configured".

## Outcome

Production (`azkar-telegram-bot.vercel.app`) now persists to a fresh, dedicated Neon PostgreSQL
database. No SQLite data was migrated (intentional fresh start). Local development still uses SQLite.

## Resources

| Item | Value |
| --- | --- |
| Neon database | `azkar-bot-production`, Free plan (0.5 GB, 100 CU-hours), region iad1, Neon Auth off |
| Connected to | Vercel project `azkar-telegram-bot`, Production environment only, no preview branches |
| DATABASE_URL | configured (pooled, injected by the Neon integration) |
| Cost | none; no paid resource activated |

## Vercel environment changes

- Added (Production): `DB_CONNECTION=pgsql`, `DB_SSLMODE=require`.
- Changed: existing `DB_CONNECTION` narrowed from "Production and Preview" to Preview only (value `sqlite`).
- Added by the Neon integration (Production): `DATABASE_URL`, `DATABASE_URL_UNPOOLED`, `DATABASE_PG*`, `DATABASE_POSTGRES_*`, `DATABASE_NEON_PROJECT_ID`.
- Unchanged: `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `APP_KEY` (added 21h ago), `CRON_SECRET` (added 2h ago), and all other variables.

## Code changes (commits `2e79b7b`, `3f140d1` on `main`)

- `Dockerfile`: `pdo_pgsql` added (image keeps `pdo_mysql`, `pdo_sqlite`).
- `config/database.php` (pgsql only): URL falls back to `DATABASE_URL`; `sslmode` from `DB_SSLMODE`;
  `PDO::PGSQL_ATTR_DISABLE_PREPARES` for Neon's PgBouncer pooler.
- `docker/entrypoint.sh`: production fail-fast without pgsql/URL; waits for PostgreSQL; logs a safe DB identity line.
- New `adhkar:db-status` command and secret-protected `GET /internal/health` (driver, db name, masked host, counts).
- `tests/Feature/PostgresBehaviourTest.php` (pgsql-only behaviour tests), `.env.example` comments,
  `docs/PRODUCTION_DATABASE.md`.
- No application logic changed; no migration rewritten.

## Incident during rollout (resolved)

The first production deploy (`2e79b7b`, 14:27 local) connected to Neon but failed in the first
migration with `SQLSTATE[25P02] current transaction is aborted`; every request returned 500.

- Mitigation: Instant Rollback to the previous deployment (`f45cc10`) at ~14:29. Downtime about 3 minutes;
  cron-job.org calls in that window returned 500 and succeeded again afterwards.
- Root cause: reproduced locally with PgBouncer in transaction mode. PDO issues `DEALLOCATE pdo_stmt_N`
  for named statements that PgBouncer has renamed; the failed `DEALLOCATE` silently aborts the transaction.
  This would also have affected runtime transactions (reminder dispatch), not only migrations.
- Fix: `3f140d1` disables PDO named prepares for pgsql. Verified through local PgBouncer: 9/9 migrations,
  idempotent sync, 75/75 tests, production-like container start. The deployment was started via its
  deployment URL (migrations ran on Neon) before being promoted, which also undid the rollback.
- The failed attempt left no partial schema (transactional DDL rolled back; only the empty `migrations`
  table remained and was reused).

## Verification

| Check | Result |
| --- | --- |
| pdo_pgsql in image | present |
| Local throwaway Postgres 17: migrations from zero | 9/9 |
| `adhkar:sync` twice | 49 created, then 49 unchanged |
| Constraints / indexes | all PK, unique (incl. `reminder_logs_unique_day`), FK and `users_reminders_*` indexes present |
| Test suite on pgsql (direct and via PgBouncer) | 75 passed |
| Test suite on SQLite | 69 passed, 6 skipped (pgsql-only) |
| Duplicate reminder day on pgsql | UniqueConstraintViolationException 23505 inside savepoint, count stays 1 (test DB only) |
| Row lock across connections | second `FOR UPDATE NOWAIT` gets 55P03 (test DB only) |
| Fail-fast (production + sqlite / no URL / unset) | container exits 1 with a clear message |
| Preview-style start on SQLite | boots, `/up` 200 |
| Production `/internal/health` | driver `pgsql`, database `neondb`, host `*.neon.tech`, 9 migrations, users 0, adhkar 5/49/67 |
| `/up` | 200 |
| `/internal/cron` | 403 without auth, 200 with auth; cron-job.org POSTs every minute and gets 200 |
| `/internal/health` without auth | 403 |
| Telegram webhook | unchanged URL (`/telegram/webhook/[REDACTED]`), secret matches, pending 0, no last error |
| Second deployment (`dpl_qiffsZxz…`) | "Nothing to migrate", sync 49 unchanged, counts preserved (sessions 2, cache row retained) |
| Secret fingerprints (sha256 prefix + length) | identical to baseline for all four secrets |

## Not yet done (needs the bot owner)

- Live Telegram flows on the new database: `/start`, onboarding, settings change, pause/resume, and a
  real reminder a few minutes ahead. Expected evidence afterwards: `/internal/health` shows `users` ≥ 1,
  `telegram_updates` growing, `reminder_logs` +1 after the reminder, `cache_locks` back to 0.
- Laptop-off test: turn the laptop off (or stop the local compose stack) and confirm a reminder still arrives.
- cron-job.org dashboard was not opened (not logged in); its behaviour was verified from Vercel logs instead.
- `VERCEL_ENV` presence is expected (system variables are enabled in the project) but was not printed.

## Notes

- A local docker-compose stack (`azkarbot-*`, MySQL) is running with the same bot token. It cannot receive
  updates while the webhook is set (poller gets 409), and it has 1 user with reminders disabled and no reminder
  logs in 24h, so it sends nothing. Stop it (`docker compose stop`) before the laptop-off test to remove any doubt.
- Do not run `php artisan db:show` against Neon; it prints the full connection URL including the password.

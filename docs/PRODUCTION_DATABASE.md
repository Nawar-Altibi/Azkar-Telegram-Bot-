# Production database

Production runs on **Neon PostgreSQL** (Free plan, region `iad1`, same as the Vercel function).
Local development keeps using **SQLite** (`database/database.sqlite`). The two never share data.

## How production finds the database

| Variable | Scope in Vercel | Source |
| --- | --- | --- |
| `DB_CONNECTION=pgsql` | Production only | set manually |
| `DB_SSLMODE=require` | Production only | set manually |
| `DATABASE_URL` (pooled) | Production only | injected by the Neon integration |
| `DATABASE_URL_UNPOOLED` and the other `DATABASE_*` / `PG*` vars | Production only | injected by the Neon integration, unused by the app |
| `DB_CONNECTION=sqlite` | Preview only | set manually; previews have no Neon access |

`config/database.php` reads `DB_URL` first and falls back to `DATABASE_URL`, so the Neon
integration value is used as-is. Never copy the connection string into Git, Markdown, logs or `.env.example`.

## Container start (`docker/entrypoint.sh`)

1. `require_production_database`: when `VERCEL_ENV=production`, the container exits with code 1
   unless `DB_CONNECTION=pgsql` and `DB_URL` or `DATABASE_URL` is set. Production never falls back to SQLite.
2. `adhkar:db-status --wait=60`: waits for PostgreSQL and logs one safe line:
   `Database driver: pgsql, database: neondb, host: *.neon.tech`.
3. `migrate --force`, then `adhkar:sync` (idempotent), then `adhkar:db-status` again.
4. `config:cache`, `route:cache`, `view:cache`.

## Neon pooler (PgBouncer) requirement

`DATABASE_URL` goes through Neon's PgBouncer in transaction mode. PDO's named prepared statements
break there: PgBouncer renames them, PDO's `DEALLOCATE` then fails silently and Postgres aborts the
open transaction (`SQLSTATE[25P02] current transaction is aborted`). The first production deploy
failed exactly this way inside the first migration.

The pgsql connection therefore sets `PDO::PGSQL_ATTR_DISABLE_PREPARES` (on by default, override with
`DB_PGSQL_DISABLE_PREPARES=false` only for a direct, non-pooled connection). Parameters are still
bound server-side; only named statements are avoided. The full test suite passes through a local
PgBouncer in transaction mode with this setting.

## Diagnostics

- `GET /internal/health` with `Authorization: Bearer <CRON_SECRET>` returns driver, database name,
  masked host, applied migration count and row counts per table. No row contents, no credentials.
  Without the header it returns 403.
- `php artisan adhkar:db-status` prints the same identity line. On failure it prints only the
  exception class and SQLSTATE, never the message (which can echo the DSN).

**Do not run `php artisan db:show` against Neon or production.** It prints the full connection URL,
including the password.

## Forbidden against the production database

`migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`, and running the test suite with
`DB_URL` pointed at Neon (tests use `RefreshDatabase`). Destructive tests belong in a throwaway
local Postgres container.

## Running tests on PostgreSQL locally

```powershell
docker run -d --name azkar-pg-test -p 55432:5432 -e POSTGRES_USER=azkar -e POSTGRES_PASSWORD=<throwaway> -e POSTGRES_DB=azkar_test postgres:17
$env:DB_CONNECTION='pgsql'; $env:DB_URL='pgsql://azkar:<throwaway>@127.0.0.1:55432/azkar_test'; $env:DB_SSLMODE='disable'
php artisan test
Remove-Item Env:DB_CONNECTION,Env:DB_URL,Env:DB_SSLMODE; docker rm -f azkar-pg-test
```

`tests/Feature/PostgresBehaviourTest.php` only runs on pgsql and covers column types, JSON/boolean/
timestamp round-trips, foreign keys, the `reminder_logs_unique_day` constraint inside a savepoint,
rollback, and `lockForUpdate` across two connections.

## Known limitation

Every cold start runs `migrate`. With the current schema that is a no-op. When a new migration ships,
two containers starting at the same moment could both try to apply it; PostgreSQL DDL is
transactional, so the loser fails cleanly, that single request returns 500, and the next start is fine.

## Rollback

Vercel Instant Rollback to a pre-PostgreSQL deployment restores its own build-time environment
(SQLite, empty on each cold start). Data written to Neon stays in Neon and is picked up again on the
next PostgreSQL deployment. After an Instant Rollback, new production builds are not auto-promoted;
promote them manually.

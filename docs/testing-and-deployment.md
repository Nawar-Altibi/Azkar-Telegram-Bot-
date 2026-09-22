# Testing and deployment

## Tests

```bash
php artisan test
```

Covered:

- Next-reminder calculation (midnight, DST, invalid time)
- Content sync idempotency, version bump, exclusion of the warning section
- Message chunking and version snapshots
- Webhook 403/400, persist-then-200, duplicate `update_id`
- Onboarding, settings, pause/resume
- Atomic claim, duplicate scheduler tick, stale recovery exhaustion
- Send job snapshot immutability and bot-blocked handling
- Additional section isolation and completion idempotency
- Unique DB constraints
- Deploy preflight (`adhkar:smoke`) and `GET /up`

Telegram HTTP is always faked.

## Production deploy order

1. Copy `deploy/production.env.example` to `.env` and fill secrets. `APP_URL` must be HTTPS.
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan migrate --force`
4. `php artisan adhkar:sync`
5. `php artisan config:cache && php artisan route:cache`
6. Install cron from `deploy/crontab.example` (one `schedule:run` every minute).
7. Start the queue worker (Supervisor: `deploy/supervisor-azkar-worker.conf`, or systemd: `deploy/azkar-worker.service`).
8. `php artisan queue:restart`
9. Confirm `GET /up` and `php artisan adhkar:health`
10. `php artisan telegram:webhook:set`
11. `php artisan adhkar:smoke --live`
12. Send a real `/start` from a Telegram account and confirm a reply arrives. Webhook `200` is not enough — the queue worker must be running.

Cron (one entry):

```
* * * * * cd /path/to/azkar && php artisan schedule:run >> /dev/null 2>&1
```

That tick drives `adhkar:dispatch-due-reminders` every minute and `adhkar:recover-stale` every five minutes.

Nginx TLS example: `deploy/nginx.conf.example` (PHP 8.2 FPM).

## Live smoke test

Automated tests never call the real Bot API. After deploy:

```bash
php artisan adhkar:smoke --live
```

Then in Telegram:

1. Message `/start` to the bot.
2. Confirm the Arabic onboarding reply arrives.
3. Complete timezone, times, and mode.
4. Confirm `/today` returns adhkar.

If `/start` is accepted (`200`) but no reply appears, the worker is down. Run `php artisan adhkar:recover-stale` and `php artisan queue:work`.

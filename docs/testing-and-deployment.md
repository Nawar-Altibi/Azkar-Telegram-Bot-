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

Telegram HTTP is always faked.

## Production deploy order

1. `composer install --no-dev --optimize-autoloader`
2. `php artisan migrate --force`
3. `php artisan adhkar:sync`
4. `php artisan config:cache && php artisan route:cache`
5. `php artisan queue:restart`
6. Confirm `GET /up`
7. `php artisan telegram:webhook:set`
8. Confirm a real `/start` reply (queue worker must be running — webhook 200 is not enough)

Cron (one entry):

```
* * * * * cd /path/to/azkar && php artisan schedule:run >> /dev/null 2>&1
```

Supervisor example: `deploy/supervisor-azkar-worker.conf`.

Webhook 200 only means the update was stored. The worker and `adhkar:recover-stale` are required for replies.

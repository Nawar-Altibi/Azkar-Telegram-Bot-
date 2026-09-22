# AZKAR BOT

Telegram bot for authentic Islamic adhkar (morning, evening, optional additional). Arabic-first, calm, no leaderboards.

## Stack

- PHP `^8.2` (developed on 8.2.12)
- Laravel `^11.31`
- SQLite (local/tests) / MySQL 8 (production)
- Database queue + Laravel scheduler

See [docs/implementation-plan.md](docs/implementation-plan.md).

## Setup

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan adhkar:sync
```

Set `TELEGRAM_BOT_TOKEN` and `TELEGRAM_WEBHOOK_SECRET` in `.env`. Never commit them.

### Local development

```bash
php artisan telegram:poll
```

Or expose HTTPS and run:

```bash
php artisan serve
php artisan queue:work --tries=3 --backoff=5,30,120
php artisan telegram:webhook:set
php artisan adhkar:smoke --live
```

Production files: `deploy/` (cron, Supervisor, systemd, nginx, env example). See [docs/testing-and-deployment.md](docs/testing-and-deployment.md). After webhook registration, send `/start` in Telegram and confirm a reply. Webhook `200` is not enough — the queue worker must be running.

## Content

Editorial sources: `resources/adhkar/*.md` (copied from `app adhkar/`). Structured seed: `database/data/adhkar/*.php`. Sync with `php artisan adhkar:sync`. Do not invent virtues, counts, or timings.

## Tests

```bash
php artisan test
```

# AZKAR BOT

Telegram bot for authentic Islamic adhkar (morning, evening, optional additional, plus manual bedtime and waking collections without reminders). Arabic-first, calm, no leaderboards.

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

Optionally set `TELEGRAM_ADMIN_CHAT_ID` to your own Telegram chat id (send `/start` to [@userinfobot](https://t.me/userinfobot) to get it). User feedback is copied to that chat, your replies to it reach the user, and `/stats` and the weekly report answer only there. Send `/start` to the bot from that account once, or Telegram will not let the bot message you.

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

## Docker

Compose starts MySQL, the webhook app, the queue worker, and the scheduler. `.env` must already contain `APP_KEY`, `TELEGRAM_BOT_TOKEN`, and `TELEGRAM_WEBHOOK_SECRET`.

```bash
docker compose up -d --build
```

The app listens on http://localhost:8080 (`GET /up` is the health check). The database password defaults to `azkar`; set `MYSQL_PASSWORD` before sharing the machine. Telegram can reach the webhook only through a public HTTPS `APP_URL` or `TELEGRAM_WEBHOOK_URL`. After the stack is healthy:

```bash
docker compose exec web php artisan telegram:webhook:set
```

## Content

Editorial sources: `resources/adhkar/*.md`. Research and the Hisn al-Muslim PDF behind them: `docs/sources/`. Structured seed: `database/data/adhkar/*.php`. Sync with `php artisan adhkar:sync`. Do not invent virtues, counts, or timings.

## Tests

```bash
php artisan test
```

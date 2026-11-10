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

Editorial sources: `resources/adhkar/*.md` (copied from `app adhkar/`). Structured seed: `database/data/adhkar/*.php`. Sync with `php artisan adhkar:sync`. Do not invent virtues, counts, or timings.

## Tests

```bash
php artisan test
```


## Admin-only broadcast messages

Set `TELEGRAM_ADMIN_IDS` to your numeric Telegram user ID (or comma-separated IDs) in the **production environment**. It is empty by default, so broadcasting is disabled until you configure it. Never put bot tokens or database credentials in the repository.

Send a **private message** to your bot:

```text
/broadcast السلام عليكم ورحمة الله وبركاته 🌿
نعتذر عن توقف بوت الأذكار مؤقتاً بسبب مشكلة تقنية.
نعمل على إصلاحه بإذن الله.
```

Only the specified Telegram user IDs can use this command in a private chat. Ordinary users get the same response as for an unknown command. The text after the command (including multiple lines) is treated as plain text, so arbitrary HTML is safely escaped before delivery. Telegram messages are limited to 4096 characters.

The command records a broadcast and acknowledges how many **currently registered users** were targeted. Delivery is **not instantaneous**: the existing authenticated `/internal/cron` endpoint sends up to `TELEGRAM_BROADCAST_BATCH_SIZE` users per tick (default 10), with a short pause between Telegram calls. It includes users who have paused reminders, because this is a service notice, not a reminder. Later users are not automatically included. The sender's own account also receives the broadcast if registered.

Apply migrations before using this command (`php artisan migrate --force` in production). Check the `broadcasts` table for target/sent/failed counts and `broadcast_deliveries` for per-user delivery outcomes. A Telegram rejection is recorded as `failed` (except HTTP 429, which is retried after Telegram's cooldown); an uncertain network outcome is recorded as `unconfirmed`. To prevent duplicate announcements, those outbound attempts are **not** retried automatically. Each incoming Telegram update ID can create only one broadcast.

**If the bot server or cron endpoint is down, /broadcast cannot be received or delivered.** Restore the deployment, database, and scheduled cron call first. The GitHub code change alone does not activate the command or send a message.

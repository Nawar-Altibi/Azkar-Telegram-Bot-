# Bedtime + Waking Readiness Audit

> Inspection, readiness and planning only. Performed 2026-10-03 (UTC+3) against branch `main`, `HEAD = 0c6677b` (= `origin/main`), with the working tree as found.
> No application code, migration, test, content, configuration, `.env`, or database was modified. Nothing was staged, committed, reverted, or restored. The only file created in the repository is this report.
>
> Labels:
> - **FACT**: read directly from the cited file, git object, command output, or HTTP response.
> - **INFERENCE**: follows from facts but was not directly observed.
> - **UNKNOWN**: not established by the evidence available.
>
> Secrets: `.env` was not opened. No token, password, key or connection URL appears in this report. The local Telegram poller's terminal output contains the bot token in an error URL; it is deliberately not reproduced here (see §25).

---

## 1. Repository State

### 1.1 Commands run (read-only)

`git status --short`, `git status`, `git branch --show-current`, `git log -n 15`, `git diff`, `git diff --cached`, `git diff --stat`, `git diff --cached --stat`, `git ls-files`, `git ls-files --deleted`, `git reflog -n 20`, `git stash list`, `git branch -a`, `git rev-parse HEAD origin/main`, `git check-ignore -v database/database.sqlite`, `git log -S/--grep` searches.

### 1.2 Results (FACT)

| Item | Value |
|---|---|
| Branch | `main`, up to date with `origin/main` |
| HEAD | `0c6677b` 2026-10-01 17:00 "feat: typeset the wird PDF in Amiri with a cleaner card layout" (author `nawar`) |
| `origin/main` | `0c6677b` (identical) |
| Other branches | `feat/postgres-production` (local + remote) at `3083c62`, already fast-forwarded into `main` |
| Stash | empty |
| Remote | `github.com/Nawar-Altibi/Azkar-Telegram-Bot-` |
| Staged changes (`git diff --cached`) | **none** |
| Unstaged changes | ` D POSTGRES_PRODUCTION_SETUP_REPORT.md` (89 lines removed), ` M app/Services/ReminderDispatchService.php` (+2 / −1), ` M tests/Feature/ReminderDispatchTest.php` (+20) |
| Untracked | `docs/current-bot-audit.md` (prior audit), and now this file |
| Ignored but present | `.env`, `.phpunit.result.cache`, `database/database.sqlite` (ignored by `database/.gitignore: *.sqlite*`), `vendor/`, `storage/logs/*` |
| Tracked files | 176 (`git ls-files`) |

Recent history (FACT, `git log`):

```
0c6677b 2026-10-01 feat: typeset the wird PDF in Amiri with a cleaner card layout
8ff1f9c 2026-10-01 fix: label the salawat group with the salawat sign and keep typed times left to right
8ac1ce7 2026-10-01 feat: choose short or full per reading, navigate in place, send the wird as PDF
41c3e6d 2026-10-01 feat: report why a typed reminder time is invalid
d8406c4 2026-10-01 build: add mPDF and the gd extension for wird PDFs
c1bca65 2026-10-01 perf: run the cron tick in-process instead of via schedule:run
3083c62 2026-09-27 docs: document Neon PostgreSQL production setup and rollout report
3f140d1 2026-09-27 fix: disable PDO named prepares for pgsql behind Neon's PgBouncer pooler
2e79b7b 2026-09-27 feat: move production persistence to PostgreSQL
f45cc10 2026-09-27 feat: run reminders from a protected cron endpoint
6378677 2026-09-26 Pushed docker
6baaa57 2026-09-26 chore: run the bot in Docker with web, queue, and scheduler
```

### 1.3 Runtime state observed on this machine (FACT)

Three local processes have been running since 2026-10-03 12:11:19 local (09:11 UTC):

| Process | Command |
|---|---|
| Telegram poller | `php artisan telegram:poll` (output shows repeated `cURL error 28: SSL connection timeout` on `getUpdates`) |
| Queue worker | `php artisan queue:work --tries=3` (processed `SendAdhkarReminderJob` at 09:12 UTC) |
| Scheduler | `php artisan schedule:work` |

They use the local SQLite file. `database/database.sqlite` was last written at 12:26 local during this audit, so it is live data and was not touched (only one read-only, aggregate-only query, see §2.4).

### 1.4 Cross-check of `docs/current-bot-audit.md` (prior report)

The prior report was treated as a claim set and checked against the code. The areas below were re-verified and are **CURRENT** unless listed as differing: architecture flow (§2), content pipeline and item fields (§5), the five collections and counts 9/20, 9/19, 10 active (§6), short/full membership and phases `s`/`a`/`f` (§7), the reading engine (§8), `/today` (§9), additional groups and single-item view (§10), the reminder flow, Start/Done and stale recovery (§11.1–11.4), the database schema (§12), the callback inventory (§13.1, §13.3), and the coupling list (§20, all 34 entries still present in code).

Differences:

**1. Working-tree state**
- OLD REPORT: "The only pre-existing working-tree change is a staged deletion of `POSTGRES_PRODUCTION_SETUP_REPORT.md`."
- CURRENT CODE: the deletion is **unstaged** (`git diff --cached` empty). Two more unstaged modifications exist (§2). The old report's §11.3 note acknowledges them.
- STATUS: **STALE** (the "staged" claim was incorrect when written; see §4).

**2. Processor size**
- OLD REPORT: "`TelegramUpdateProcessor.php` (1,087 lines)".
- CURRENT CODE: 1,333 total lines; 1,087 non-blank; unchanged since HEAD.
- STATUS: **UNCLEAR** (the number is the non-blank count, not total lines).

**3. Test execution**
- OLD REPORT: "Tests were **not executed**."
- CURRENT CODE: executed in this audit: 125 passed, 6 skipped (§18).
- STATUS: **STALE**.

**4. Test inventory**
- OLD REPORT: "17 test files, 117 test methods"; `ReminderDispatchTest` (15).
- CURRENT CODE: 16 test classes + `TestCase.php`; 119 methods in the working tree (118 at HEAD); `ReminderDispatchTest` has 16 (15 at HEAD). PHPUnit counts 131 cases.
- STATUS: **STALE** (an off-by-one at HEAD plus the new working-tree test).

**5. Today-PDF coverage**
- OLD REPORT: "Not covered by any test … `sv:t:` (today PDF) path".
- CURRENT CODE: `tests/Feature/ReminderReadingTest.php:417–418` dispatches `sv:t:e:a` (in `test_pdf_is_uploaded_once_then_resent_by_file_id`).
- STATUS: **STALE** (incorrect).

**6. Live database**
- OLD REPORT: "Which DB is live: UNKNOWN".
- CURRENT CODE: still not determinable. New evidence: Vercel answers `DEPLOYMENT_DISABLED`, and a local SQLite stack is actively sending (§3).
- STATUS: **CURRENT**, with new evidence.

**7. Catch-up behaviour (§11.3)**
- OLD REPORT: HEAD sends one nudge per missed day per tick; the working tree may change this.
- CURRENT CODE: confirmed in HEAD and observed empirically (§2.4). The working tree changes it (§2.3).
- STATUS: **CURRENT** for HEAD, **STALE** for the working tree.

**8. Vercel build**
- OLD REPORT: "how the Docker image is built/deployed on Vercel is UNKNOWN".
- CURRENT CODE: still no `vercel.json`. The entrypoint has `VERCEL` branches.
- STATUS: **CURRENT**.

**9. Blockers**
- OLD REPORT: "Blocker: none found … (Tests were not executed.)"
- CURRENT CODE: tests pass. An operational blocker exists outside the code: the production deployment is disabled (§3).
- STATUS: **STALE** (operational context changed).

---

## 2. Unexpected Working-Tree Changes

### 2.1 Staged or unstaged?

**Unstaged only** (FACT). `git diff --cached` is empty; `git status` lists both files under "Changes not staged for commit".

### 2.2 Exact change (FACT, `git diff`)

`app/Services/ReminderDispatchService.php`, inside `claim()`:

```diff
+                // Schedule from now, not from $dueAt, so a long outage yields one late reminder instead of one per missed day.
                 $next = ReminderScheduleCalculator::calculateNext(
                     $user->timezone,
                     (string) $localTime,
-                    $dueAt,
+                    $dueAt->copy()->max(now()),
                 );
```

`tests/Feature/ReminderDispatchTest.php`: one new test, `test_long_outage_sends_one_late_reminder_instead_of_one_per_missed_day`. It creates a user with `next_morning_at = now()->subDays(10)` and runs `adhkar:dispatch-due-reminders` twice. It asserts one `reminder_logs` row, one dispatched `SendAdhkarReminderJob`, and a future `next_morning_at`. Git warns this file has CRLF line endings in the working copy.

### 2.3 Behaviour change

`ReminderScheduleCalculator::calculateNext($tz, $time, $after)` returns the first local occurrence of `$time` strictly after `$after` (today if still ahead, else tomorrow). FACT.

| | HEAD (`0c6677b`) | Working tree |
|---|---|---|
| `after` passed to `calculateNext` | the stored past `$dueAt` | `max($dueAt, now())` |
| User whose `next_*_at` is N days in the past | Each tick claims the oldest missed day and advances `next_*_at` by one day, which is still in the past. Every scheduled-local-date differs, so `reminder_logs_unique_day` does not stop it. Result: one nudge per missed day per type, one per tick, until caught up. | The first claim moves `next_*_at` into the future. Result: **one** late nudge per type, then the normal schedule. |
| Normal on-time case (`dueAt` ≈ now) | next = tomorrow at the same local time | identical (`now` ≥ `dueAt` by seconds; the same next occurrence) |
| `scheduled_local_date` / `scheduled_for` of the late log | the old due date | **unchanged**: still the old due date |

Things the change does **not** alter (FACT, code unchanged): the unique key, `SendAdhkarReminderJob` retries, statuses, stale recovery, Start/Done validation. The one late nudge still carries the old `scheduled_local_date`. If that date is not the user's current local day, pressing Start answers `bot.reminder_expired` (`TelegramUpdateProcessor::isCurrentLocalDay`). There is still no lateness cut-off in the send job.

Classification against the requested list:

| Concern | Affected? |
|---|---|
| Missed reminders | **Yes**: collapses N missed days into one late nudge per type |
| Rescheduling / next-run calculation | **Yes**: `next_*_at` is computed from now instead of from the stale due time |
| Duplicate prevention | Indirectly. The unique key is unchanged; the burst of distinct-day logs no longer occurs. |
| Retry behaviour | No |
| Reminder status | No |

### 2.4 Empirical confirmation in local data (FACT, read-only aggregate query)

The local SQLite was opened with `PDO::SQLITE_OPEN_READONLY`; only counts/grouped dates were selected (script outside the repository). `reminder_logs` rows created today:

| Created (UTC) | Morning log for local date | Evening log for local date | Status |
|---|---|---|---|
| 09:12 | 2026-09-29 | 2026-09-28 | sent |
| 09:13 | 2026-09-30 | 2026-09-29 | sent |
| 09:14 | 2026-10-01 | 2026-09-30 | sent |
| 09:15 | 2026-10-02 | 2026-10-01 | sent |
| 09:16 | 2026-10-03 | 2026-10-02 | sent |

That is 10 real nudges in 5 minutes, which is exactly the HEAD catch-up behaviour described above. The previous log before the burst was created on 2026-09-28. The burst stops at 09:16 UTC = 12:16 local. File modification times: `tests/Feature/ReminderDispatchTest.php` 12:16:25, `app/Services/ReminderDispatchService.php` 12:16:31 (local, 2026-10-03).

### 2.5 Which commit the prior documentation described

`docs/current-bot-audit.md` states it inspected commit `0c6677b`, the current HEAD (FACT, its header). Its §11.3 describes HEAD's catch-up behaviour and adds a note that the uncommitted change appeared during that audit.

### 2.6 History and documentation search

- `git log --all -S "max(now())"`, `--grep=outage`, `--grep=missed`: **no commit** (FACT).
- Commits touching these files: `d17181f`, `b48eceb`, `c1bca65`, `3083c62` (report only); none contains this change (FACT).
- Docs: the only repository references are the new comment, the new test name, an unrelated comment in `tests/Feature/SchedulerCronTest.php:362`, and the prior audit's §11.3 note (FACT, `git grep`).
- The previous audit session's transcript shows that agent wrote only `docs/current-bot-audit.md` (and one temp file outside the repo); it never edited these two files (FACT).

### 2.7 Authorship

**UNKNOWN — repository history does not establish who introduced the working-tree change.**

INFERENCE (timing only): the change was written about 5 minutes after the local stack started, in the same minute the catch-up burst ended. That makes it very likely a deliberate reaction to the observed burst, made by a person or another tool outside the prior audit session. Behaviour check: the full suite passes with the change (§18). INFERENCE: the new test would fail on HEAD (the second dispatch would claim the next missed day). It was not run against HEAD, because that would require checking out or reverting.

---

## 3. Production Deployment State

### 3.1 Evidence collected

- Files: `Dockerfile`, `docker-compose.yml`, `docker/entrypoint.sh`, `docker/nginx.conf`, `docker/php*.{conf,ini}`, `deploy/*` (nginx, supervisor, systemd, crontab, `production.env.example`), `.env.example`, `config/database.php`, `composer.json` scripts, `README.md`, `docs/PRODUCTION_DATABASE.md`, `docs/testing-and-deployment.md`, `POSTGRES_PRODUCTION_SETUP_REPORT.md` (from `HEAD`).
- Absent (FACT): `vercel.json`, `.vercel/`, `api/`, `Procfile`, `.github/` (no CI), any GitLab CI file; and no Azure configuration anywhere. The only `azure` string is a suggested package in `composer.lock`.
- History: no `vercel.json` ever existed (`git log --all -- vercel.json` empty). The only commits mentioning Vercel/Neon/MySQL are `6baaa57`, `2e79b7b`, `3f140d1`, `3083c62`.
- Live check: two unauthenticated GET requests (read-only):

| URL | Status | Response header |
|---|---|---|
| `https://azkar-telegram-bot.vercel.app/up` | **402** | `Server: Vercel`, `X-Vercel-Error: DEPLOYMENT_DISABLED` |
| `https://azkar-telegram-bot.vercel.app/internal/health` | **402** | `Server: Vercel`, `X-Vercel-Error: DEPLOYMENT_DISABLED` |

The response comes from Vercel's edge, not Laravel. Laravel would answer `/up` with 200 and `/internal/health` without a secret with 403.

### 3.2 Determination

| Question | Evidence | Conclusion | Confidence |
|---|---|---|---|
| A. What architectures does the repository support? | `deploy/` (nginx + php-fpm, Supervisor/systemd worker, `schedule:run` crontab, MySQL env example); `docker-compose.yml` (MySQL 8.4 + web/queue/scheduler roles); `docker/entrypoint.sh` `VERCEL`/`VERCEL_ENV` branches (sync queue, stderr logs, pgsql required); `telegram:poll` + SQLite for local | Four paths: classic Linux server (MySQL), Docker Compose (MySQL), Vercel container (PostgreSQL/Neon, external cron), local polling (SQLite) | High |
| B. What does the latest code target? | Last deployment-related commits: `f45cc10` protected `/internal/cron`; `2e79b7b` pgsql + production fail-fast; `3f140d1` Neon PgBouncer fix; `c1bca65` in-process cron tick "for cron-job.org"; `config/adhkar.php` claim budget sized for cron-job.org | Vercel container + Neon PostgreSQL + external cron calling `POST /internal/cron` | High |
| C. What do production docs claim? | `docs/PRODUCTION_DATABASE.md`: "Production runs on Neon PostgreSQL … same as the Vercel function". `README.md:9`: "SQLite (local/tests) / MySQL 8 (production)". `docs/testing-and-deployment.md`: classic server steps. Deleted report: production on `azkar-telegram-bot.vercel.app` + Neon. | Conflicting. The newest doc (`PRODUCTION_DATABASE.md`, 2026-09-27) says Vercel + Neon; README's MySQL claim dates from the original scaffold | High (that they conflict) |
| D. Evidence of an actual live deployment? | The deleted report (HEAD) records a live Vercel deployment on 2026-09-27 with `/up` 200, `/internal/health` showing `pgsql`/`neondb`, and cron-job.org POSTs getting 200. Today the same domain returns `402 DEPLOYMENT_DISABLED`. | A Vercel production deployment existed; **it is currently disabled** on Vercel and serves no requests | High |
| E. Can the endpoint be checked without credentials? | `/up` is public by design; `/internal/health` needs `CRON_SECRET` | Yes for `/up`; health details need the secret (not used) | High |
| F. Public endpoint inspected | Both return 402 `DEPLOYMENT_DISABLED` | Vercel itself refuses to serve the deployment. Reason (billing, limits, manual pause) is not visible without account access | High (state) / Unknown (reason) |
| G. Can the production DB be established from repo evidence? | Entrypoint refuses to start when `VERCEL_ENV=production` unless `DB_CONNECTION=pgsql` and `DB_URL`/`DATABASE_URL`; docs + report name Neon | For the Vercel deployment: PostgreSQL (Neon) by code enforcement + docs. Whether that Neon database still exists or holds data is not verifiable | Medium-High |
| H. Current production DB | Vercel deployment disabled. On this machine, a local poller/worker/scheduler is running against local SQLite and sent 10 real Telegram nudges today (§2.4). Whether the Telegram webhook still points to Vercel is UNKNOWN; checking it requires the bot token. | **UNKNOWN** which instance counts as "production". The documented production DB is PostgreSQL (Neon), on a deployment that is currently disabled. The only active bot instance observed is local SQLite. | Medium |

Additional observations:
- The local `users` table has 1 row, and `telegram_updates` has 0 rows. `telegram:poll` calls the processor directly and never writes `telegram_updates` (FACT, `PollTelegramCommand`).
- INFERENCE: if a webhook is still registered, Telegram rejects `getUpdates`, so the local poller cannot receive user messages, while the local scheduler/worker can still **send** reminders with the same bot token. The observed poller errors are SSL timeouts, not 409, so this remains UNKNOWN.
- External cron (cron-job.org) configuration is not in the repository (UNKNOWN).

---

## 4. POSTGRES Production Report Investigation

| Question | Finding |
|---|---|
| Tracked? | Yes. Added in `3083c62` (2026-09-27 14:55, "docs: document Neon PostgreSQL production setup and rollout report"); still in `HEAD` (FACT). |
| Staged? | **No.** It is an **unstaged** working-tree deletion (` D`). `git diff --cached` is empty (FACT). The prior audit's claim "staged deletion" is wrong. INFERENCE: that claim came from a status snapshot whose first line had lost its leading space (`" D …"` read as `"D …"`). |
| What HEAD contains | An 89-line report: outcome (production on `azkar-telegram-bot.vercel.app` moved to a fresh Neon database, no SQLite data migrated); resources (Neon Free plan, iad1); Vercel env changes (names only, values redacted); code commits `2e79b7b`, `3f140d1`; rollout incident (`25P02` behind PgBouncer, ~3 min downtime, Instant Rollback, fix); verification table (9/9 migrations, sync 49 items, 75 tests on pgsql, `/up` 200, `/internal/cron` 403/200, webhook unchanged); owner to-dos (live flows, laptop-off test); notes (a local MySQL docker-compose stack with the same token; "do not run `db:show` against Neon"). No secret values (FACT). |
| Newer replacement? | No newer report. `docs/PRODUCTION_DATABASE.md`, from the same commit and edited in `c1bca65`, holds the durable parts: config, entrypoint, pooler, diagnostics, forbidden commands, rollback. The incident timeline, verification table and owner to-do list exist only in the deleted file (FACT). |
| Referenced elsewhere? | Only by `docs/current-bot-audit.md` (FACT, grep). No code, docs index or README links to it. |
| Documents a completed migration? | Yes: production persistence switched to Neon PostgreSQL on 2026-09-27 as a fresh start (FACT, per its content). |
| Contradicts current code? | Not on database configuration. It is point-in-time and partly superseded: "75 tests" (now 131 cases); cron path before `c1bca65` (in-process tick); "local docker-compose MySQL stack running" (today the local stack is `php artisan` processes on SQLite). It also asserts a live Vercel deployment that is now `DEPLOYMENT_DISABLED`. |
| Deletion intentional per git history? | No commit deletes it, and no doc mentions removing it. **UNKNOWN.** The file's deletion time cannot be recovered from git. |
| Safe to leave as-is? | For runtime: yes. Nothing reads the file, and code and the Docker build do not depend on it. For repository hygiene: a future `git commit -a` / `git add -A` would delete it from history going forward. That is a decision for the owner and was not acted on. |

---

## 5. Current Architecture

### 5.1 Telegram update path (FACT)

```
Telegram
  │ POST /telegram/webhook/{secret}                       bootstrap/app.php (route "telegram.webhook")
  ▼
VerifyTelegramWebhookSecret (path secret + X-Telegram-Bot-Api-Secret-Token)  app/Http/Middleware/
throttle:telegram-webhook                                                    AppServiceProvider
  ▼
TelegramWebhookController::__invoke
  ├─ DB::transaction → TelegramUpdate::create(update_id unique, chat_id, payload, status=pending)
  │     duplicate update_id → 200 {"ok":true}, no reprocessing
  └─ ProcessTelegramUpdateJob::dispatch($record->id)
        queue "database" → queue:work   |   QUEUE_CONNECTION=sync when VERCEL is set (inline)
  ▼
ProcessTelegramUpdateJob::handle → status processing → TelegramUpdateProcessor::handle($payload) → processed | failed
  ▼
TelegramUpdateProcessor::handle
  ├─ findOrCreateUser (telegram_id) ; last_interaction_at
  ├─ callback_query → handleCallback → routeCallback($data)   (exact matches + str_starts_with prefixes)
  └─ message.text   → handleMessage → handleCommand | handleReplyKeyboard | handleAwaitingInput | handleUnknownText
  ▼
OnboardingService / UserSettingsService / AdhkarContentService / AdhkarPdfService / KeyboardFactory
  ▼
TelegramClient (sendMessage / editMessageText / answerCallbackQuery / sendDocument / sendChatAction), parse_mode HTML
  └─ show(): edits the pressed message unless the markup has a reply keyboard or the edit fails

Local development: PollTelegramCommand (telegram:poll) → getUpdates → TelegramUpdateProcessor::handle directly
                   (no telegram_updates row, no queue).
```

### 5.2 Scheduled reminder path (FACT)

```
Trigger A (classic/Docker/local): routes/console.php
    Schedule::command('adhkar:dispatch-due-reminders')->everyMinute()->withoutOverlapping()
    Schedule::command('adhkar:recover-stale')->everyFiveMinutes()->withoutOverlapping()
    driven by cron "schedule:run" | docker "scheduler" role loop | local "schedule:work"
Trigger B (Vercel): POST /internal/cron (VerifyCronSecret) → SchedulerCronController → CronTickService::run
    cache lock adhkar:cron-tick (120 s), claim budget 25 s, StaleRecoveryService::runIfDue,
    queue:work --stop-when-empty when queue ≠ sync
  ▼
DispatchDueRemindersCommand → ReminderDispatchService::claimDue → claimDueLogIds(?deadline)
    users WHERE reminders_enabled AND (next_morning_at <= now OR next_evening_at <= now), chunkById(100)
    per user: fresh() → isStillDue(Morning) → claim(Morning) → fresh() → isStillDue(Evening) → claim(Evening)
  ▼
claim(): DB::transaction { ReminderLog::create(pending, collection_key = collectionKey(users.mode ?? Short, type),
                           scheduled_local_date, scheduled_for = dueAt) ; users.next_{type}_at = calculateNext(…) }
         unique violation → null ; else SendAdhkarReminderJob::dispatch(logId)
  ▼
SendAdhkarReminderJob::handle (ShouldBeUnique, tries 3, backoff 5/30/120)
    collection must exist/active → reserveOutboundAttempt (lockForUpdate) → TelegramClient::sendMessage(
    AdhkarContentService::nudgeText(collection_key), KeyboardFactory::reminderNudge(logId, type)) → status sent
  ▼
User presses "مختصر"/"كامل" → reminder:start:{id}:{s|a} → TelegramUpdateProcessor::handleReminderStart
    → isCurrentLocalDay → claimReading (lock, collection_key overwritten from phase, snapshot) → deliverScheduledReading
    → presentReading (scope "log") → … → reminder:done:{id} → handleCompletion
```

### 5.3 `TelegramUpdateProcessor` as a coupling point (FACT)

- Size: **1,333 lines** (1,087 non-blank; the prior audit's "1,087 lines" is the non-blank count), **52 methods**, 6 injected collaborators.
- Responsibilities: user upsert; command routing; reply-keyboard label routing; free-text state machine (timezone/time input); onboarding/settings callbacks; timezone/region/time pickers; pause/resume; additional library (`add:*`, including its own DB queries on `adhkar_items`); reminder Start (row lock, snapshot); Done validation; the whole reading engine (`presentReading`, `readingActions`, `readingCallback`, `saveCallback`); PDF/offline delivery with Telegram `file_id` caching; error recording; the edit-vs-send rule.
- Category/collection-specific logic located here:
  - `routeCallback`: `today:morning` / `today:evening` exact matches → `ReminderType` → `modeChoice('m'|'e')`.
  - `handleTodayReadingNav`: `in_array($period, ['m','e'])` → `ReminderType` → `AdhkarContentService::collectionKey`.
  - `handleOfflineSave` (`sv:t:`): the same `m`/`e` validation and mapping.
  - `handleAdditionalCallback`, `additionalItems`: `where('category', AdhkarCategory::Additional)`.
  - `deliverScheduledReading`: phase from `str_contains($key, '_full')`.
  - `completionProblem`: `in_array($log->reminder_type, [Morning, Evening])`; `handleCompletion`: Evening vs else text.
  - `handleAwaitingInput`, `handleTimeCallback`, `saveChosenTime`, `replyTimeError`, `replyPauseResume`: morning/evening reminder-time handling.
- Conclusion: **yes, it is the central coupling point** for routing and the reader. The reader itself (`presentReading`/`readingActions`) is generic. The callers that resolve a base collection key are not.

---

## 6. Current Adhkar Content Model

### 6.1 Trace (FACT)

```
database/data/adhkar/{morning,evening,additional}.php     PHP arrays (one per item, incl. 'collections' => [keys])
   ▼ php artisan adhkar:sync [--if-changed]               SyncAdhkarContentCommand; also docker/entrypoint.sh on every start
ContentSyncService
   ├─ sourceHash(): sha256 over the 3 data files + ContentSyncService.php itself
   ├─ syncIfChanged(): skip only if cache marker == hash AND isComplete()
   ├─ sync(): DB::transaction { syncCollections() ; foreach dataFiles() → syncItem() }
   │    syncCollections(): updateOrCreate by key from collectionDefinitions() (5 rows), active = true
   │    syncItem(): upsert by item key; hash() of canonical fields → source_hash; content_version++ on change;
   │                pivot sync: for each listed collection key that EXISTS → order = item 'order'
   │                (unknown collection keys are silently skipped)
   └─ isComplete(): all defined collections active; every item key present; active flags; link count = data links
   ▼
adhkar_collections (key unique, category, variant, title, description, active, order)
adhkar_items       (key unique, category, title, arabic_text, repetitions_*, timing, virtue, source_*, hadith_*,
                    narrator, guidance_note, notes, order, active, content_version, source_hash)
adhkar_collection_items (collection_id, item_id, order; unique pair)
   ▼
AdhkarCollection::items()  belongsToMany ... withPivot('order') ->orderByPivot('order')
   ▼
AdhkarContentService::collectionItems($key) (active collection, active items) → itemsForPhase / fullOnlyItems
   ▼
TelegramUpdateProcessor::presentReading → AdhkarContentService::renderCard → formatItem (HTML) → TelegramClient
AdhkarPdfService::build → resources/views/pdf/wird.blade.php (mPDF, Amiri)
```

### 6.2 Collections and items (FACT: data files, `ContentSyncService::collectionDefinitions`; local DB matches)

| Collection key | category | variant | Items (active) | Data file |
|---|---|---|---|---|
| `morning_short` | morning | short | 9 | `morning.php` |
| `morning_full` | morning | full | 20 | `morning.php` |
| `evening_short` | evening | short | 9 | `evening.php` |
| `evening_full` | evening | full | 19 | `evening.php` |
| `additional` | additional | null | 10 (11 rows; `additional.11` `active => false`) | `additional.php` |

Totals: 50 item rows (49 active), 68 pivot links. Local DB: 5 / 50 / 68 (FACT, read-only count).

### 6.3 Conventions (FACT)

| Aspect | Convention |
|---|---|
| Item key | `{category}.{NN}` (e.g. `morning.01`) plus named `morning.salat_nabi`, `evening.salat_nabi`. Unique; the stable identifier used by sync. |
| Collection key | `{category}_{short|full}` for morning/evening; bare `additional` |
| Category values | `morning`, `evening`, `additional` (string(32) column; PHP enum `AdhkarCategory`) |
| Short/full | Membership: an item lists `*_short` and/or `*_full` in `collections`. Every morning/evening item is in `_full`; the first 9 by order are also in `_short`. |
| Sort/order | The item's single `order` integer becomes the pivot `order` in **every** collection it belongs to; reading uses `orderByPivot('order')`. |
| Active | Item `active` (default true); collections forced `active => true` on each sync; queries filter both. Sync never deletes rows and never deactivates items removed from files. |
| Content version | `content_version` +1 when `hash()` changes (arabic_text, repetitions, timing, virtue, source_text, source_name, hadith_number, hadith_grade, narrator, guidance_note, notes, title). Not included in the hash: `category`, `order`, `collections`, `active`. |
| Source fields | `source_text` (not rendered), `source_name` (rendered, appended with `hadith_number`), `hadith_number`, `hadith_grade` (rendered), `narrator` (rendered only in the additional detail view) |
| Repetition | `repetitions_count` (not rendered), `repetitions_label` (rendered "🔁 التكرار") |
| Virtue | `virtue` (rendered as blockquote, expandable when longer than 200 characters) |
| Guidance/context | `timing` (not rendered), `guidance_note` (additional detail view only), `notes` (not rendered) |
| Unused columns in UI | `adhkar_collections.title`, `description`, `order`, `variant` (no read anywhere in `app/`; `->variant` has zero references) |

---

## 7. Generic Collection Readiness

| Question | Answer | Evidence |
|---|---|---|
| A. Can the DB represent arbitrary collection keys? | **Yes.** | `adhkar_collections.key` `string` unique; pivot uses ids. FACT. |
| B. Can the DB represent arbitrary categories? | **Yes** at the schema level (`string(32)` on both tables, no check constraint). | migrations `…000003`, `…000004`. FACT. |
| C. Which enums block this? | `App\Enums\AdhkarCategory` (`morning`, `evening`, `additional`), cast on `AdhkarItem::category` and `AdhkarCollection::category`. Laravel's `setEnumCastableAttribute` calls `AdhkarCategory::from($value)`, so assigning `'bedtime'` throws `ValueError` during `ContentSyncService::sync()`. The whole sync transaction rolls back, and `docker/entrypoint.sh` (`set -eu`) would exit at startup. `AdhkarVariant` (`short`, `full`) is **not** a blocker. `ReminderType` blocks only code paths that build keys from it. | `vendor/.../HasAttributes.php:1228–1255`; enums. FACT. |
| D. Which query filters assume morning/evening/additional? | `TelegramUpdateProcessor::handleAdditionalCallback` (`add:item`) and `additionalItems` filter `category = AdhkarCategory::Additional`. No other category filter exists; the reader selects by collection key. | FACT |
| E. Which sync code has hard-coded lists? | `ContentSyncService::dataFiles()` → `['morning','evening','additional']`; `ContentSyncService::collectionDefinitions()` → 5 literal rows. `sourceFiles()` and `isComplete()` derive from them. | FACT |
| F. Which UI code assumes only current collections? | `KeyboardFactory::todayMenu` (3 fixed buttons); `TelegramUpdateProcessor::routeCallback` (`today:morning`/`today:evening`); `handleTodayReadingNav` and `handleOfflineSave` (`m`/`e`); `KeyboardFactory::mainReplyKeyboard` + `handleReplyKeyboard` (fixed labels); `AdhkarContentService::modeChoiceText(ReminderType)`. | FACT |
| G. Which labels depend on key prefixes? | `AdhkarContentService::sessionHeader` (`str_starts_with($key,'evening')`; `_full`), `wirdTitle` (same), `footerForKey` (`morning…` / `evening…` / default), `nudgeText` (reminders), `AdhkarPdfService::render` `heading` (`evening…`), `AdhkarPdfService::subtitle` (`_full`), `TelegramUpdateProcessor::deliverScheduledReading` (`_full`). | FACT |
| H. Which parts fall back to morning for an unknown key? | `sessionHeader` → "🌅 أذكار الصباح"; `wirdTitle` → "أذكار الصباح"; `AdhkarPdfService::render` heading → "أذكار الصباح"; `nudgeText` → `bot.nudge_morning`; `modeChoiceText` → morning title for any non-Evening type; `readingCallback`/`saveCallback` default period `m`; `handleTodayReadingNav`/`handleOfflineSave` map every non-`e` valid period to Morning; `handleCompletion` text; `KeyboardFactory::reminderNudge` time button; `handleTimeCallback` kind. `footerForKey` falls back to the **additional** text ("هذه أذكار اختيارية، خذ منها ما تيسّر."), not morning. | FACT |

---

## 8. Exact File-Level Impact Map

Scope: add `bedtime` and `waking` as **manually browsable/readable** collections. No reminders. "Conditional" rows depend on which access point (§9) is chosen. That choice is not made here.

| File | Class/Function | Current assumption | Why it must change | Type of change |
|---|---|---|---|---|
| `database/data/adhkar/bedtime.php` (new) | — | does not exist | Source-backed items must exist in the sync input format | content/config only |
| `database/data/adhkar/waking.php` (new) | — | does not exist | same | content/config only |
| `app/Enums/AdhkarCategory.php` | enum | 3 cases | Category cast throws for `bedtime`/`waking` during sync (§7C) | enum |
| `app/Services/ContentSyncService.php` | `dataFiles()` | 3 files | New data files would never be read | sync |
| `app/Services/ContentSyncService.php` | `collectionDefinitions()` | 5 collections | New collections would never be created. Item links to them are silently skipped, so `isComplete()` stays false and a full sync runs on every start. | sync |
| `app/Services/AdhkarContentService.php` | `collectionKey(AdhkarMode, ReminderType)` | key = `ReminderType->value` + variant | There is no `ReminderType` for bedtime/waking. Manual entry points need a base key without going through the reminder enum, or the enum must grow (next rows). | collection lookup |
| `app/Services/AdhkarContentService.php` | `modeChoiceText(ReminderType)` | Evening vs else morning title | Mode-choice screen would title bedtime as morning | label mapping |
| `app/Services/AdhkarContentService.php` | `sessionHeader()` | `evening` prefix else morning | Every reading card header would say "🌅 أذكار الصباح" | label mapping |
| `app/Services/AdhkarContentService.php` | `footerForKey()` | morning/evening prefixes else "additional" text | Last card footer would be the optional-adhkar text | label mapping |
| `app/Services/AdhkarContentService.php` | `wirdTitle()` | `evening` prefix else morning | PDF filename/caption would say أذكار الصباح | label mapping |
| `app/Services/AdhkarContentService.php` | `itemsForPhase`, `fullKey`, `fullOnlyItems`, `collectionItems`, `renderCard`, `formatItem`, `offlineMessages`, `snapshotVersions` | `{x}_short` / `{x}_full` naming | Generic once keys follow the convention | no change required |
| `app/Services/AdhkarPdfService.php` | `render()` `heading` | `evening` prefix else morning | PDF heading would say أذكار الصباح | label mapping |
| `app/Services/AdhkarPdfService.php` | `subtitle()`, `contentHash()`, `build()` | `_full` detection; key used in path | Generic | no change required |
| `app/Services/Telegram/TelegramUpdateProcessor.php` | `routeCallback()` (`today:morning`/`today:evening`) | two exact matches | A new menu entry needs a route to the mode choice (if reached via /today or any new callback) | routing/navigation |
| `app/Services/Telegram/TelegramUpdateProcessor.php` | `handleTodayReadingNav()` | period ∈ {`m`,`e`} → `ReminderType` → `collectionKey` | The reader can only be opened in "today" scope through this method; new periods are rejected with `bot.unknown` | reader (entry) / collection lookup |
| `app/Services/Telegram/TelegramUpdateProcessor.php` | `handleOfflineSave()` (`sv:t:`) | same `m`/`e` mapping | The "📥 أرسل الورد كاملًا" button on a bedtime card would fail with `bot.unknown` | reader (entry) / collection lookup |
| `app/Services/Telegram/TelegramUpdateProcessor.php` | `presentReading`, `readingActions`, `readingCallback`, `saveCallback`, `show` | generic; default period `m` only if missing | Period is always set in today scope | no change required |
| `app/Support/Telegram/KeyboardFactory.php` | `todayMenu()` | 3 fixed buttons | Needed only if /today is the access point | /today (conditional) |
| `app/Support/Telegram/KeyboardFactory.php` | `modeChoice($period)` | any period letter | Generic | no change required |
| `app/Support/Telegram/KeyboardFactory.php` | `readingNav()` | generic | — | no change required |
| `app/Support/Telegram/KeyboardFactory.php` | `mainReplyKeyboard()` | fixed labels | Only if a reply-keyboard button is the access point | routing/navigation (conditional) |
| `app/Services/Telegram/TelegramUpdateProcessor.php` | `handleReplyKeyboard()`, `handleCommand()` | fixed labels/commands | Only if a reply-keyboard button or a new command is the access point | routing/navigation (conditional) |
| `app/Services/Telegram/TelegramUpdateProcessor.php` | `handleAdditionalCallback()`, `additionalItems()` | `category = additional`, single-item view | Only if the Additional library is the access point (§13) | additional menu (conditional) |
| `config/adhkar.php` | `additional_groups` | groups list only `additional.*` keys | Only for the Additional access point | content/config only (conditional) |
| `lang/ar/bot.php` | `duration_short`, `duration_full` (used by `choose_mode`) | fixed "تقريبًا 5 دقائق" / "تقريبًا 10–15 دقيقة" | Shown on the mode-choice screen for any collection that goes through `modeChoiceText`; values describe morning/evening | label mapping (copy) |
| `lang/ar/bot.php` | `help`, `explain_body`, `welcome`, `today_prompt` | list morning/evening/additional features | Not technically required; feature description copy | documentation (copy) |
| `app/Enums/ReminderType.php` | enum | 2 cases | **Only** if the chosen approach routes manual keys through `collectionKey(…, ReminderType)` / `modeChoiceText(ReminderType)`. Adding cases does not affect dispatch (explicit Morning/Evening passes), but the reminder enum would then carry non-reminder values. | enum (conditional) |
| `app/Models/AdhkarItem.php`, `app/Models/AdhkarCollection.php` | casts | enum casts | Work once the enum has the cases | no change required |
| `app/Enums/AdhkarVariant.php`, `app/Enums/AdhkarMode.php` | enums | short/full | Reusable as-is | no change required |
| `database/migrations/*` | — | generic string columns | No content, pivot or reading-state column is category-specific | **no migration required** |
| `app/Services/ReminderDispatchService.php`, `app/Jobs/SendAdhkarReminderJob.php`, `app/Services/UserSettingsService.php`, `app/Services/OnboardingService.php`, `app/Models/User.php`, `app/Services/ReminderScheduleCalculator.php` | — | morning/evening reminders | Not touched by manual collections | no change required |
| `resources/views/pdf/wird.blade.php` | template | receives heading/subtitle/footer | Generic | no change required |
| `docker/entrypoint.sh`, `Dockerfile`, `deploy/*` | — | run `adhkar:sync --if-changed` | Picks up new data automatically (source hash includes data files and `ContentSyncService.php`) | no change required |
| `resources/adhkar/bedtime.md`, `resources/adhkar/waking.md` (+ `app adhkar/` copies, if that duplication convention is kept) | — | each existing data file names its Markdown source in a docblock | Provenance trail used by the existing content convention | documentation / content |
| `tests/Unit/ContentSyncServiceTest.php` | `test_sync_is_idempotent…` | asserts morning/evening keys and short ⊂ full | New collections/items need assertions | test |
| `tests/Feature/ContentSyncIfChangedTest.php` | file-override tests | explicit 3-file lists in some tests | INFERENCE: existing tests still pass (overrides replace `dataFiles()`); new-file coverage is missing | test |
| `tests/Feature/ReminderReadingTest.php` (or a new test file) | today/reader tests | only `today:morning`, `rt:m:*`, `sv:t:e:a` | Need bedtime/waking open, page, continue, PDF, labels | test |
| `tests/Unit/AdhkarContentServiceTest.php` | label tests | morning/evening | Header/footer/title for new keys | test |
| `tests/Feature/AdditionalAndCompletionTest.php` | `test_additional_menu_is_grouped…` reads `inline_keyboard[0][0]`; `test_additional_items_are_not_in_morning_collections` | first group is `short` | Breaks only if groups are inserted before it (Additional access point) | test (conditional) |
| `tests/Feature/SettingsPauseResumeTest.php` | `test_settings_back_opens_the_reading_menu` | markup contains `today:morning` | Still true if the today menu only grows | no change required |
| `database/factories/AdhkarItemFactory.php`, `ReminderLogFactory.php` | defaults Morning / `morning_short` | test defaults | — | no change required |
| `docs/architecture.md`, `docs/bot-flows.md`, `docs/database-design.md`, `docs/testing-and-deployment.md`, `docs/implementation-checklist.md`, `README.md`, `docs/current-bot-audit.md`, Arabic report files | — | describe 3 categories / 5 collections | Would be stale | documentation |

Count for the cleanest reader-reusing path (FACT-based minimum):
- **New files: 2** (data files `bedtime.php`, `waking.php`), plus 2 Markdown provenance files by convention.
- **Existing application files that must change: 6.** `AdhkarCategory.php`, `ContentSyncService.php`, `AdhkarContentService.php`, `AdhkarPdfService.php` and `TelegramUpdateProcessor.php` change in every option. `KeyboardFactory.php` changes for any button-based entry; the only options that skip it are a new command or the Additional route.
- **Likely: 1 more**: `lang/ar/bot.php` (durations/labels). **Conditional: 2**: `ReminderType.php` and `config/adhkar.php`.
- **Tests: 3–4 files**; **docs: several**.
- **Migrations: 0.**

---

## 9. Technical Access Points

Descriptive only; no option is chosen.

| Access point | Code location | Existing behaviour | What the code assumes | Can new collections plug in? | What breaks / must change if inserted |
|---|---|---|---|---|---|
| `/today` inline menu | `KeyboardFactory::todayMenu`; `TelegramUpdateProcessor::handleCommand('/today')`, `handleReplyKeyboard('📖 أذكار اليوم')`, `showTodayMenu`, `routeCallback('today:*')`; then `KeyboardFactory::modeChoice` → `rt:{p}:{s|a}:0` | 3 buttons: morning, evening, additional → mode choice → reader | Exactly two periods `m`/`e` mapped to `ReminderType` | Yes, through the shared reader | New buttons + `today:*` routes; period→base-key resolution in `handleTodayReadingNav` and `handleOfflineSave`; mode-choice title/durations; prefix-based labels. Existing tests stay valid. The menu text `bot.today_prompt` "ماذا تريد أن تقرأ؟" is generic. |
| Additional library | `KeyboardFactory::additionalGroups/additionalList/additionalItem`; `TelegramUpdateProcessor::handleAdditionalCallback`, `additionalItems`, `additionalGroupTitle`; `config/adhkar.php additional_groups` | Groups → list of item titles → single item detail (`formatItem(detailed: true)`) | Items have `category = additional`; one item per screen; no Previous/Next, progress, short/full or PDF | Only as single items, and only if stored with `category = additional` in `additional.php` (zero code change) | Bedtime/waking items would also appear in "📚 جميع الأذكار" and the `additional` collection. Footers/labels do not apply. Sequential reading and short/full are unavailable. Inserting groups before `short` breaks `AdditionalAndCompletionTest` index `[0][0]`. Opening the reader from here would need a new callback branch. |
| Main reply keyboard | `KeyboardFactory::mainReplyKeyboard`; `TelegramUpdateProcessor::handleReplyKeyboard` (label `match`) | 2×2 + 2 rows of fixed labels | Labels are matched as exact strings | Yes, if a label + handler are added | Both methods change. The reply keyboard reaches users only with new messages (`show()` cannot attach it on edit). `test_main_keyboard_puts_today_on_the_right_and_changes_with_pause` asserts the current layout. |
| Slash command | `TelegramUpdateProcessor::handleCommand` (`match`) | `/start`, `/settings`, `/help`, `/pause`, `/resume`, `/today` | Fixed list | Yes, with a new `match` arm | Bot command list in BotFather is external; `bot.help` lists commands. |
| Returning-start keyboard | `KeyboardFactory::returningStart` | today / settings / reset / explain | — | Indirectly (via `today:menu`) | — |
| Dedicated category menu / shared collection browser | — | **Does not exist** | — | Would be new code | New callbacks, keyboard, routing |
| Reminder nudge | `KeyboardFactory::reminderNudge` | Start buttons for a log | Reminder-only | Not applicable to Phase A | — |

---

## 10. Short/Full Compatibility

| Question | Answer |
|---|---|
| A. Does short/full belong to the collection? | **Yes.** It is collection membership (`{x}_short`, `{x}_full`), declared per item in `collections`. `adhkar_collections.variant` is stored but never read. FACT. |
| B. Is mode selected per reading? | **Yes.** `/today` → `modeChoice` buttons `rt:{p}:s:0` / `rt:{p}:a:0`; reminders → `reminder:start:{id}:{s|a}`. FACT. |
| C. Is mode stored on the user? | `users.mode` exists (cast `AdhkarMode`) but nothing writes it. It is read only by `ReminderDispatchService::claim` (default `collection_key`) and the unused `AdhkarContentService::resolveCollection`. Vestigial. FACT. |
| D. Is mode inferred from the collection key? | Partly. In "log" scope, `deliverScheduledReading` infers phase `a` from `_full` in `collection_key`. Card headers and the PDF subtitle infer "كامل" from `_full`. In today scope the phase letter in the callback decides. FACT. |
| E. Could two new collections have short/full versions? | **Yes, technically.** Keys must follow `{x}_short` / `{x}_full`, and short items must be a key-subset of full items, for `fullOnlyItems()` and the `f` continuation ("أكمل الورد الكامل") to work. With a single list, one collection works too: `fullKey()` is then a no-op and `f` is empty. But `modeChoice` would still show both buttons with identical lists unless the entry point skips it. Which items form a "short" bedtime/waking list is a content decision that is not in the repository. |
| Pivot-order constraint | An item's single `order` is reused as its position in **every** collection it belongs to (`syncItem`). Reusing existing items such as `morning.01` inside a bedtime collection would force the morning position and keep `category = morning`. Separate item keys avoid that coupling. FACT / INFERENCE. |
| Reader initialization / progress | Generic: `presentReading` counts the phase list; "الذكر N من total" adapts. FACT. |
| F. Files that must know about the new collections | `ContentSyncService` (definitions, files), the new data files, `AdhkarCategory`, the period/entry → base-key resolution in `TelegramUpdateProcessor` (`handleTodayReadingNav`, `handleOfflineSave`, plus the entry route), labels in `AdhkarContentService` and `AdhkarPdfService`, the entry keyboard in `KeyboardFactory`, and duration copy in `lang/ar/bot.php`. |

---

## 11. Reader Compatibility

**Can bedtime/waking reuse the same reader?** **Yes**, for loading, ordering, progress, Previous/Next, continuation and PDF. Its entry points and labels are morning/evening-specific, though. Reasons:

| Capability | Status | Evidence |
|---|---|---|
| Loading items | Generic by key | `AdhkarContentService::collectionItems($key)` |
| Ordering | Generic | `orderByPivot('order')` |
| Progress | Generic ("الذكر N من total") | `renderCard`, `presentReading` |
| Next / Previous | Generic index arithmetic; Previous on `f` index 0 jumps to the last short card | `readingActions` |
| Continue to full | Generic if `{x}_short`/`{x}_full` | `fullOnlyItems` |
| Full collection sending (PDF / text fallback) | Generic content; **entry blocked** by `sv:t:` `m`/`e` validation | `handleOfflineSave`, `AdhkarPdfService`, `offlineMessages` |
| Arabic HTML formatting, source, repetition | Generic per item | `formatItem` |
| Session header | **Morning fallback** | `sessionHeader` |
| Footer on last card | **Additional-text fallback** | `footerForKey` |
| PDF heading/title | **Morning fallback** | `AdhkarPdfService::render`, `wirdTitle` |
| Callback encoding | Today scope encodes period letters `m`/`e` only | `readingCallback`, `saveCallback`, `handleTodayReadingNav` |
| Hard-coded item counts | None | grep; tests use relative assertions |
| Message length | No guard on a single card; long virtues use an expandable blockquote | `formatItem`, `blockquote` |

---

## 12. /today Compatibility

Current behaviour (FACT):

| Step | Payload | Code |
|---|---|---|
| Open | `/today`, `📖 أذكار اليوم`, `today:menu` | `handleCommand`, `handleReplyKeyboard`, `showTodayMenu` → `KeyboardFactory::todayMenu` |
| Menu | `today:morning`, `today:evening`, `add:list` | `routeCallback` |
| Mode choice | `rt:m:s:0` / `rt:m:a:0` (or `e`) + `today:menu` back | `modeChoiceText(ReminderType)`, `KeyboardFactory::modeChoice('m'|'e')` |
| Reading | `rt:{m|e}:{s|f|a}:{index}` | `handleTodayReadingNav`: validates `m`/`e` and `s`/`f`/`a`, maps to `ReminderType`, `collectionKey(Short|Full, type)` |
| PDF | `sv:t:{m|e}:{phase}` | `handleOfflineSave` |
| Fallback | invalid period/phase → toast `bot.unknown`; no morning fallback for invalid letters | — |
| Persistence | none (no log, no Done) | `test_manual_today_does_not_create_a_log_or_done_button` |

What would be required for `/today → bedtime` and/or `/today → waking`:
1. Menu buttons in `KeyboardFactory::todayMenu`.
2. `routeCallback` arms for the new `today:*` values, showing a mode-choice screen with the correct title (`modeChoiceText` is typed on `ReminderType`).
3. New period letters accepted in `handleTodayReadingNav` and `handleOfflineSave`, resolved to `bedtime_*` / `waking_*` base keys. Today that resolution goes through `ReminderType` + `collectionKey`, so either the enum grows or the resolution stops depending on it.
4. Labels: `sessionHeader`, `footerForKey`, `wirdTitle`, PDF heading.
5. Durations copy if the mode-choice screen is used.

Technical consequences of each option:

| Option | Consequence |
|---|---|
| Put bedtime/waking in `/today` | Reuses the reader end-to-end, including PDF. Touches the period mapping that today doubles as the `ReminderType` mapping. The today menu grows from 3 to 5 inline rows. Existing payloads stay valid, so old buttons on old messages keep working. |
| Keep them out of `/today` initially | The `rt:`/`sv:t:` period mapping still has to accept new letters (or a parallel prefix is needed), because the today-scope reader is the only non-reminder entry to `presentReading`. A separate entry button/command/menu must be added somewhere else (§9). |
| Additional library instead (no `/today`) | Zero code if the items are stored as `category = additional`, but no sequential reader, short/full or PDF (§13). |

The product decision is not made here.

---

## 13. Additional Library Compatibility

| Aspect | Current implementation (FACT) | Compatible with bedtime/waking? |
|---|---|---|
| Group map | `config/adhkar.php additional_groups` (key, label, list of item keys); comment says navigation only, not religious classification | A group could list bedtime/waking item keys |
| Item selection | `additionalItems`: `category = Additional`, `active`, `whereIn('key', …)`, sorted by config order; `all` = every active additional item | Items must be `category = additional`; they would also appear under "📚 جميع الأذكار" |
| Item open | `add:item:{id}:{group}`: requires `category = Additional` | Same restriction |
| Labels | Group label from config; title "🌟 أذكار وفضائل إضافية" + group label | Generic title text describes "additional" |
| Rendering | `formatItem(detailed: true)`, single item, back button only | Works per item; no progress, no Next/Previous |
| Short/full | None | Not available |
| Sync | Items must be in one of the three data files, so `additional.php` unless `ContentSyncService::dataFiles()` changes; `collections` → `additional` | Possible without code if placed in `additional.php` |
| PDF | None | Not available |

Technically compatible only as a flat list of single-item cards inside the existing additional category. Conceptual fit is not assessed.

---

## 14. Reminder Coupling (Phase B only — not implemented)

| Location | Assumption (FACT) |
|---|---|
| `app/Enums/ReminderType.php` | 2 cases |
| `users` migration `0001_01_01_000000` | `morning_time`, `evening_time`, `next_morning_at`, `next_evening_at`; indexes `users_reminders_morning_idx`, `users_reminders_evening_idx` |
| `app/Models/User.php` | fillable/casts for the 4 columns; `isOnboarded()` requires both times; `formattedMorningTime/EveningTime` |
| `ReminderDispatchService::claimDueLogIds` | `select` of the two `next_*` columns; WHERE `next_morning_at <= now OR next_evening_at <= now`; two explicit passes |
| `ReminderDispatchService::isStillDue`, `claim` | ternaries Morning ? `next_morning_at`/`morning_time` : evening |
| `reminder_logs` | `reminder_type string(16)`; unique `(user_id, reminder_type, scheduled_local_date)`; cast `ReminderType` |
| `SendAdhkarReminderJob::markFailed` | clears exactly `next_morning_at`/`next_evening_at` |
| `SendAdhkarReminderJob::handle` → `AdhkarContentService::nudgeText` | `evening` prefix else morning |
| `KeyboardFactory::reminderNudge` | time-edit callback `settings:evening_time` vs morning |
| `TelegramUpdateProcessor::handleReminderStart` → `claimReading` | `collectionKey(mode, reminder_type)` |
| `TelegramUpdateProcessor::completionProblem` | `in_array(reminder_type, [Morning, Evening])` |
| `TelegramUpdateProcessor::handleCompletion` | Evening vs morning completion text |
| `TelegramUpdateProcessor::isCurrentLocalDay` | Start/Done valid only on `scheduled_local_date` = today |
| `UserSettingsService::recalculateNextReminders`, `pause`, `resume`, `updateMorningTime`, `updateEveningTime` | exactly two slots; requires both times |
| `OnboardingService` | timezone → morning → evening; `back()` branches on `str_contains($awaiting, 'morning'/'evening')`; `summary` |
| `OnboardingState` | `WaitingMorningTime`, `WaitingEveningTime` |
| `TelegramUpdateProcessor::handleAwaitingInput`, `handleTimeCallback`, `handleCustomTime`, `saveChosenTime`, `replyTimeError`, `replyPauseResume` | `morning`/`evening` kinds and state strings |
| `ReminderScheduleCalculator::classifyTime` | the `evening` kind triggers the AM/PM ambiguity rule |
| `KeyboardFactory::settingsMenu`, `timePresets` | two time buttons; `morning_presets`/`evening_presets` |
| `config/adhkar.php` | `morning_presets`, `evening_presets` |
| `lang/ar/bot.php` | `ask_*_time`, `*_saved`, `summary_*`, `resumed`, `nudge_*`, `completed_*`, `tutorial` |
| `DispatchDueRemindersCommand` | description "morning/evening" |
| Tests | `ReminderDispatchTest`, `SchedulerCronTest`, `ReminderReadingTest`, `ReminderDeliveryAndVersioningTest`, `SettingsPauseResumeTest`, `OnboardingTest`, `PostgresBehaviourTest`, `DatabaseConstraintTest`, `UserFactory` |

Why bedtime/waking reminders need schema changes (FACT/INFERENCE):
- **Required new storage:** per-type preferred time and per-type next-run timestamp for each new type. The `users` table has no such columns, and `claimDueLogIds` queries the specific column names. The new storage could be four new columns plus two indexes, or a separate per-type schedule table. Either way it is a migration. The form is not chosen here.
- **Required enum changes:** `ReminderType` cases (`bedtime` = 7 chars and `waking` = 6 chars fit `string(16)`). `OnboardingState` only if onboarding asks for the new times.
- **Uniqueness:** `(user_id, reminder_type, scheduled_local_date)` already allows one log per new type per day; no change is needed for one reminder per type per day. INFERENCE: a bedtime time after local midnight, or a Start pressed after midnight, would fail `isCurrentLocalDay` (the date rolls over), so the "same local day" rule needs a decision for bedtime.
- **Dispatch changes:** WHERE clause, passes, ternaries, `markFailed`, `pause`, `recalculateNextReminders`.
- **Settings changes:** settings menu, presets, awaiting-input states, time callbacks, summaries, pause/resume copy.
- **Start/Done:** `completionProblem` type whitelist, completion text, nudge text, nudge time button.
- **Tests:** all reminder test files above.

Nothing here was implemented.

---

## 15. Database Impact

### Content schema
**No migration required** for new adhkar collections. `adhkar_collections`, `adhkar_items` and `adhkar_collection_items` use generic string keys/categories. The blocker is the PHP enum `AdhkarCategory`, not the schema. FACT.

### Reminder schema
**Migration required** for scheduled bedtime/waking reminders: there is no per-type time or next-run storage for new types (§14). FACT.

### Reading state
**No migration required.** Manual reading state lives only in callback data; there is no session table. `reminder_logs` is used only in reminder scope. FACT.

### User settings
**No `users` change required** for manual collections; manual reading reads no user setting. FACT.

---

## 16. Callback/State Impact

### 16.1 Inventory and identifier style (FACT)

| Family | Payloads | Collection identifier style |
|---|---|---|
| Onboarding | `onb:begin`, `onb:reset`, `onb:tz:{i|custom}`, `onb:tzr:{c}:{r}`, `onb:time:{morning|evening}:{HHMM|custom}`, legacy `onb:mode:*`, `guide:explain`, `nav:back`, `nav:cancel` | none (reminder kinds as raw strings) |
| Settings | `settings:open|timezone|morning_time|evening_time|toggle_reminders|close` | none |
| Today | `today:menu`, `today:morning`, `today:evening` | **fixed exact-match branches** |
| Today reading | `rt:{m|e}:{s|f|a}:{i}`, `sv:t:{m|e}:{phase}` | **compact period codes** `m`/`e` → `ReminderType` |
| Reminder reading | `rd:{logId}:{s|f|a}:{i}`, `sv:l:{logId}:{phase}` | DB `reminder_logs.collection_key` (raw string) |
| Reminder | `reminder:start:{logId}:{s|a}`, `reminder:done:{logId}` | log id |
| Additional | `add:list`, `add:back`, `add:group:{key|all}`, `add:item:{itemId}:{groupKey}` | config group keys (raw strings), DB item ids |
| Other | `noop` | — |

### 16.2 Collision analysis

- `today:bedtime` / `today:waking`: no collision with `today:menu|morning|evening` (exact matches). FACT.
- New period letters inside `rt:`/`sv:t:`: `b` and `w` do not clash with `m`/`e`; phases are a separate position. Parsing is positional (`explode(':')`), so letters are scoped to their position. FACT.
- `add:group:{key}`: existing keys `short`, `prayer`, `salawat`, `all`. A new group named `short`/`all` would collide; `bedtime`/`waking` would not. FACT.
- Prefix routing order in `routeCallback`: `str_starts_with` checks for `onb:`, `settings:`, `add:`, `sv:`, `rd:`, `rt:`, `reminder:`. A new top-level prefix must not start with any of these strings. FACT.
- Length: Telegram limits `callback_data` to 64 bytes (external). Payloads like `rt:b:a:12` or `today:bedtime` are far below that.
- Old messages: existing payloads keep working if formats are unchanged.

### 16.3 What is possible today vs needs change

- Possible without change: `rd:`/`sv:l:`/`reminder:*` carry log ids and are collection-agnostic, but they exist only for reminders.
- Needs change for manual bedtime/waking: `today:*` branches (if used), the `m`/`e` whitelist and mapping in `handleTodayReadingNav` and `handleOfflineSave`, or a new callback family.

---

## 17. salat_nabi Provenance Issue

Repository evidence only; no religious judgment.

| Item | Evidence (FACT) |
|---|---|
| Entries | `database/data/adhkar/morning.php` → `morning.salat_nabi`; `database/data/adhkar/evening.php` → `evening.salat_nabi` |
| Fields | `title` "الصلاة على النبي ﷺ — 10 مرات"; `arabic_text` "اللَّهُمَّ صَلِّ وَسَلِّمْ وَبَارِكْ عَلَى نَبِيِّنَا مُحَمَّدٍ ﷺ."; `repetitions_count` 10; `repetitions_label` "10 مرات"; `timing`, `virtue`, `source_text`, `source_name`, `hadith_number`, `hadith_grade`, `narrator`, `guidance_note` all **null**; `notes` "أُضيفت إلى قائمة الأذكار بناءً على طلب المستخدم، دون إسناد فضل خاص في ملف البحث الأصلي لهذا العدد تحديدًا."; `order` 3; collections `morning_short`+`morning_full` / `evening_short`+`evening_full` |
| Source missing? | Yes. No source, number, grade or virtue is stored. These are the only morning/evening items without `source_name` (the data-file scan shows `src=N` only for these two and the inactive `additional.11`). |
| Where it appears | 3rd card of every morning/evening short and full reading; morning/evening PDFs; text fallback |
| Tests | `tests/Unit/ContentSyncServiceTest.php:35,37` assert the first three short keys are `*.01`, `*.02`, `*.salat_nabi` (position only, not content) |
| Docs marking review | `docs/implementation-checklist.md:29` open item: wording/virtue do not match `adhkar-research.md` §24, "Left unchanged pending an explicit source-backed correction". `docs/implementation-plan.md:59` "flagged for source review". `docs/current-bot-audit.md` §15.3 / §22. |
| Changed by this audit? | No |

---

## 18. Test Impact

### 18.1 Execution

**Run:** `php artisan test` (full suite) on the existing test environment.
**Result (FACT):** `Tests: 6 skipped, 125 passed (538 assertions)`. The 6 skipped tests are PostgreSQL-only. This includes the uncommitted `test_long_outage_…`.

Why it was safe (FACT):
- `phpunit.xml` forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `CACHE_STORE=array`, and test Telegram/cron secrets.
- No OS-level `DB_*`/`APP_ENV` variables were set in the shell.
- `bootstrap/cache/config.php` does not exist, so no cached config could redirect tests to the real DB.
- `tests/TestCase.php` uses `RefreshDatabase` (in-memory) and `Http::preventStrayRequests()`, so no real Telegram call is possible.
- `ContentSyncIfChangedTest` writes temp copies under the system temp directory.

Side effects observed: only the git-ignored `storage/logs/laravel.log` and `storage/logs/telegram-2026-10-03.log` changed (the running local stack writes them too). `git status` was identical before and after. `database/database.sqlite`, `.env` and tracked files were not touched by the tests.

Inventory: 16 test classes, 119 test methods in the working tree (118 at HEAD). PHPUnit reports 131 cases, so some tests use data providers.

### 18.2 Tests to add/update when bedtime/waking are implemented

#### Content tests
- `tests/Unit/ContentSyncServiceTest.php`: new collections exist; item keys; short ⊂ full (if split); idempotency still holds.
- `tests/Feature/ContentSyncIfChangedTest.php`: new data files affect `sourceHash()`; `isComplete()` covers new collections/links; a missing new collection triggers sync.
- A guard that every `collections` key in data files is defined (today an undefined key is silently skipped).

#### Reader tests
- `tests/Feature/ReminderReadingTest.php` (or new file): open bedtime/waking, page Next/Previous, last-card footer, continuation (if short/full), header labels, PDF title/heading (`sv:t:` with new period), no reminder log created.
- `tests/Unit/AdhkarContentServiceTest.php`: `sessionHeader`/`footerForKey`/`wirdTitle` for new keys; no morning fallback.

#### Navigation tests
- Whichever access point is chosen: today menu buttons, reply keyboard (`test_main_keyboard_…` asserts layout), or additional groups (`AdditionalAndCompletionTest` index `[0][0]`).

#### /today tests
- `today:{new}` → mode choice title; `rt:{new}:s:0` / `a:0`; invalid letters still rejected; existing `today:morning`/`rt:m:*` unchanged (`ReminderReadingTest:258–270`, `SettingsPauseResumeTest:70`).

#### Callback tests
- Old payloads still route; new payloads under 64 bytes; unknown period → `bot.unknown`.

#### Regression tests
- The full existing suite (125 passing) must stay green, especially `ReminderReadingTest`, `AdditionalAndCompletionTest`, `ContentSyncServiceTest`, `ContentSyncIfChangedTest`, `SchedulerCronTest`.

#### Reminder tests (future only)
- `ReminderDispatchTest`, `SchedulerCronTest`, `ReminderDeliveryAndVersioningTest`, `ReminderScheduleCalculatorTest`, `OnboardingTest`, `SettingsPauseResumeTest`, `DatabaseConstraintTest`, `PostgresBehaviourTest`, `UserFactory`.

---

## 19. Deployment Impact

**Can manual bedtime/waking content be deployed through the current application without changing infrastructure?** **Yes** (INFERENCE from code):

| Concern | Needed? | Reason |
|---|---|---|
| New infrastructure | No | Same tables, same webhook → queue → processor path |
| New environment variables | No | No config keys involved |
| Queue changes | No | Manual reading does not dispatch jobs beyond the existing `ProcessTelegramUpdateJob` |
| Scheduler/cron changes | No | No reminders |
| Deployment changes | No | `docker/entrypoint.sh` runs `migrate` (no-op) then `adhkar:sync --if-changed`. The source hash includes the data files and `ContentSyncService.php`, so new content syncs on the next start. Classic path: `php artisan adhkar:sync` per `docs/testing-and-deployment.md`. |
| Worker changes | No | — |

Caveat (FACT): if new data files used a category not in `AdhkarCategory`, the sync would throw. With `set -eu` in the entrypoint, the container would then fail to start, so enum and data must ship together. Where production actually runs is unresolved (§3).

---

## 20. Performance Impact

Assuming roughly 13 bedtime + 4 waking full items (final count not decided):

| Area | Impact (INFERENCE from code paths) |
|---|---|
| DB size | +~17 item rows, +2 to 4 collection rows, +~17 to 34 pivot rows; negligible next to 50/68 today |
| Sync time | `syncItem` does about 3 to 5 queries per item plus a pivot sync; +17 items ≈ +60 to 90 queries inside one transaction, run only when the source hash changes |
| `isComplete()` | Re-reads data files and runs 4 count queries; unchanged complexity |
| Memory | Data files are `require`d per sync; a few KB more |
| Reader | Each card loads the whole current phase list (≤ 13 rows): 1 collection + 1 item query per card, plus `fullOnlyItems` queries on the last short card; same as morning/evening |
| Telegram messages | Per card: `answerCallbackQuery` + `editMessageText`; no extra messages. PDF: one `sendDocument` (cached `file_id` afterwards) |
| PDF rendering | mPDF render of ≤ 13 items: smaller than `morning_full` (20) |

No meaningful impact.

---

## 21. Morning/Evening Coupling Matrix

Reconstructed from source. **A** = matters for manual bedtime/waking content; **B** = matters for future bedtime/waking reminders.

### 1. Enums

| # | File | Location | Assumption | Future Impact |
|---|---|---|---|---|
| 1 | `app/Enums/AdhkarCategory.php` | enum | morning/evening/additional only | **A** (sync throws), B |
| 2 | `app/Enums/ReminderType.php` | enum | morning/evening only | A (only if keys are built from it), **B** |
| 3 | `app/Enums/AdhkarVariant.php`, `AdhkarMode.php` | enums | short/full | none (reusable) |
| 4 | `app/Enums/OnboardingState.php` | `WaitingMorningTime`, `WaitingEveningTime` | two time steps | B (if onboarding asks for new times) |

### 2. Database / user settings

| # | File | Location | Assumption | Future Impact |
|---|---|---|---|---|
| 5 | `database/migrations/0001_01_01_000000_create_users_table.php` | 4 columns + 2 indexes | two reminder slots | **B** (migration) |
| 6 | `database/migrations/2026_01_01_000006_create_reminder_logs_table.php` | `reminder_type string(16)`, unique per type/day | one log per type per day | B (fits new values) |
| 7 | `app/Models/User.php` | `isOnboarded`, fillable, casts, formatters | both times required | B |
| 8 | `app/Services/UserSettingsService.php` | `recalculateNextReminders`, `pause`, `resume`, `update*Time` | two slots | B |

### 3. Reminder engine

| # | File | Location | Assumption | Future Impact |
|---|---|---|---|---|
| 9 | `app/Services/ReminderDispatchService.php` | `claimDueLogIds` | WHERE + two passes | B |
| 10 | same | `isStillDue`, `claim` | Morning ? … : evening ternaries | B |
| 11 | same | `claim` `collection_key` | `collectionKey(users.mode, type)` | B |
| 12 | `app/Jobs/SendAdhkarReminderJob.php` | `markFailed` | clears two `next_*` columns | B |
| 13 | `app/Services/AdhkarContentService.php` | `nudgeText` | `evening` prefix else morning | B |
| 14 | `app/Support/Telegram/KeyboardFactory.php` | `reminderNudge` | evening vs morning time button | B |
| 15 | `app/Services/Telegram/TelegramUpdateProcessor.php` | `completionProblem` | type whitelist Morning/Evening | B |
| 16 | same | `handleCompletion` | Evening vs morning text | B |
| 17 | same | `isCurrentLocalDay` (Start/Done) | same local date | B (bedtime past midnight) |
| 18 | `app/Console/Commands/DispatchDueRemindersCommand.php` | `$description` | "morning/evening" | B (cosmetic) |
| 19 | `app/Services/OnboardingService.php` | flow, `back`, `summary` | timezone → morning → evening | B |
| 20 | `app/Services/Telegram/TelegramUpdateProcessor.php` | `handleAwaitingInput`, `handleTimeCallback`, `handleCustomTime`, `saveChosenTime`, `replyTimeError`, `replyPauseResume` | morning/evening kinds | B |
| 21 | `app/Services/ReminderScheduleCalculator.php` | `classifyTime($kind)` | `evening` ambiguity rule | B |
| 22 | `config/adhkar.php` | `morning_presets`, `evening_presets` | two preset lists | B |

### 4. Content sync

| # | File | Location | Assumption | Future Impact |
|---|---|---|---|---|
| 23 | `app/Services/ContentSyncService.php` | `dataFiles()` | 3 files | **A** |
| 24 | same | `collectionDefinitions()` | 5 collections | **A** |
| 25 | same | `syncItem()` | unknown collection keys silently skipped; pivot order = item order | A (silent failure mode; reuse constraint) |

### 5. Collection labels

| # | File | Location | Assumption | Future Impact |
|---|---|---|---|---|
| 26 | `app/Services/AdhkarContentService.php` | `sessionHeader` | evening else morning; `_full` | **A** |
| 27 | same | `footerForKey` | morning/evening else additional text | **A** |
| 28 | same | `wirdTitle` | evening else morning | **A** |
| 29 | same | `modeChoiceText(ReminderType)` | Evening else morning | **A** |
| 30 | `app/Services/AdhkarPdfService.php` | `render` heading | evening else morning | **A** |
| 31 | `lang/ar/bot.php` | `duration_short`, `duration_full` | morning/evening durations | A (copy) |
| 32 | `lang/ar/bot.php` | `nudge_*`, `completed_*`, `summary_*`, `resumed`, `ask_*_time`, `*_saved`, `tutorial` | morning/evening wording | B |
| 33 | `lang/ar/bot.php` | `welcome`, `explain_body`, `help` | feature list | A/B (copy only) |

### 6. /today

| # | File | Location | Assumption | Future Impact |
|---|---|---|---|---|
| 34 | `app/Support/Telegram/KeyboardFactory.php` | `todayMenu` | 3 fixed buttons | A (if /today is used) |
| 35 | `app/Services/Telegram/TelegramUpdateProcessor.php` | `routeCallback` `today:morning|evening` | two periods | A (if /today is used) |

### 7. Reader

| # | File | Location | Assumption | Future Impact |
|---|---|---|---|---|
| 36 | `app/Services/Telegram/TelegramUpdateProcessor.php` | `handleTodayReadingNav` | `m`/`e` → ReminderType → `collectionKey` | **A** |
| 37 | same | `handleOfflineSave` (`sv:t:`) | `m`/`e` → ReminderType | **A** |
| 38 | same | `readingCallback`, `saveCallback` | default period `m` | none (period always set) |
| 39 | same | `deliverScheduledReading` | phase from `_full` | B (reminder scope) |
| 40 | `app/Services/AdhkarContentService.php` | `collectionKey`, `fullKey`, `fullOnlyItems` | `{type}_{variant}`; `_short`→`_full` | A (naming convention to follow) |

### 8. Navigation

| # | File | Location | Assumption | Future Impact |
|---|---|---|---|---|
| 41 | `app/Support/Telegram/KeyboardFactory.php` | `mainReplyKeyboard`; `TelegramUpdateProcessor::handleReplyKeyboard` | fixed labels | A (if used) |
| 42 | `app/Support/Telegram/KeyboardFactory.php` | `settingsMenu` | two time buttons | B |
| 43 | `app/Services/Telegram/TelegramUpdateProcessor.php` | `handleAdditionalCallback`, `additionalItems` | `category = additional` | A (if Additional is used) |
| 44 | `config/adhkar.php` | `additional_groups` | `additional.*` keys | A (if Additional is used) |

### 9. Callbacks

| # | File | Location | Assumption | Future Impact |
|---|---|---|---|---|
| 45 | `KeyboardFactory::modeChoice`, `TelegramUpdateProcessor::handleTodayReadingNav` | `rt:{m|e}` | compact period codes | A |
| 46 | `KeyboardFactory::timePresets`, `TelegramUpdateProcessor::handleTimeCallback` | `onb:time:{morning|evening}` | two kinds | B |

### 10. Tests

| # | File | Location | Assumption | Future Impact |
|---|---|---|---|---|
| 47 | `tests/Unit/ContentSyncServiceTest.php` | key assertions | morning/evening keys | A |
| 48 | `tests/Feature/AdditionalAndCompletionTest.php` | `[0][0]` first group | group order | A (conditional) |
| 49 | `tests/Feature/ReminderReadingTest.php` | `today:morning`, `rt:m:*`, `sv:t:e:a` | two periods | A (new tests) |
| 50 | `database/factories/UserFactory.php`, `ReminderLogFactory.php`, `AdhkarItemFactory.php` | defaults | morning/evening | B / none |
| 51 | Reminder test files (§18.2) | — | two types | B |

### 11. Documentation

| # | File | Location | Assumption | Future Impact |
|---|---|---|---|---|
| 52 | `README.md`, `docs/architecture.md`, `docs/bot-flows.md`, `docs/database-design.md`, `docs/testing-and-deployment.md`, `docs/implementation-*.md`, Arabic reports, `docs/current-bot-audit.md` | content/flow descriptions | 3 categories / 5 collections / 2 reminders | A, B |

**Matter for A (manual content):** 1, 23, 24, 25, 26–31, 36, 37, 40, 47, 49, 52, plus the chosen access point (34–35, 41, or 43–44, 48). Item 2 matters only if keys are built from `ReminderType`.
**Matter for B (future reminders):** 2, 4–22, 32, 39, 42, 46, 50–52.

Compared with the prior audit's 34 points: the same core assumptions appear here, split more finely. Newly listed: 18, 19's `back()` substring branching, 20, 21, 25 (silent skip + pivot-order reuse), 31, 39, 43–49.

---

## 22. Minimum Required Generalization

The minimum, proven necessary by source inspection, for the existing reader to accept `bedtime` and `waking` collections:

1. **Add `Bedtime` and `Waking` cases to `App\Enums\AdhkarCategory`.** Without them the sync throws `ValueError` (§7C).
2. **Add the two data files to `ContentSyncService::dataFiles()` and their collections to `ContentSyncService::collectionDefinitions()`** (`bedtime_short`/`bedtime_full`/`waking_short`/`waking_full`, or a single key each if no short/full split). Otherwise nothing is created or linked.
3. **Add two content files** `database/data/adhkar/bedtime.php`, `waking.php` in the existing item format (content approval is outside this audit).
4. **Make the today-scope reader entry resolve a base key for the new collections.** `TelegramUpdateProcessor::handleTodayReadingNav` and `handleOfflineSave` accept only `m`/`e` and derive the key through `ReminderType` + `AdhkarContentService::collectionKey`. Either the accepted periods and their key resolution must include the new collections, or `ReminderType` gains cases. Both are proven necessary alternatives; only one is needed.
5. **Make the four prefix-based label points aware of the new keys:** `AdhkarContentService::sessionHeader`, `footerForKey`, `wirdTitle`, and `AdhkarPdfService::render` (heading). Otherwise cards and PDFs are labelled morning or "additional". Also `modeChoiceText` if the mode-choice screen is used.
6. **Add one entry point** (a today-menu button + `routeCallback` arm, or another access point from §9). Today no entry exists.
7. **Reuse unchanged:** `presentReading`, `readingActions`, `readingCallback`, `saveCallback`, `renderCard`, `formatItem`, `itemsForPhase`, `fullKey`, `fullOnlyItems`, `offlineMessages`, `snapshotVersions`, `KeyboardFactory::modeChoice`/`readingNav`, `AdhkarPdfService::build`/`subtitle`/`contentHash`, the Blade template, all migrations, all reminder code.
8. **Copy:** `bot.duration_short`/`duration_full` are shown on the mode-choice screen; their values describe morning/evening. This is a copy decision rather than a code necessity.

Not required: schema changes, a domain redesign, new tables, reminder changes, infrastructure changes.

---

## 23. Phase A — Manual Collections

Scope (not implemented):
- Content: bedtime and waking items with source metadata in the existing fields (`source_name`, `hadith_number`, `hadith_grade`, `virtue`, `repetitions_label`, …), new data files, Markdown provenance files.
- Collections: new collection definitions; short/full only if a source-backed short subset is approved.
- Category enum cases.
- Reader reuse via a base-key resolution for the new collections (§22.4).
- Labels: header, footer, PDF title/heading, mode-choice title.
- One manual access point (§9; choice open).
- Tests per §18.2 (content, reader, navigation, /today if used, callbacks, regression).
- Docs update.
- No migrations, no `users` changes, no reminder code, no infrastructure.

Why Phase A is separable (FACT): manual reading never touches `users` reminder columns, `reminder_logs`, `ReminderDispatchService`, `SendAdhkarReminderJob`, the scheduler, or the cron endpoint. The today-scope reader is stateless (callback data only).

---

## 24. Phase B — Future Reminders

Scope (not implemented):
- Schema: per-type time + next-run storage for bedtime/waking (new `users` columns + indexes, or a new table). **Migration required.**
- Enums: `ReminderType` cases; `OnboardingState` steps if onboarding asks.
- Dispatch: `claimDueLogIds` WHERE/passes, `isStillDue`/`claim` ternaries, `collection_key` derivation.
- Scheduler: no new cron; the same tick handles more types (INFERENCE).
- Uniqueness: the current key allows one per type per day; the bedtime midnight semantics need a decision.
- Settings/onboarding: time pickers, presets, awaiting-input states, summaries, pause/resume (clearing new columns), `isOnboarded` rule.
- Start/Done: `completionProblem` whitelist, completion text, nudge text, nudge time button, same-day rule.
- Recovery: `markFailed` clearing, stale recovery (generic).
- The uncommitted catch-up change (§2) affects how any new type behaves after an outage.
- Tests: all reminder suites.

Why the phases should stay separate (from the current architecture):
1. Phase A needs **no** schema change; Phase B **does** (§15). Mixing them puts a migration into an otherwise content-only release.
2. Phase B touches the reminder engine, which currently has an uncommitted, unreviewed behaviour change (§2) and an unresolved production location (§3). Phase A does not depend on either.
3. Phase B's coupling is in user settings, onboarding and dispatch (21 of the matrix points); Phase A's is in sync, labels and one reader entry (§21).
4. `ReminderType` doubles as the today-period mapping. Keeping Phase A independent of that enum (or extending it deliberately) is a decision that Phase B would otherwise force implicitly.

---

## 25. Blockers / Risks / Unknowns

### Blockers (actual)
- **For Phase A code:** none in the architecture. Content must be source-approved before any data file is written (outside this audit).
- **For releasing anything to "production":** the documented production deployment returns `402 DEPLOYMENT_DISABLED` (§3). There is currently no verified serving production target.

### Risks (observed)
1. **Uncommitted reminder behaviour change** (§2): passes tests; unreviewed; authorship unknown. The late nudge still carries the old date, so its Start answers "expired".
2. **Local stack sending real reminders** with the production bot token from local SQLite (§1.3, §2.4). If a Vercel deployment is re-enabled, two instances with different databases could both send reminders (INFERENCE).
3. **Bot token printed in local terminal output.** `PollTelegramCommand` prints `$e->getMessage()` unredacted, and the cURL error contains the full API URL. `TelegramClient::redact` exists but is not used there (FACT). The value is not reproduced here.
4. **Unstaged deletion of `POSTGRES_PRODUCTION_SETUP_REPORT.md`**: a `git commit -a` would remove the only record of the rollout incident and verification (§4).
5. **Silent sync failure mode:** data that references an undefined collection is skipped without error, and `isComplete()` then stays false, so a full sync runs on every start (FACT/INFERENCE, §6.1).
6. **Enum/data ordering on deploy:** a data file with a new category deployed before the enum makes container start fail (§19).
7. **Pivot order is item-level:** reusing existing items across collections forces the same position (§10).
8. **README states MySQL production** while newer docs state Neon (§3).
9. `salat_nabi` provenance open item (§17).

### Unknowns
- Who made the ReminderDispatch working-tree change (§2.7).
- Why and when `POSTGRES_PRODUCTION_SETUP_REPORT.md` was deleted (§4).
- Why the Vercel deployment is disabled, and whether the Neon database still exists or holds data (§3).
- Whether the Telegram webhook is still registered, and which instance (if any) receives user messages (§3).
- How the Docker image is built/deployed on Vercel (no `vercel.json`) and the cron-job.org configuration (§3).
- The approved bedtime/waking item list, sources, and whether a short subset exists (content, out of scope).

---

## 26. Final Recommendation-Free Summary

### Current Git State
- **Modified, unstaged; nothing staged.** HEAD `0c6677b` = `origin/main`.
- Unexpected changes: `app/Services/ReminderDispatchService.php` (next-run computed from `max($dueAt, now())`) and `tests/Feature/ReminderDispatchTest.php` (+1 test); the unstaged deletion of `POSTGRES_PRODUCTION_SETUP_REPORT.md`. Untracked: `docs/current-bot-audit.md`, this report.
- What the change does: after an outage, one late nudge per reminder type instead of one per missed day per tick. Local data shows the old behaviour sent 10 nudges in 5 minutes today, ending in the minute the files were written. Authorship UNKNOWN.

### Production State
- **Verified:** `azkar-telegram-bot.vercel.app` returns `402` with `X-Vercel-Error: DEPLOYMENT_DISABLED` on `/up` and `/internal/health`. A local poller/worker/scheduler is running on local SQLite and sent real nudges today.
- **Inferred:** the latest code targets Vercel (Docker container) + Neon PostgreSQL + cron-job.org; that deployment's DB is PostgreSQL by entrypoint enforcement.
- **Unknown:** which instance is "production" now; webhook registration; Neon status; why Vercel is disabled.

### Content Model
- Bedtime/waking **fit the existing content tables without a migration**. The PHP enum `AdhkarCategory` and the hard-coded lists in `ContentSyncService` block it in code.

### Manual Collections
- Files likely to change, minimum: **6 existing application files** (`AdhkarCategory.php`, `ContentSyncService.php`, `AdhkarContentService.php`, `AdhkarPdfService.php`, `TelegramUpdateProcessor.php`, `KeyboardFactory.php`) + **2 new data files**. Likely also `lang/ar/bot.php`; conditionally `ReminderType.php` / `config/adhkar.php`; plus 3–4 test files and docs.
- **Migration needed: no.**
- **Reader reusable: yes.** Only its today-scope entry (`m`/`e` period mapping) and four prefix-based labels need generalizing.

### Reminders
- Separate because they need per-type time/next-run storage that does not exist, which means a **migration**. The coupling is in `users` (`morning_time`, `evening_time`, `next_morning_at`, `next_evening_at` + 2 indexes), `ReminderType`, `ReminderDispatchService` passes/ternaries, `SendAdhkarReminderJob::markFailed`, `UserSettingsService`, `OnboardingService`, `completionProblem`, and nudge/completion copy. `reminder_logs` and its unique key can already hold the new type strings.

### Blockers
- No architectural blocker for Phase A.
- No verified serving production target (Vercel `DEPLOYMENT_DISABLED`).

### Unknowns
- Authorship of the ReminderDispatch change; reason for the report deletion; Vercel disable reason; Neon status; webhook target; Vercel build configuration; approved bedtime/waking content.

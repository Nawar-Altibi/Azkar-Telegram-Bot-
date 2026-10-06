<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the external wake gate (a Cloudflare Worker) when the schedule changed outside a cron tick,
 * for example a new reminder time, so it wakes the bot on time instead of at its last known moment.
 */
class WakeGate
{
    private bool $dirty = false;

    private bool $flushRegistered = false;

    public function __construct(private readonly WakeScheduleService $schedule) {}

    public function enabled(): bool
    {
        return filled(config('services.wake_gate.url')) && filled(config('services.cron.secret'));
    }

    /**
     * Pushed once after the response is sent, however many users changed in the request.
     */
    public function scheduleChanged(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->dirty = true;
        if (! $this->flushRegistered) {
            $this->flushRegistered = true;
            app()->terminating(fn () => $this->flush());
        }
    }

    /**
     * A cron tick reports the next wake in its own response, so nothing is pushed after it.
     */
    public function reportedByTick(): void
    {
        $this->dirty = false;
    }

    public function flush(): void
    {
        if (! $this->dirty) {
            return;
        }
        $this->dirty = false;

        try {
            // Lets the gate drop a tick's answer that was computed before this change but arrives after it.
            $computedAt = now()->getTimestampMs();
            Http::timeout(5)
                ->withToken((string) config('services.cron.secret'))
                ->post(rtrim((string) config('services.wake_gate.url'), '/').'/next', [
                    'next_wake_at' => $this->schedule->nextWakeAt()?->getTimestamp(),
                    'computed_at_ms' => $computedAt,
                ])
                ->throw();
        } catch (Throwable $exception) {
            // The gate still wakes the bot at its safety interval, so a failed push only delays.
            Log::warning('wake_gate_push_failed', ['exception' => $exception::class]);
        }
    }
}

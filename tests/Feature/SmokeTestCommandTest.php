<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmokeTestCommandTest extends TestCase
{
    public function test_preflight_passes_after_sync(): void
    {
        $this->artisan('adhkar:sync')->assertSuccessful();

        $this->artisan('adhkar:smoke')
            ->expectsOutputToContain('ok  webhook route')
            ->expectsOutputToContain('Preflight complete')
            ->assertSuccessful();
    }

    public function test_live_mode_calls_telegram_get_me(): void
    {
        $this->artisan('adhkar:sync')->assertSuccessful();

        Http::fake([
            'https://api.telegram.org/bottest-token/getMe' => Http::response([
                'ok' => true,
                'result' => ['id' => 1, 'is_bot' => true, 'username' => 'azkar_bot'],
            ], 200),
            'https://api.telegram.org/bottest-token/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => ['url' => 'https://example.com/telegram/webhook/test-secret'],
            ], 200),
        ]);

        $this->artisan('adhkar:smoke', ['--live' => true])
            ->expectsOutputToContain('Telegram getMe: @azkar_bot')
            ->assertSuccessful();
    }

    public function test_health_endpoint_returns_200(): void
    {
        $this->get('/up')->assertOk();
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

class SchedulerCronTest extends TestCase
{
    public function test_missing_secret_returns_403(): void
    {
        $this->postJson('/internal/cron')->assertStatus(403);
    }

    public function test_wrong_secret_returns_403(): void
    {
        $this->postJson('/internal/cron', [], [
            'Authorization' => 'Bearer wrong-secret',
        ])->assertStatus(403);
    }

    public function test_valid_secret_runs_the_scheduler(): void
    {
        $this->postJson('/internal/cron', [], [
            'Authorization' => 'Bearer test-cron-secret',
        ])
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_empty_configured_secret_is_rejected(): void
    {
        config(['services.cron.secret' => '']);

        $this->postJson('/internal/cron', [], [
            'Authorization' => 'Bearer test-cron-secret',
        ])->assertStatus(403);
    }
}

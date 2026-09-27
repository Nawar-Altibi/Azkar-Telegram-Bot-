<?php

namespace Tests\Feature;

use App\Enums\ReminderType;
use App\Models\AdhkarItem;
use App\Models\ReminderLog;
use App\Models\TelegramUpdate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Runs only when the suite is pointed at PostgreSQL, e.g.
 * DB_CONNECTION=pgsql DB_URL=pgsql://user:pass@127.0.0.1:5432/azkar_test php artisan test
 */
class PostgresBehaviourTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-only checks; run the suite with DB_CONNECTION=pgsql.');
        }
    }

    public function test_column_types_match_the_expected_postgres_types(): void
    {
        $expected = [
            ['users', 'reminders_enabled', 'boolean'],
            ['adhkar_items', 'active', 'boolean'],
            ['reminder_logs', 'telegram_message_id', 'json'],
            ['reminder_logs', 'content_version_map', 'json'],
            ['telegram_updates', 'payload', 'json'],
            ['users', 'next_morning_at', 'timestamp without time zone'],
            ['users', 'next_evening_at', 'timestamp without time zone'],
            ['reminder_logs', 'scheduled_for', 'timestamp without time zone'],
            ['users', 'telegram_id', 'bigint'],
        ];

        foreach ($expected as [$table, $column, $type]) {
            $actual = DB::table('information_schema.columns')
                ->where('table_schema', 'public')
                ->where('table_name', $table)
                ->where('column_name', $column)
                ->value('data_type');

            $this->assertSame($type, $actual, "{$table}.{$column}");
        }
    }

    public function test_boolean_json_and_timestamp_values_round_trip(): void
    {
        $morning = Carbon::parse('2026-09-28 03:00:00');
        $user = User::factory()->create([
            'reminders_enabled' => false,
            'next_morning_at' => $morning,
        ]);
        $item = AdhkarItem::factory()->create(['active' => false]);

        $log = ReminderLog::factory()->create([
            'user_id' => $user->id,
            'telegram_message_id' => [101, 102],
            'content_version_map' => ['morning.1' => 2],
            'scheduled_for' => $morning,
        ]);
        $update = TelegramUpdate::factory()->create([
            'update_id' => 424242,
            'payload' => ['update_id' => 424242, 'message' => ['text' => '/start']],
        ]);

        $this->assertFalse($user->fresh()->reminders_enabled);
        $this->assertFalse($item->fresh()->active);
        $this->assertSame(0, User::query()->where('reminders_enabled', true)->whereKey($user->id)->count());
        $this->assertSame([101, 102], $log->fresh()->telegram_message_id);
        $this->assertSame(['morning.1' => 2], $log->fresh()->content_version_map);
        $this->assertSame(424242, $update->fresh()->payload['update_id']);
        $this->assertTrue($morning->equalTo($user->fresh()->next_morning_at));
        $this->assertTrue($morning->equalTo($log->fresh()->scheduled_for));
    }

    public function test_foreign_key_violation_is_rejected(): void
    {
        try {
            DB::transaction(fn () => ReminderLog::factory()->create(['user_id' => 999999999]));
            $this->fail('Expected a foreign key violation.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0] ?? null);
        }
    }

    public function test_duplicate_reminder_day_raises_unique_constraint_violation(): void
    {
        $user = User::factory()->create();
        $attributes = [
            'user_id' => $user->id,
            'reminder_type' => ReminderType::Morning,
            'scheduled_local_date' => '2026-09-28',
        ];
        ReminderLog::factory()->create($attributes);

        try {
            DB::transaction(fn () => ReminderLog::factory()->create($attributes));
            $this->fail('Expected a unique constraint violation.');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame('23505', $e->errorInfo[0] ?? null);
        }

        $this->assertSame(1, ReminderLog::query()->where('user_id', $user->id)->count());
    }

    public function test_failed_transaction_rolls_back(): void
    {
        try {
            DB::transaction(function () {
                User::factory()->create(['telegram_id' => 777000111]);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, User::query()->where('telegram_id', 777000111)->count());
    }

    public function test_lock_for_update_blocks_a_second_connection(): void
    {
        config(['database.connections.pgsql_second' => config('database.connections.'.DB::getDefaultConnection())]);
        $second = DB::connection('pgsql_second');
        $key = 'pg-lock-test-'.Str::random(12);

        $second->table('cache')->insert(['key' => $key, 'value' => 'x', 'expiration' => time() + 60]);
        $this->beforeApplicationDestroyed(function () use ($key) {
            DB::connection('pgsql_second')->table('cache')->where('key', $key)->delete();
            DB::disconnect('pgsql_second');
        });

        DB::transaction(function () use ($second, $key) {
            $this->assertNotNull(DB::table('cache')->where('key', $key)->lockForUpdate()->first());

            try {
                $second->transaction(fn () => $second->select('select key from cache where key = ? for update nowait', [$key]));
                $this->fail('Expected the row to be locked.');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->errorInfo[0] ?? null);
            }
        });
    }
}

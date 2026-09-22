<?php

namespace Database\Factories;

use App\Enums\TelegramUpdateStatus;
use App\Models\TelegramUpdate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramUpdate>
 */
class TelegramUpdateFactory extends Factory
{
    protected $model = TelegramUpdate::class;

    public function definition(): array
    {
        $updateId = fake()->unique()->numberBetween(1, 2_000_000_000);

        return [
            'update_id' => $updateId,
            'chat_id' => 1001,
            'payload' => [
                'update_id' => $updateId,
                'message' => [
                    'message_id' => 1,
                    'date' => time(),
                    'chat' => ['id' => 1001, 'type' => 'private'],
                    'from' => ['id' => 1001, 'first_name' => 'Test'],
                    'text' => '/start',
                ],
            ],
            'status' => TelegramUpdateStatus::Pending,
            'attempts' => 0,
            'error_message' => null,
            'received_at' => now(),
            'processed_at' => null,
        ];
    }
}

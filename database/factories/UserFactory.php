<?php

namespace Database\Factories;

use App\Enums\AdhkarMode;
use App\Enums\OnboardingState;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'telegram_id' => fake()->unique()->numberBetween(100000, 999999999),
            'chat_id' => fake()->unique()->numberBetween(100000, 999999999),
            'first_name' => fake()->firstName(),
            'username' => fake()->userName(),
            'language_code' => 'ar',
            'timezone' => 'Asia/Damascus',
            'morning_time' => '06:00:00',
            'evening_time' => '18:00:00',
            'mode' => AdhkarMode::Short,
            'reminders_enabled' => true,
            'onboarding_state' => OnboardingState::Completed,
            'awaiting_input' => null,
            'next_morning_at' => now()->addHour(),
            'next_evening_at' => now()->addHours(8),
            'paused_at' => null,
            'last_interaction_at' => now(),
        ];
    }

    public function newUser(): static
    {
        return $this->state(fn () => [
            'timezone' => null,
            'morning_time' => null,
            'evening_time' => null,
            'reminders_enabled' => false,
            'onboarding_state' => OnboardingState::New,
            'next_morning_at' => null,
            'next_evening_at' => null,
        ]);
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('telegram_id')->unique();
            $table->unsignedBigInteger('chat_id')->unique();
            $table->string('first_name')->nullable();
            $table->string('username')->nullable();
            $table->string('language_code', 10)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->time('morning_time')->nullable();
            $table->time('evening_time')->nullable();
            $table->string('mode', 16)->default('short');
            $table->boolean('reminders_enabled')->default(false);
            $table->string('onboarding_state', 32)->default('new');
            $table->string('awaiting_input', 64)->nullable();
            $table->timestamp('next_morning_at')->nullable();
            $table->timestamp('next_evening_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('last_interaction_at')->nullable();
            $table->timestamps();

            $table->index(['reminders_enabled', 'next_morning_at'], 'users_reminders_morning_idx');
            $table->index(['reminders_enabled', 'next_evening_at'], 'users_reminders_evening_idx');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};

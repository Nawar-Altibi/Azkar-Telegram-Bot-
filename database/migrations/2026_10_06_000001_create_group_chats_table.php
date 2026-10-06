<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_chats', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->unique();
            $table->string('title')->nullable();
            $table->string('timezone')->nullable();
            $table->string('morning_time', 5)->default('06:00');
            $table->string('evening_time', 5)->default('17:00');
            $table->string('mode', 10)->default('short');
            $table->boolean('enabled')->default(false);
            $table->boolean('bot_member')->default(true);
            $table->timestamp('next_morning_at')->nullable()->index();
            $table->timestamp('next_evening_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_chats');
    }
};

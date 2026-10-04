<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('mode_chosen_at')->nullable()->after('mode');
            $table->boolean('keyboard_persistent')->default(true)->after('reminders_enabled');
            $table->string('reminder_style', 16)->default('nudge')->after('keyboard_persistent');
            $table->boolean('follow_up_enabled')->default(false)->after('reminder_style');
        });

        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->timestamp('follow_up_sent_at')->nullable()->after('reading_started_at');
        });

        Schema::create('bot_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 48);
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event', 'created_at']);
            $table->index(['user_id', 'event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_events');

        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->dropColumn('follow_up_sent_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mode_chosen_at', 'keyboard_persistent', 'reminder_style', 'follow_up_enabled']);
        });
    }
};

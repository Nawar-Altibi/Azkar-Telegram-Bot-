<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_chats', function (Blueprint $table) {
            $table->string('delivery_format', 16)->default('images')->after('mode');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('weekly_summary_enabled')->default(true)->after('follow_up_enabled');
            $table->timestamp('last_weekly_summary_at')->nullable()->after('weekly_summary_enabled');
            $table->timestamp('time_fit_asked_at')->nullable()->after('last_weekly_summary_at');
            $table->timestamp('comeback_sent_at')->nullable()->after('time_fit_asked_at');
            $table->timestamp('feedback_nudge_at')->nullable()->after('comeback_sent_at');
        });

        Schema::table('reading_sessions', function (Blueprint $table) {
            $table->json('card_times')->nullable()->after('furthest');
        });
    }

    public function down(): void
    {
        Schema::table('reading_sessions', function (Blueprint $table) {
            $table->dropColumn('card_times');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'weekly_summary_enabled',
                'last_weekly_summary_at',
                'time_fit_asked_at',
                'comeback_sent_at',
                'feedback_nudge_at',
            ]);
        });

        Schema::table('group_chats', function (Blueprint $table) {
            $table->dropColumn('delivery_format');
        });
    }
};

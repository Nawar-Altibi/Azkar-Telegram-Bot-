<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone_label')->nullable()->after('timezone');
        });

        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->timestamp('reading_started_at')->nullable()->after('sent_at');
            $table->timestamp('last_dispatch_attempt_at')->nullable()->after('dispatch_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('reminder_logs', function (Blueprint $table) {
            $table->dropColumn(['reading_started_at', 'last_dispatch_attempt_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('timezone_label');
        });
    }
};

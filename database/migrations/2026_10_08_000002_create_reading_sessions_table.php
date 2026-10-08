<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('reminder_log_id')->nullable()->index();
            $table->string('collection_key', 40);
            $table->char('phase', 1);
            $table->string('source', 10);
            $table->unsignedSmallInteger('total');
            $table->unsignedSmallInteger('furthest');
            $table->unsignedTinyInteger('local_hour')->nullable();
            $table->unsignedTinyInteger('local_weekday')->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('last_seen_at');
            $table->timestamp('completed_at')->nullable();
            $table->index(['user_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_sessions');
    }
};

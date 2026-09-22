<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reminder_type', 16);
            $table->string('collection_key');
            $table->date('scheduled_local_date');
            $table->timestamp('scheduled_for');
            $table->string('status', 16)->default('pending');
            $table->json('telegram_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->unsignedInteger('dispatch_attempts')->default(0);
            $table->json('content_version_map')->nullable();
            $table->char('content_snapshot_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'reminder_type', 'scheduled_local_date'], 'reminder_logs_unique_day');
            $table->index('status');
            $table->index('scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
    }
};

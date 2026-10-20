<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adhkar_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('collection_group', 32);
            $table->date('local_date');
            $table->timestamp('completed_at');

            $table->unique(['user_id', 'collection_group', 'local_date'], 'adhkar_completions_user_group_day');
            $table->index(['collection_group', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adhkar_completions');
    }
};

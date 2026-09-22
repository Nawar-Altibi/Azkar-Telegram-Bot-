<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adhkar_items', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('category', 32);
            $table->string('title')->nullable();
            $table->text('arabic_text');
            $table->unsignedInteger('repetitions_count')->nullable();
            $table->string('repetitions_label')->nullable();
            $table->string('timing')->nullable();
            $table->text('virtue')->nullable();
            $table->text('source_text')->nullable();
            $table->string('source_name')->nullable();
            $table->string('hadith_number')->nullable();
            $table->string('hadith_grade')->nullable();
            $table->string('narrator')->nullable();
            $table->text('guidance_note')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('content_version')->default(1);
            $table->char('source_hash', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adhkar_items');
    }
};

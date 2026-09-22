<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adhkar_collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('adhkar_collections')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('adhkar_items')->cascadeOnDelete();
            $table->unsignedInteger('order')->default(0);
            $table->unique(['collection_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adhkar_collection_items');
    }
};

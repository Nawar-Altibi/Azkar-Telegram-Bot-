<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('delivery_format', 16)->default('images')->after('feedback_nudge_at');
            $table->boolean('delivery_hint_seen')->default(false)->after('delivery_format');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['delivery_format', 'delivery_hint_seen']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only change journal. The auto-increment `id` is the server
     * revision delivered to clients through the sync cursor. Server time
     * (`created_at`) is authoritative; clients never compare their own clocks.
     */
    public function up(): void
    {
        Schema::create('change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entity_type');
            $table->string('entity_id', 36);
            $table->string('operation', 16);
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['user_id', 'id']);
            $table->index(['entity_type', 'entity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('change_logs');
    }
};

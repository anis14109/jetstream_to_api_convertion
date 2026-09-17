<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Persists the last server revision consumed by each client so a client
     * that loses its local cursor can ask the server where it left off. The
     * client id is the auth session id for logged-in devices, or the access
     * token id for programmatic tokens.
     */
    public function up(): void
    {
        Schema::create('sync_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('client_id', 64);
            $table->unsignedBigInteger('cursor')->default(0);
            $table->timestamp('updated_at')->useCurrent();

            $table->unique(['user_id', 'client_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_cursors');
    }
};

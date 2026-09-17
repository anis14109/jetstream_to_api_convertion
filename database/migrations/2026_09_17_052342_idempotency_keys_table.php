<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tracks the hashed operation ids used by push endpoints so a retried
     * request can never create duplicate data. The `key_hash` is unique per
     * user and derived from the client-provided `operation_id`.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key_hash', 64);
            $table->string('request_hash', 64);
            $table->string('entity_type');
            $table->string('entity_id', 36);
            $table->string('operation', 16);
            $table->unsignedInteger('response_code')->default(200);
            $table->json('response_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'key_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

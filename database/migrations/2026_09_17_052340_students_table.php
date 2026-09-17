<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Demonstrates the offline-first synchronization contract on a real,
     * synchronizable resource. The `version` column drives optimistic
     * concurrency control and `deleted_at` keeps tombstones for delete sync.
     */
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'deleted_at']);
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};

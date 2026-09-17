<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `completed_at` separates an in-flight reservation (created before the
     * business operation runs, so concurrent requests with the same operation
     * id cannot both execute it) from a completed one that can be replayed.
     */
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('response_json');
        });

        // Rows created before reservations existed are, by definition, complete.
        DB::table('idempotency_keys')
            ->whereNull('completed_at')
            ->update(['completed_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};

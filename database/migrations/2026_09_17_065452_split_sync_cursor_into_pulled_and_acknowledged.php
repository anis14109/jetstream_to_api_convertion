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
     * Split the single client cursor into two checkpoints:
     *
     *  - `last_pulled_cursor`: highest revision delivered to the client.
     *  - `acknowledged_cursor`: highest revision the client confirmed applied.
     *
     * The legacy `cursor` advanced on pull, so its value becomes the delivered
     * checkpoint. The acknowledged checkpoint starts at zero: existing clients
     * must ACK explicitly before pruning may consider their changes consumed.
     */
    public function up(): void
    {
        Schema::table('sync_cursors', function (Blueprint $table) {
            $table->unsignedBigInteger('last_pulled_cursor')->default(0)->after('client_id');
            $table->unsignedBigInteger('acknowledged_cursor')->default(0)->after('last_pulled_cursor');
        });

        DB::table('sync_cursors')->update(['last_pulled_cursor' => DB::raw('cursor')]);

        Schema::table('sync_cursors', function (Blueprint $table) {
            $table->dropColumn('cursor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sync_cursors', function (Blueprint $table) {
            $table->unsignedBigInteger('cursor')->default(0)->after('client_id');
        });

        DB::table('sync_cursors')->update(['cursor' => DB::raw('acknowledged_cursor')]);

        Schema::table('sync_cursors', function (Blueprint $table) {
            $table->dropColumn(['last_pulled_cursor', 'acknowledged_cursor']);
        });
    }
};

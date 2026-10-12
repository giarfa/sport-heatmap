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
     * Existing rows all came from the Strava sync, so the column default backfills
     * them as `strava`. No new index: every query on this table — the heatmap
     * aggregate, the sync's reconcile, the Garmin import's conflict lookup — filters
     * on (user_id, activity_date) first, and that index already exists.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('source')->default('strava');

            // Garmin-imported runs have no Strava id. The unique index stays: an
            // overwritten Strava twin keeps its id so the sync can recognise it, and
            // SQLite allows any number of NULLs under a unique index.
            $table->unsignedBigInteger('strava_activity_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Lossy by necessity: a Garmin-imported row has no Strava id, and the old schema
     * cannot hold one, so those rows are deleted before the column becomes NOT NULL
     * again. Garmin-overwritten twins keep their Strava id and survive, reverting to
     * plain Strava rows that the next sync overwrites.
     */
    public function down(): void
    {
        DB::table('activities')->whereNull('strava_activity_id')->delete();

        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('strava_activity_id')->nullable(false)->change();
            $table->dropColumn('source');
        });
    }
};

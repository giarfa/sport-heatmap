<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive: every stored run is a Garmin Connect CSV import, which is exactly what
     * the column default backfills. The unique index on `garmin_activity_id` is what
     * makes a second sync idempotent at the database level, and SQLite allows any
     * number of NULLs under it, so CSV runs that were never linked stay valid.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('source')->default('garmin_csv');

            /*
             * An integer, not a string: Garmin's activity ids are 11 digits today and the
             * API answers them as JSON numbers, so a bigint compares and indexes them
             * without a cast on either side.
             */
            $table->unsignedBigInteger('garmin_activity_id')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Lossy only in the link: the runs stay, but a run linked to Garmin forgets its
     * id, so a later sync would create it again.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            /*
             * The unique index first: SQLite rebuilds the table to drop a column and
             * fails on an index that still names it (the same order as
             * `remove_strava_from_schema`).
             */
            $table->dropUnique(['garmin_activity_id']);
            $table->dropColumn(['garmin_activity_id', 'source']);
        });
    }
};

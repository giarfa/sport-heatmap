<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Irreversible by the owner's decision (US-021, accepted 2026-10-26): Strava
     * is removed, so the runs it supplied and the tokens it left behind go with it.
     */
    public function up(): void
    {
        /*
         * The query builder, not the Activity model: a migration has to keep
         * running after the model changes. Only `strava` rows go. A run the owner
         * overwrote from a Garmin CSV is already `garmin_csv` — it carries
         * Garmin's values and keeps every one of them, even though it still holds
         * its old Strava id until the column is dropped just below.
         */
        DB::table('activities')->where('source', 'strava')->delete();

        Schema::table('activities', function (Blueprint $table) {
            /*
             * The unique index first: SQLite rebuilds the table to drop a column
             * and fails on an index that still names it. `source` is dropped, not
             * narrowed to one value — with a single source the column says
             * nothing, and a column that cannot differ invites a reader to look
             * for the other value.
             */
            $table->dropUnique(['strava_activity_id']);
            $table->dropColumn(['strava_activity_id', 'source']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['strava_athlete_id']);
            $table->dropColumn([
                'strava_athlete_id',
                'strava_access_token',
                'strava_refresh_token',
                'strava_token_expires_at',
            ]);
        });

        Schema::dropIfExists('sync_runs');

        /*
         * The deleted runs are inside every cached grid window, and a remembered
         * Garmin import plan can still name one of them as a conflict. The store
         * holds nothing that cannot be rebuilt on the next request, so everything
         * is dropped rather than guessing which keys carried a Strava run.
         */
        Cache::flush();
    }

    /**
     * Reverse the migrations.
     *
     * Restores the schema only. The deleted Strava runs, the tokens and the sync
     * history cannot come back: there is no copy of them to restore from.
     */
    public function down(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('status');
            $table->unsignedInteger('activities_upserted')->default(0);
            $table->unsignedInteger('activities_deleted')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index('started_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('strava_athlete_id')->nullable()->unique()->after('email_verified_at');

            // TEXT, not VARCHAR: an encrypted cast produces ciphertext of unpredictable length.
            $table->text('strava_access_token')->nullable()->after('strava_athlete_id');
            $table->text('strava_refresh_token')->nullable()->after('strava_access_token');
            $table->timestamp('strava_token_expires_at')->nullable()->after('strava_refresh_token');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedBigInteger('strava_activity_id')->nullable()->unique()->after('id');

            // Every surviving row is a Garmin import, which is what the default says.
            $table->string('source')->default('garmin_csv');
        });
    }
};

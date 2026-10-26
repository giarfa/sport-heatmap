<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
        });

        /*
         * The query builder, not the User model: a migration has to keep running
         * after the model changes, and an Eloquent write would fire model events
         * and bump updated_at on a row the owner did not edit. The owner keeps
         * every other column — runs, Strava tokens, name, email — and only
         * gains the sign-in identity with an empty password, which arms the
         * forced first password change.
         */
        $ownerId = $this->ownerId();

        if ($ownerId === null) {
            /*
             * Empty database: create the record. .invalid is the RFC 2606
             * reserved TLD, so nothing is ever sent to this address.
             */
            DB::table('users')->insert([
                'name' => 'giarfa',
                'username' => 'giarfa',
                'email' => 'giarfa@heatmap.invalid',
                'password' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        DB::table('users')->where('id', $ownerId)->update([
            'username' => 'giarfa',
            'password' => null,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }

    /**
     * The row that becomes the owner: the connected allowlisted athlete, else
     * the lowest id with runs, else the lowest id.
     */
    private function ownerId(): ?int
    {
        /*
         * The config read carries a [] default because US-021 deletes
         * config/strava.php: this migration must still run on a fresh database
         * after that, where only the later branches apply.
         */
        foreach ((array) config('strava.allowed_athlete_ids', []) as $stravaAthleteId) {
            $id = DB::table('users')->where('strava_athlete_id', (string) $stravaAthleteId)->value('id');

            if ($id !== null) {
                return (int) $id;
            }
        }

        $withActivities = DB::table('users')
            ->whereIn('id', DB::table('activities')->select('user_id'))
            ->min('id');

        if ($withActivities !== null) {
            return (int) $withActivities;
        }

        $lowest = DB::table('users')->min('id');

        return $lowest === null ? null : (int) $lowest;
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('strava_athlete_id')->nullable()->unique()->after('email_verified_at');

            // TEXT, not VARCHAR: the Eloquent "encrypted" cast produces ciphertext
            // of unpredictable length, which a fixed-width string column truncates.
            $table->text('strava_access_token')->nullable()->after('strava_athlete_id');
            $table->text('strava_refresh_token')->nullable()->after('strava_access_token');

            $table->timestamp('strava_token_expires_at')->nullable()->after('strava_refresh_token');

            // There is no password login in this product: identity comes from Strava.
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['strava_athlete_id']);

            $table->dropColumn([
                'strava_athlete_id',
                'strava_access_token',
                'strava_refresh_token',
                'strava_token_expires_at',
            ]);

            $table->string('password')->nullable(false)->change();
        });
    }
};

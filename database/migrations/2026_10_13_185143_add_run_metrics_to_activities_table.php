<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Three optional per-run metrics, all nullable with no default: NULL means "not
     * recorded" and is shown as an em dash, while 0 would read as a real value — a
     * zero heart rate or a zero-second pace is never a measurement. No index: they are
     * read only with the run's own row, and every query on this table still filters on
     * (user_id, activity_date) first.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedInteger('average_pace_seconds_per_km')->nullable();
            $table->unsignedInteger('total_ascent_meters')->nullable();
            $table->unsignedInteger('average_heart_rate')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Lossless for everything else: the metrics are additive, no other column depends
     * on them.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn([
                'average_pace_seconds_per_km',
                'total_ascent_meters',
                'average_heart_rate',
            ]);
        });
    }
};

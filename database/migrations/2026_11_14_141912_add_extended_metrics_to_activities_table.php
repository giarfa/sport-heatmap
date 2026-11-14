<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The extended run metrics of FR-022, from the Garmin CSV and the Garmin Connect API.
     * Every column is nullable with no default: NULL means "not recorded", while 0 would
     * read as a measurement — a zero cadence or a zero-calorie run is never real. The
     * only zeros kept are the ones Garmin really reports (descent, elevation, time in a
     * heart-rate zone, aerobic training effect), and those are decided by the DTO that
     * writes them, not by the schema. No index: these are read only with the run's own
     * row, and every query on this table still filters on (user_id, activity_date) first.
     * No coordinate column either: start and end positions are never stored.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedInteger('calories')->nullable();
            $table->unsignedSmallInteger('max_heart_rate')->nullable();
            $table->decimal('aerobic_training_effect', 3, 1)->nullable();
            $table->unsignedSmallInteger('average_running_cadence')->nullable();
            $table->unsignedSmallInteger('max_running_cadence')->nullable();
            $table->unsignedInteger('best_pace_seconds_per_km')->nullable();
            $table->unsignedInteger('total_descent_meters')->nullable();
            $table->decimal('average_stride_length_cm', 5, 1)->nullable();
            $table->decimal('average_vertical_ratio_percent', 4, 1)->nullable();
            $table->decimal('average_vertical_oscillation_cm', 4, 1)->nullable();
            $table->unsignedSmallInteger('average_ground_contact_time_ms')->nullable();
            $table->decimal('average_ground_contact_balance_left_percent', 4, 1)->nullable();
            $table->unsignedInteger('steps')->nullable();
            $table->unsignedSmallInteger('lap_count')->nullable();
            $table->unsignedInteger('elapsed_time_seconds')->nullable();
            $table->integer('min_elevation_meters')->nullable();
            $table->integer('max_elevation_meters')->nullable();
            $table->decimal('training_stress_score', 6, 1)->nullable();
            $table->unsignedInteger('hr_zone_1_seconds')->nullable();
            $table->unsignedInteger('hr_zone_2_seconds')->nullable();
            $table->unsignedInteger('hr_zone_3_seconds')->nullable();
            $table->unsignedInteger('hr_zone_4_seconds')->nullable();
            $table->unsignedInteger('hr_zone_5_seconds')->nullable();
            $table->unsignedInteger('fastest_1k_seconds')->nullable();
            $table->unsignedInteger('fastest_mile_seconds')->nullable();
            $table->unsignedInteger('fastest_5k_seconds')->nullable();
            $table->string('location_name', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Lossless for everything else: the metrics are additive, so only the columns this
     * migration added are dropped and every run keeps its other values.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn([
                'calories',
                'max_heart_rate',
                'aerobic_training_effect',
                'average_running_cadence',
                'max_running_cadence',
                'best_pace_seconds_per_km',
                'total_descent_meters',
                'average_stride_length_cm',
                'average_vertical_ratio_percent',
                'average_vertical_oscillation_cm',
                'average_ground_contact_time_ms',
                'average_ground_contact_balance_left_percent',
                'steps',
                'lap_count',
                'elapsed_time_seconds',
                'min_elevation_meters',
                'max_elevation_meters',
                'training_stress_score',
                'hr_zone_1_seconds',
                'hr_zone_2_seconds',
                'hr_zone_3_seconds',
                'hr_zone_4_seconds',
                'hr_zone_5_seconds',
                'fastest_1k_seconds',
                'fastest_mile_seconds',
                'fastest_5k_seconds',
                'location_name',
            ]);
        });
    }
};

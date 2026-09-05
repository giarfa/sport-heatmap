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
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('strava_activity_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('sport_type');
            $table->string('name');

            // Metres and seconds, always integers: kilometres and pace are a
            // presentation concern only (FR-003).
            $table->integer('distance_meters');
            $table->integer('moving_time_seconds');

            $table->dateTime('start_date_local');
            $table->date('activity_date');
            $table->timestamps();

            // The only query the heatmap makes: one athlete's activities over a
            // date window, grouped by day.
            $table->index(['user_id', 'activity_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};

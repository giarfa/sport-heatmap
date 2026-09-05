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
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');

            // Null while a run is still in flight.
            $table->dateTime('finished_at')->nullable();

            $table->string('status');
            $table->unsignedInteger('activities_upserted')->default(0);
            $table->unsignedInteger('activities_deleted')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            // The dashboard asks one question only: what was the most recent run?
            $table->index('started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per sync attempt. No column can hold the API key, the access token, a
     * request URL or a response body: the failure is stored as a cause from a closed
     * list, so a secret has nowhere to leak to.
     *
     * The (outcome, finished_at) index serves the two status queries the dashboard
     * makes on every request: the newest succeeded attempt and the newest finished one.
     */
    public function up(): void
    {
        Schema::create('garmin_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('trigger');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('outcome');
            $table->string('failure_cause')->nullable();
            $table->boolean('full_history')->default(false);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('linked_count')->default(0);
            $table->unsignedInteger('ignored_count')->default(0);
            $table->unsignedInteger('future_dated_count')->default(0);
            $table->unsignedInteger('invalid_count')->default(0);
            $table->timestamps();

            $table->index(['outcome', 'finished_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('garmin_syncs');
    }
};

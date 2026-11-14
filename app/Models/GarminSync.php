<?php

namespace App\Models;

use App\Enums\GarminSyncFailure;
use App\Enums\GarminSyncOutcome;
use App\Enums\GarminSyncTrigger;
use Database\Factories\GarminSyncFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One attempt to read the owner's runs from Garmin Connect.
 *
 * @property int $id
 * @property GarminSyncTrigger $trigger
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property GarminSyncOutcome $outcome
 * @property GarminSyncFailure|null $failure_cause
 * @property bool $full_history
 * @property int $new_count
 * @property int $updated_count
 * @property int $linked_count
 * @property int $ignored_count
 * @property int $future_dated_count
 * @property int $invalid_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'trigger',
    'started_at',
    'finished_at',
    'outcome',
    'failure_cause',
    'full_history',
    'new_count',
    'updated_count',
    'linked_count',
    'ignored_count',
    'future_dated_count',
    'invalid_count',
])]
class GarminSync extends Model
{
    /** @use HasFactory<GarminSyncFactory> */
    use HasFactory;

    /**
     * Restrict to attempts that ended well, even one that wrote nothing.
     *
     * @param  Builder<GarminSync>  $query
     */
    #[Scope]
    protected function succeeded(Builder $query): void
    {
        $query->where('outcome', GarminSyncOutcome::Succeeded->value);
    }

    /**
     * Restrict to attempts that have ended, whichever way: a running attempt has no
     * verdict to show yet.
     *
     * @param  Builder<GarminSync>  $query
     */
    #[Scope]
    protected function finished(Builder $query): void
    {
        $query->whereNotNull('finished_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'trigger' => GarminSyncTrigger::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'outcome' => GarminSyncOutcome::class,
            'failure_cause' => GarminSyncFailure::class,
            'full_history' => 'boolean',
            'new_count' => 'integer',
            'updated_count' => 'integer',
            'linked_count' => 'integer',
            'ignored_count' => 'integer',
            'future_dated_count' => 'integer',
            'invalid_count' => 'integer',
        ];
    }
}

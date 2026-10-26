<?php

namespace App\Models;

use App\Enums\SportType;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property SportType $sport_type
 * @property string $name
 * @property int $distance_meters
 * @property int $moving_time_seconds
 * @property Carbon $start_date_local
 * @property Carbon $activity_date
 * @property int|null $average_pace_seconds_per_km
 * @property int|null $total_ascent_meters
 * @property int|null $average_heart_rate
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
/*
 * user_id is deliberately not mass-assignable: activity ownership must come from the
 * relation the import is writing through ($user->activities()->create(...)), never
 * from an attribute array that a request could reach.
 */
#[Fillable([
    'sport_type',
    'name',
    'distance_meters',
    'moving_time_seconds',
    'start_date_local',
    'activity_date',
    'average_pace_seconds_per_km',
    'total_ascent_meters',
    'average_heart_rate',
])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /**
     * The athlete this activity belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Restrict to runs on exactly the given days.
     *
     * @param  Builder<Activity>  $query
     * @param  array<int, string>  $dates  Y-m-d
     */
    #[Scope]
    protected function onDates(Builder $query, array $dates): void
    {
        /*
         * The dates are bound as CarbonImmutable on purpose: the grammar writes them as
         * "Y-m-d H:i:s", the very format the `date` cast stores activity_date in, so the
         * equality matches. Binding the Y-m-d strings would compare "2026-10-11" with
         * "2026-10-11 00:00:00" as TEXT and match nothing. A range belongs to
         * onDaysBetween() instead.
         */
        $query->whereIn('activity_date', array_map(
            static fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, 'UTC'),
            $dates,
        ));
    }

    /**
     * Restrict to runs on the given days, both ends included.
     *
     * @param  Builder<Activity>  $query
     */
    #[Scope]
    protected function onDaysBetween(Builder $query, CarbonInterface $firstDay, CarbonInterface $lastDay): void
    {
        /*
         * The `date` cast writes activity_date as "Y-m-d 00:00:00", and SQLite compares the
         * column as TEXT. `<= 'Y-m-d'` therefore excluded the window's last day, because
         * "2026-10-11 00:00:00" sorts after "2026-10-11" — and the last day of a window is
         * always a Sunday, the day of the long run. The range is half-open, `>=` the first
         * day and `<` the day after the last, so it holds whether a row carries "Y-m-d" or
         * "Y-m-d 00:00:00" and never depends on how the row was written. Not whereDate():
         * it wraps the column in strftime() and the (user_id, activity_date) index no
         * longer serves the query.
         */
        $query->where('activity_date', '>=', $firstDay->toDateString())
            ->where('activity_date', '<', CarbonImmutable::instance($lastDay)->addDay()->toDateString());
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sport_type' => SportType::class,
            'distance_meters' => 'integer',
            'moving_time_seconds' => 'integer',
            'start_date_local' => 'datetime',
            'activity_date' => 'date',
            'average_pace_seconds_per_km' => 'integer',
            'total_ascent_meters' => 'integer',
            'average_heart_rate' => 'integer',
        ];
    }
}

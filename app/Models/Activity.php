<?php

namespace App\Models;

use App\Enums\ActivitySource;
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
 * @property int|null $calories
 * @property int|null $max_heart_rate
 * @property float|null $aerobic_training_effect
 * @property int|null $average_running_cadence
 * @property int|null $max_running_cadence
 * @property int|null $best_pace_seconds_per_km
 * @property int|null $total_descent_meters
 * @property float|null $average_stride_length_cm
 * @property float|null $average_vertical_ratio_percent
 * @property float|null $average_vertical_oscillation_cm
 * @property int|null $average_ground_contact_time_ms
 * @property float|null $average_ground_contact_balance_left_percent
 * @property int|null $steps
 * @property int|null $lap_count
 * @property int|null $elapsed_time_seconds
 * @property int|null $min_elevation_meters
 * @property int|null $max_elevation_meters
 * @property float|null $training_stress_score
 * @property int|null $hr_zone_1_seconds
 * @property int|null $hr_zone_2_seconds
 * @property int|null $hr_zone_3_seconds
 * @property int|null $hr_zone_4_seconds
 * @property int|null $hr_zone_5_seconds
 * @property int|null $fastest_1k_seconds
 * @property int|null $fastest_mile_seconds
 * @property int|null $fastest_5k_seconds
 * @property string|null $location_name
 * @property ActivitySource $source
 * @property int|null $garmin_activity_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
/*
 * user_id is deliberately not mass-assignable: activity ownership must come from the
 * relation the import is writing through ($user->activities()->create(...)), never
 * from an attribute array that a request could reach. `source` and `garmin_activity_id`
 * are left out for the same reason: they say where a run came from and which Garmin
 * activity it is, so only the code that does the linking assigns them, explicitly. An
 * import row or a request body can never plant an id that would make a later sync
 * overwrite, or refuse to create, an unrelated run.
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
            'calories' => 'integer',
            'max_heart_rate' => 'integer',
            'aerobic_training_effect' => 'float',
            'average_running_cadence' => 'integer',
            'max_running_cadence' => 'integer',
            'best_pace_seconds_per_km' => 'integer',
            'total_descent_meters' => 'integer',
            'average_stride_length_cm' => 'float',
            'average_vertical_ratio_percent' => 'float',
            'average_vertical_oscillation_cm' => 'float',
            'average_ground_contact_time_ms' => 'integer',
            'average_ground_contact_balance_left_percent' => 'float',
            'steps' => 'integer',
            'lap_count' => 'integer',
            'elapsed_time_seconds' => 'integer',
            'min_elevation_meters' => 'integer',
            'max_elevation_meters' => 'integer',
            'training_stress_score' => 'float',
            'hr_zone_1_seconds' => 'integer',
            'hr_zone_2_seconds' => 'integer',
            'hr_zone_3_seconds' => 'integer',
            'hr_zone_4_seconds' => 'integer',
            'hr_zone_5_seconds' => 'integer',
            'fastest_1k_seconds' => 'integer',
            'fastest_mile_seconds' => 'integer',
            'fastest_5k_seconds' => 'integer',
            'location_name' => 'string',
            'source' => ActivitySource::class,
            'garmin_activity_id' => 'integer',
        ];
    }
}

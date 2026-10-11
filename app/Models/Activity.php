<?php

namespace App\Models;

use App\Enums\ActivitySource;
use App\Enums\SportType;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $strava_activity_id
 * @property int $user_id
 * @property SportType $sport_type
 * @property string $name
 * @property int $distance_meters
 * @property int $moving_time_seconds
 * @property Carbon $start_date_local
 * @property Carbon $activity_date
 * @property ActivitySource $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
/*
 * user_id is deliberately not mass-assignable: activity ownership must come from the
 * relation the sync is writing through ($user->activities()->updateOrCreate(...)), never
 * from an attribute array that a request could reach.
 */
#[Fillable([
    'strava_activity_id',
    'sport_type',
    'name',
    'distance_meters',
    'moving_time_seconds',
    'start_date_local',
    'activity_date',
    'source',
])]
class Activity extends Model
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    /**
     * Mirrors the column default, so a freshly created model reads its source
     * without a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => 'strava',
    ];

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
     * Whether a Garmin Connect CSV import wrote this activity's values — either as a
     * new run or by overwriting its Strava twin.
     */
    public function isGarminSourced(): bool
    {
        return $this->source === ActivitySource::GarminCsv;
    }

    /**
     * The Strava deep-link for this activity, or null when its values are not
     * Strava's (FR-008).
     */
    public function stravaUrl(): ?string
    {
        /*
         * A Garmin-overwritten twin still carries its Strava id so the sync can
         * recognise it, but the page that id opens shows Strava's values, not the
         * ones stored here. Linking it would contradict the row it sits beside.
         */
        if ($this->source !== ActivitySource::Strava || $this->strava_activity_id === null) {
            return null;
        }

        return "https://www.strava.com/activities/{$this->strava_activity_id}";
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strava_activity_id' => 'integer',
            'sport_type' => SportType::class,
            'distance_meters' => 'integer',
            'moving_time_seconds' => 'integer',
            'start_date_local' => 'datetime',
            'activity_date' => 'date',
            'source' => ActivitySource::class,
        ];
    }
}

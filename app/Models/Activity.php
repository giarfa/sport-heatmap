<?php

namespace App\Models;

use App\Enums\SportType;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'strava_activity_id',
    'user_id',
    'sport_type',
    'name',
    'distance_meters',
    'moving_time_seconds',
    'start_date_local',
    'activity_date',
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
        ];
    }
}

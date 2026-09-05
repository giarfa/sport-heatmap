<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string|null $strava_athlete_id
 * @property string|null $strava_access_token
 * @property string|null $strava_refresh_token
 * @property Carbon|null $strava_token_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Activity> $activities
 */
#[Fillable([
    'name',
    'email',
    'password',
    'strava_athlete_id',
    'strava_access_token',
    'strava_refresh_token',
    'strava_token_expires_at',
])]
#[Hidden([
    'password',
    'remember_token',
    'strava_access_token',
    'strava_refresh_token',
])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The activities synced from this athlete's Strava account.
     *
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'strava_access_token' => 'encrypted',
            'strava_refresh_token' => 'encrypted',
            'strava_token_expires_at' => 'datetime',
        ];
    }
}

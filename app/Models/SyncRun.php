<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property SyncRunStatus $status
 * @property int $activities_upserted
 * @property int $activities_deleted
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'started_at',
    'finished_at',
    'status',
    'activities_upserted',
    'activities_deleted',
    'error_message',
])]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'status' => SyncRunStatus::class,
            'activities_upserted' => 'integer',
            'activities_deleted' => 'integer',
        ];
    }
}

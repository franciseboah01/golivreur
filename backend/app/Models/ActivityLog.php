<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'activity_logs';

    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'properties',
        'ip_address',
        'severity',
    ];

    protected $casts = [
        'properties' => 'array',
        'user_id' => 'integer',
        'entity_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('Les journaux d\'audit sont strictement immuables (append-only) et ne peuvent pas être modifiés.');
        });

        static::deleting(function (): void {
            throw new \RuntimeException('Les journaux d\'audit sont strictement immuables (append-only) et ne peuvent pas être supprimés.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ParcelPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'parcel_id',
        'photo_url',
        'storage_path',
        'type',
        'lat',
        'lng',
    ];

    protected $hidden = [
        'storage_path',
    ];

    protected $appends = [
        'signed_url',
    ];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
    ];

    public static function storageDisk(): string
    {
        $configured = config('filesystems.parcel_photos_disk');

        return is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : 'parcel_photos';
    }

    public function getSignedUrlAttribute(): ?string
    {
        $storagePath = $this->attributes['storage_path'] ?? null;

        if (is_string($storagePath) && $storagePath !== '') {
            try {
                return Storage::disk(self::storageDisk())->temporaryUrl($storagePath, now()->addMinutes(15));
            } catch (\Throwable) {
                // Repli sûr si le disque ne supporte pas les URLs signées temporaires
            }
        }

        $legacyUrl = $this->attributes['photo_url'] ?? null;

        return is_string($legacyUrl) && $legacyUrl !== '' ? $legacyUrl : null;
    }

    /**
     * @return BelongsTo<Parcel, $this>
     */
    public function parcel(): BelongsTo
    {
        return $this->belongsTo(Parcel::class);
    }
}

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

    public function getSignedUrlAttribute(): ?string
    {
        $storagePath = $this->attributes['storage_path'] ?? null;

        if (is_string($storagePath) && $storagePath !== '') {
            return Storage::disk('s3')->temporaryUrl($storagePath, now()->addMinutes(15));
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

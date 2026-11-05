<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'merchant_id',
        'city_id',
        'name',
        'description',
        'price',
        'image_url',
        'category',
        'is_available',
        'options',
    ];

    protected $casts = [
        'price'        => 'integer',
        'is_available' => 'boolean',
        'options'      => 'array',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}


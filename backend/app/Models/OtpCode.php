<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'code_hash',
        'purpose',
        'attempts',
        'expires_at',
        'is_used',
    ];

    protected $casts = [
        'attempts'   => 'integer',
        'is_used'    => 'boolean',
        'expires_at' => 'datetime',
    ];
}


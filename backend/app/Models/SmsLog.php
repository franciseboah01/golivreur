<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Journal d'envoi SMS sans jamais stocker ni contenu, ni code OTP, ni téléphone en clair.
 */
class SmsLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone_masked',
        'motif',
        'driver',
        'status',
        'provider_message_id',
        'error_message',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}

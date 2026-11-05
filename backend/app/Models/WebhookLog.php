<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'event_type',
        'payload',
        'signature_received',
        'signature_valid',
        'status',
        'error_message',
        'retry_count',
        'horizon_job_id',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_valid' => 'boolean',
        'retry_count' => 'integer',
        'processed_at' => 'datetime',
    ];
}

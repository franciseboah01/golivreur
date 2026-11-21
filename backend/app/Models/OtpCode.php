<?php

namespace App\Models;

use App\Services\ConfirmationCodeService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read string|null $admin_otp_code
 */
class OtpCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'code_hash',
        'code_enc',
        'purpose',
        'attempts',
        'expires_at',
        'is_used',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'is_used' => 'boolean',
        'expires_at' => 'datetime',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'code_hash',
        'code_enc',
    ];

    /**
     * Déchiffre le code OTP uniquement pour l'interface d'administration.
     */
    public function getAdminOtpCodeAttribute(): ?string
    {
        return app(ConfirmationCodeService::class)->dechiffrer($this->code_enc);
    }
}

<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property-read string|null $logo_url
 * @property-read string $resolved_emoji
 */
class Merchant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'city_id',
        'name',
        'category',
        'emoji',
        'logo_path',
        'description',
        'address',
        'photo_path',
        'lat',
        'lng',
        'open_hours',
        'prep_time_minutes',
        'is_active',
        'is_open',
        'commission_rate',
        'average_rating',
        'total_ratings',
        'photo_url',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_open' => 'boolean',
        'open_hours' => 'array',
        'prep_time_minutes' => 'integer',
        'commission_rate' => 'float',
        'average_rating' => 'float',
        'total_ratings' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
    ];

    protected $appends = [
        'photo_url',
        'logo_url',
    ];

    /**
     * URL publique de la photo principale du commerce (vitrine / bannière).
     */
    public function getPhotoUrlAttribute(): ?string
    {
        if (! empty($this->attributes['photo_path'])) {
            return url('storage/'.$this->attributes['photo_path']);
        }

        if (! empty($this->attributes['photo_url'])) {
            return $this->attributes['photo_url'];
        }

        return $this->logo_url ?? url('images/placeholder-merchant.png');
    }

    /**
     * URL publique du logo de la boutique (si uploadé depuis l'admin).
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (! empty($this->attributes['logo_path'])) {
            return url('storage/'.$this->attributes['logo_path']);
        }

        return null;
    }

    /**
     * Emoji effectif : celui de la boutique, sinon celui de sa catégorie, sinon un fallback.
     */
    public function getResolvedEmojiAttribute(): string
    {
        if (! empty($this->attributes['emoji'])) {
            return $this->attributes['emoji'];
        }

        return $this->categoryModel?->emoji ?: '🏪';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return BelongsTo<MerchantCategory, $this>
     */
    public function categoryModel(): BelongsTo
    {
        return $this->belongsTo(MerchantCategory::class, 'category', 'slug');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Vérifie si le commerce est dans ses horaires d'ouverture configurés.
     * Si aucun horaire structuré n'est renseigné (`null` ou `[]`), le statut `is_open` fait foi.
     */
    public function estDansHorairesOuverture(?CarbonInterface $maintenant = null): bool
    {
        $horaires = $this->open_hours;
        if (! is_array($horaires) || $horaires === []) {
            return true;
        }

        $now = ($maintenant ? Carbon::instance($maintenant) : Carbon::now())
            ->timezone((string) config('app.timezone', 'Africa/Abidjan'));

        $jourAliases = [
            0 => ['sunday', 'sun', 'dimanche', 'dim'],
            1 => ['monday', 'mon', 'lundi', 'lun'],
            2 => ['tuesday', 'tue', 'mardi', 'mar'],
            3 => ['wednesday', 'wed', 'mercredi', 'mer'],
            4 => ['thursday', 'thu', 'jeudi', 'jeu'],
            5 => ['friday', 'fri', 'vendredi', 'ven'],
            6 => ['saturday', 'sat', 'samedi', 'sam'],
        ];

        $tousLesAliases = array_merge(...$jourAliases);
        $normalises = [];
        foreach ($horaires as $cle => $valeur) {
            $normalises[mb_strtolower(trim((string) $cle))] = $valeur;
        }

        $contientJours = count(array_intersect(array_keys($normalises), $tousLesAliases)) > 0;
        if ($contientJours) {
            $entreeJour = null;
            $trouve = false;
            foreach ($jourAliases[$now->dayOfWeek] as $alias) {
                if (array_key_exists($alias, $normalises)) {
                    $entreeJour = $normalises[$alias];
                    $trouve = true;
                    break;
                }
            }

            if (! $trouve) {
                return false;
            }

            return $this->estPlageHoraireActive($entreeJour, $now);
        }

        if (isset($normalises['open']) || isset($normalises['debut'])) {
            return $this->estPlageHoraireActive($normalises, $now);
        }

        $plageGlobale = $normalises['summary']
            ?? $normalises['default']
            ?? $normalises['all']
            ?? $normalises['tous_les_jours']
            ?? reset($normalises);

        return $this->estPlageHoraireActive($plageGlobale, $now);
    }

    private function estPlageHoraireActive(mixed $entree, Carbon $now): bool
    {
        if ($entree === null || $entree === false || $entree === '') {
            return false;
        }

        if (is_string($entree)) {
            $nettoye = mb_strtolower(trim($entree));
            if (in_array($nettoye, ['closed', 'ferme', 'fermé', 'off', '0'], true)) {
                return false;
            }

            if (preg_match('/^(\d{1,2})\s*[:h]\s*(\d{2})\s*[-–àa]+\s*(\d{1,2})\s*[:h]\s*(\d{2})$/u', $nettoye, $m) === 1) {
                return $this->heureDansIntervalle(
                    ((int) $m[1]) * 60 + (int) $m[2],
                    ((int) $m[3]) * 60 + (int) $m[4],
                    $now,
                );
            }

            return true;
        }

        if (is_array($entree)) {
            if (($entree['closed'] ?? false) === true || ($entree['open'] ?? true) === false) {
                return false;
            }

            $debut = $entree['open'] ?? $entree['debut'] ?? $entree['start'] ?? null;
            $fin = $entree['close'] ?? $entree['fin'] ?? $entree['end'] ?? null;

            if (is_string($debut) && is_string($fin)) {
                $minDebut = $this->parseHeureMinutes($debut);
                $minFin = $this->parseHeureMinutes($fin);
                if ($minDebut !== null && $minFin !== null) {
                    return $this->heureDansIntervalle($minDebut, $minFin, $now);
                }
            }
        }

        return true;
    }

    private function parseHeureMinutes(string $heure): ?int
    {
        if (preg_match('/^(\d{1,2})\s*[:h]\s*(\d{2})$/iu', trim($heure), $m) !== 1) {
            return null;
        }

        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    private function heureDansIntervalle(int $debutMin, int $finMin, Carbon $now): bool
    {
        $courantMin = ((int) $now->format('H')) * 60 + (int) $now->format('i');

        if ($finMin >= $debutMin) {
            return $courantMin >= $debutMin && $courantMin <= $finMin;
        }

        return $courantMin >= $debutMin || $courantMin <= $finMin;
    }
}

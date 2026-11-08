<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class RoleHabilitation extends Model
{
    use HasFactory;

    protected $table = 'roles_habilitation';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'onglets',
        'is_system',
    ];

    protected $casts = [
        'onglets' => 'array',
        'is_system' => 'boolean',
    ];

    /**
     * Dictionnaire exhaustif des onglets / sections du panneau d'administration.
     */
    public const AVAILABLE_ONGLETS = [
        'dashboard' => [
            'label' => 'Tableau de Bord (Dashboard)',
            'group' => 'Général',
            'icon' => 'heroicon-o-home',
            'description' => 'Métriques globales, graphiques et suivi d\'activité en direct.',
        ],
        'orders' => [
            'label' => 'Commandes GoShop',
            'group' => 'Opérations GoShop',
            'icon' => 'heroicon-o-shopping-bag',
            'description' => 'Gestion, suivi, réassignation et détails des commandes marchands.',
        ],
        'parcels' => [
            'label' => 'Expéditions GoColis',
            'group' => 'Opérations GoColis',
            'icon' => 'heroicon-o-cube',
            'description' => 'Suivi des colis, photos de preuve, codes de retrait et statuts.',
        ],
        'merchants' => [
            'label' => 'Commerces Partenaires & Catégories',
            'group' => 'Partenaires',
            'icon' => 'heroicon-o-building-storefront',
            'description' => 'Validation, commissions et fiches boutiques/restaurants.',
        ],
        'couriers' => [
            'label' => 'Livreurs & Flotte de Véhicules',
            'group' => 'Partenaires',
            'icon' => 'heroicon-o-truck',
            'description' => 'Comptes livreurs, permis, pièces, types de véhicules et blocages.',
        ],
        'clients' => [
            'label' => 'Base Clients',
            'group' => 'Partenaires',
            'icon' => 'heroicon-o-users',
            'description' => 'Consulter les comptes clients et l\'historique d\'activité.',
        ],
        'pending_validations' => [
            'label' => 'Validations en Attente',
            'group' => 'Partenaires',
            'icon' => 'heroicon-o-check-badge',
            'description' => 'Activation des nouveaux commerces et des livreurs postulants.',
        ],
        'finances' => [
            'label' => 'Finances & Transactions Wallets',
            'group' => 'Finances',
            'icon' => 'heroicon-o-banknotes',
            'description' => 'Soldes wallets livreurs/marchands, recharges, prélèvements et exports.',
        ],
        'refunds' => [
            'label' => 'Remboursements & Plafonds',
            'group' => 'Finances',
            'icon' => 'heroicon-o-arrow-path-rounded-square',
            'description' => 'Traitement des demandes de remboursement selon les plafonds de validation.',
        ],
        'disputes' => [
            'label' => 'Litiges & Codes Bloqués',
            'group' => 'Opérations',
            'icon' => 'heroicon-o-exclamation-triangle',
            'description' => 'Résolution des incidents, colis avariés et déblocage manuel des codes.',
        ],
        'emergency_center' => [
            'label' => 'Centre d\'Urgence & Mode Pluie',
            'group' => 'Opérations',
            'icon' => 'heroicon-o-bolt',
            'description' => 'Activation manuelle du mode pluie ou mode urgence sur les villes.',
        ],
        'administrators' => [
            'label' => 'Comptes Administrateurs',
            'group' => 'Administration',
            'icon' => 'heroicon-o-shield-check',
            'description' => 'Création, affectation et gestion des utilisateurs administratifs.',
        ],
        'roles_habilitation' => [
            'label' => 'Niveaux d\'Habilitation & Rôles',
            'group' => 'Administration',
            'icon' => 'heroicon-o-key',
            'description' => 'Gestion des profils d\'accès et des onglets autorisés (Super Admin).',
        ],
        'activity_logs' => [
            'label' => 'Journal d\'Audit',
            'group' => 'Administration',
            'icon' => 'heroicon-o-clipboard-document-list',
            'description' => 'Historique immuable de toutes les actions et modifications sensibles.',
        ],
        'webhook_logs' => [
            'label' => 'Logs & Rejeu Webhooks',
            'group' => 'Administration',
            'icon' => 'heroicon-o-arrow-top-right-on-square',
            'description' => 'Traçabilité des notifications de paiement (Orange Money, Wave, MTN).',
        ],
        'cities' => [
            'label' => 'Villes & Périmètres',
            'group' => 'Paramètres & Système',
            'icon' => 'heroicon-o-map-pin',
            'description' => 'Villes desservies, coordonnées GPS et zones opérationnelles.',
        ],
        'settings' => [
            'label' => 'Paramètres Système & Barèmes',
            'group' => 'Paramètres & Système',
            'icon' => 'heroicon-o-cog-6-tooth',
            'description' => 'Tarifs au km, commissions, délais d\'acceptation et règles globales.',
        ],
    ];

    protected static function booted(): void
    {
        static::saving(function (self $model) {
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name, '_');
            }
            if (empty($model->onglets)) {
                $model->onglets = [];
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_habilitation_id');
    }

    public function hasOnglet(string $ongletKey): bool
    {
        if ($this->slug === 'super_admin') {
            return true;
        }

        return in_array($ongletKey, $this->onglets ?? [], true);
    }

    public static function getOngletOptions(): array
    {
        $options = [];
        foreach (self::AVAILABLE_ONGLETS as $key => $meta) {
            $options[$key] = "{$meta['label']} ({$meta['group']})";
        }
        return $options;
    }

    public static function getOngletDescriptions(): array
    {
        $descriptions = [];
        foreach (self::AVAILABLE_ONGLETS as $key => $meta) {
            $descriptions[$key] = $meta['description'];
        }
        return $descriptions;
    }

    public static function getAllOngletKeys(): array
    {
        return array_keys(self::AVAILABLE_ONGLETS);
    }
}


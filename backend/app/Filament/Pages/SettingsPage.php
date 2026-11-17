<?php

namespace App\Filament\Pages;

use App\Enums\PaymentMethod;
use App\Filament\Components\ImageUploadField;
use App\Filament\Components\SearchableSelect;
use App\Filament\Support\FilamentCityScope;
use App\Models\City;
use App\Services\SettingsService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * Page Filament « Paramètres » pour le Super Admin et DG National.
 * Permet d'ajuster les tarifs, commissions, délais et seuils sans aucun déploiement.
 * Supporte la configuration globale et la surcharge par ville.
 */
class SettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Paramètres & Système';

    protected static ?string $navigationLabel = 'Paramètres & Tarifs';

    protected static ?string $title = 'Configuration & Tarifs de la Plateforme';

    protected static string $view = 'filament.pages.settings-page';

    protected static ?int $navigationSort = 99;

    public ?int $selectedCityId = null;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null
            && ! FilamentCityScope::isLocal($user)
            && $user->hasHabilitationTab('settings');
    }

    public function mount(): void
    {
        if (FilamentCityScope::isLocal()) {
            abort(403, 'La configuration des paramètres est réservée aux rôles nationaux.');
        }

        $this->loadSettings();
    }

    public function updatedSelectedCityId(): void
    {
        if (FilamentCityScope::isLocal()) {
            abort(403, 'La configuration des paramètres est réservée aux rôles nationaux.');
        }

        $this->loadSettings();
    }

    protected function loadSettings(): void
    {
        $settingsService = app(SettingsService::class);
        $settings = $settingsService->getAll($this->selectedCityId);
        $settings['branding'] = $settingsService->getBranding();

        $this->form->fill($settings);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                SearchableSelect::make('selectedCityId')
                    ->label('Périmètre de configuration')
                    ->options([
                        '' => '🌐 Configuration Globale (Toutes les villes)',
                        ...City::orderBy('name')->pluck('name', 'id')->toArray(),
                    ])
                    ->reactive()
                    ->afterStateUpdated(fn ($state) => $this->selectedCityId = $state ? (int) $state : null)
                    ->helperText('Sélectionnez une ville pour surcharger ses tarifs locaux (ex: Daloa) ou restez en global.'),

                Forms\Components\Tabs::make('SettingsTabs')
                    ->tabs([
                        // ── 0. Logo & Identité GoLivreur ──────────────────────
                        Forms\Components\Tabs\Tab::make('Logo & Identité')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Forms\Components\Section::make('Logo de la plateforme GoLivreur')
                                    ->description('Si un logo est uploadé ici, il remplace l\'emoji par défaut dans l\'administration et les applications mobiles.')
                                    ->schema([
                                        ImageUploadField::make(
                                            'branding.logo_path',
                                            'branding',
                                            'Logo de GoLivreur (JPG, PNG, WEBP — Max 2 Mo)'
                                        )
                                            ->live()
                                            ->afterStateUpdated(function (): void {
                                                if (! app()->runningUnitTests()) {
                                                    $this->save();
                                                }
                                            })
                                            ->columnSpanFull(),

                                        Forms\Components\TextInput::make('branding.default_emoji')
                                            ->label('Emoji par défaut (si aucun logo)')
                                            ->maxLength(16)
                                            ->placeholder('🛵')
                                            ->helperText('Affiché partout lorsqu\'aucun logo n\'est enregistré (défaut : 🛵).'),

                                        Forms\Components\Placeholder::make('branding_preview')
                                            ->label('Aperçu actuel affiché')
                                            ->content(function (Forms\Get $get): HtmlString {
                                                $settingsService = app(SettingsService::class);
                                                $branding = $settingsService->getBranding();
                                                $emoji = trim((string) ($get('branding.default_emoji') ?? $branding['default_emoji'] ?? '🛵')) ?: '🛵';

                                                if (! empty($branding['logo_url'])) {
                                                    $url = e($branding['logo_url']);

                                                    return new HtmlString(
                                                        '<div style="display:flex;align-items:center;gap:12px;">'
                                                        .'<img src="'.$url.'" alt="GoLivreur" style="height:48px;width:48px;object-fit:cover;border-radius:12px;border:1px solid #e5e7eb;background:#fff;box-shadow:0 6px 14px rgba(244,166,35,0.28);padding:2px;" />'
                                                        .'<div><div style="font-weight:800;font-size:16px;"><span style="color:#F4A623;">Go</span>Livreur</div>'
                                                        .'<div style="font-size:12px;color:#059669;font-weight:600;">Logo personnalisé actif (synchronisé auto Web + Client + Business + Driver)</div></div>'
                                                        .'</div>'
                                                    );
                                                }

                                                return new HtmlString(
                                                    '<div style="display:flex;align-items:center;gap:12px;">'
                                                    .'<span style="display:inline-flex;align-items:center;justify-content:center;height:48px;width:48px;border-radius:12px;background:linear-gradient(135deg,#FFB732 0%,#F4A623 100%);box-shadow:0 6px 14px rgba(244,166,35,0.35);font-size:26px;">'.e($emoji).'</span>'
                                                    .'<div><div style="font-weight:800;font-size:16px;"><span style="color:#F4A623;">Go</span>Livreur</div>'
                                                    .'<div style="font-size:12px;color:#6b7280;">Logo unique par défaut actif sur toutes les plateformes</div></div>'
                                                    .'</div>'
                                                );
                                            }),
                                    ])->columns(2),
                            ]),

                        // ── 1. Tarifs GoShop ──────────────────────────────────
                        Forms\Components\Tabs\Tab::make('Tarifs GoShop')
                            ->icon('heroicon-o-shopping-bag')
                            ->schema([
                                Forms\Components\Section::make('Barème de livraison GoShop (par distance)')
                                    ->description('Frais de livraison appliqués aux clients selon le kilométrage réel')
                                    ->schema([
                                        Forms\Components\Repeater::make('tarifs_goshop.bareme')
                                            ->label('Tranches kilométriques')
                                            ->schema([
                                                Forms\Components\TextInput::make('max_km')
                                                    ->label('Distance max (km)')
                                                    ->numeric()
                                                    ->required(),
                                                Forms\Components\TextInput::make('normal')
                                                    ->label('Tarif Normal (F CFA)')
                                                    ->numeric()
                                                    ->prefix('F')
                                                    ->required(),
                                                Forms\Components\TextInput::make('pointe')
                                                    ->label('Heures de Pointe (F CFA)')
                                                    ->numeric()
                                                    ->prefix('F')
                                                    ->required(),
                                                Forms\Components\TextInput::make('pluie')
                                                    ->label('Mode Pluie (F CFA)')
                                                    ->numeric()
                                                    ->prefix('F')
                                                    ->required(),
                                            ])
                                            ->columns(4)
                                            ->collapsible(),
                                    ]),

                                Forms\Components\Section::make('Plages Horaires de Pointe')
                                    ->description('Heures pendant lesquelles la majoration pointe est active')
                                    ->schema([
                                        Forms\Components\Repeater::make('tarifs_goshop.heures_pointe')
                                            ->label('Créneaux de pointe')
                                            ->schema([
                                                Forms\Components\TimePicker::make('debut')
                                                    ->label('Début')
                                                    ->seconds(false)
                                                    ->required(),
                                                Forms\Components\TimePicker::make('fin')
                                                    ->label('Fin')
                                                    ->seconds(false)
                                                    ->required(),
                                            ])
                                            ->columns(2),
                                    ]),
                            ]),

                        // ── 2. Tarifs GoColis ─────────────────────────────────
                        Forms\Components\Tabs\Tab::make('Tarifs GoColis')
                            ->icon('heroicon-o-cube')
                            ->schema([
                                Forms\Components\Section::make('Barème d\'expédition selon la taille du colis')
                                    ->schema([
                                        Forms\Components\Grid::make(3)
                                            ->schema([
                                                Forms\Components\Fieldset::make('Petit Colis (Pli, Clés, Médicaments)')
                                                    ->schema([
                                                        Forms\Components\TextInput::make('tarifs_gocolis.bareme.petit.base')
                                                            ->label('Prix de base (jusqu\'à 2 km)')
                                                            ->numeric()->prefix('F')->required(),
                                                        Forms\Components\TextInput::make('tarifs_gocolis.bareme.petit.par_km')
                                                            ->label('Par km supplémentaire')
                                                            ->numeric()->prefix('F')->required(),
                                                    ]),
                                                Forms\Components\Fieldset::make('Moyen Colis (Repas, Vêtements, Chaussures)')
                                                    ->schema([
                                                        Forms\Components\TextInput::make('tarifs_gocolis.bareme.moyen.base')
                                                            ->label('Prix de base (jusqu\'à 2 km)')
                                                            ->numeric()->prefix('F')->required(),
                                                        Forms\Components\TextInput::make('tarifs_gocolis.bareme.moyen.par_km')
                                                            ->label('Par km supplémentaire')
                                                            ->numeric()->prefix('F')->required(),
                                                    ]),
                                                Forms\Components\Fieldset::make('Grand Colis (Électroménager, Carton)')
                                                    ->schema([
                                                        Forms\Components\TextInput::make('tarifs_gocolis.bareme.grand.base')
                                                            ->label('Prix de base (jusqu\'à 2 km)')
                                                            ->numeric()->prefix('F')->required(),
                                                        Forms\Components\TextInput::make('tarifs_gocolis.bareme.grand.par_km')
                                                            ->label('Par km supplémentaire')
                                                            ->numeric()->prefix('F')->required(),
                                                    ]),
                                            ]),
                                    ]),

                                Forms\Components\Section::make('Assurance et Indemnisation')
                                    ->schema([
                                        Forms\Components\TextInput::make('tarifs_gocolis.seuil_assurance')
                                            ->label('Seuil de déclenchement assurance (F CFA)')
                                            ->numeric()->prefix('F')->required()
                                            ->helperText('En dessous de ce seuil, l\'assurance est gratuite.'),
                                        Forms\Components\TextInput::make('tarifs_gocolis.taux_assurance')
                                            ->label('Taux d\'assurance obligatoire (%)')
                                            ->numeric()->suffix('%')->required()
                                            ->helperText('Pourcentage de la valeur déclarée au-delà du seuil.'),
                                    ])->columns(2),
                            ]),

                        // ── 3. Commissions ───────────────────────────────────
                        Forms\Components\Tabs\Tab::make('Commissions')
                            ->icon('heroicon-o-banknotes')
                            ->schema([
                                Forms\Components\Section::make('Taux de commission de la plateforme')
                                    ->schema([
                                        Forms\Components\TextInput::make('commissions.merchant_defaut')
                                            ->label('Commission commerce par défaut (%)')
                                            ->numeric()->suffix('%')->required()
                                            ->helperText('Prélevée sur le montant des produits vendus (ex: 15%).'),
                                        Forms\Components\TextInput::make('commissions.livreur_goshop')
                                            ->label('Part retenue par la plateforme sur les frais de livraison (le livreur touche le reste : 100 − cette valeur)')
                                            ->numeric()->suffix('%')->required()
                                            ->helperText('Exemple (20 %) : course à 800 F → plateforme 160 F, livreur 640 F.'),
                                        Forms\Components\TextInput::make('commissions.plateforme_gocolis')
                                            ->label('Part retenue par la plateforme sur les frais d\'expédition GoColis (le livreur touche le reste : 100 − cette valeur)')
                                            ->numeric()->suffix('%')->required()
                                            ->helperText('Exemple (25 %) : colis à 1 800 F → plateforme 450 F, livreur 1 350 F.'),
                                    ])->columns(3),
                            ]),

                        // ── 4. Délais & Règles Opérationnelles ────────────────
                        Forms\Components\Tabs\Tab::make('Délais & Règles')
                            ->icon('heroicon-o-clock')
                            ->schema([
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('delais_regles.acceptation_commerce_secondes')
                                            ->label('Délai acceptation commerce')
                                            ->numeric()->suffix('secondes')->required(),
                                        Forms\Components\TextInput::make('delais_regles.marge_balayage_commerce_secondes')
                                            ->label('Marge balayage commerce')
                                            ->numeric()->suffix('secondes')->required(),
                                        Forms\Components\TextInput::make('delais_regles.paiement_attente_minutes')
                                            ->label('Expiration paiement en ligne')
                                            ->numeric()->suffix('minutes')->required(),
                                        Forms\Components\TextInput::make('delais_regles.acceptation_livreur_secondes')
                                            ->label('Délai sonnerie livreur')
                                            ->numeric()->suffix('secondes')->required(),
                                        Forms\Components\TextInput::make('delais_regles.marge_balayage_livreur_secondes')
                                            ->label('Marge balayage livreur')
                                            ->numeric()->suffix('secondes')->required(),
                                        Forms\Components\TextInput::make('delais_regles.gps_fraicheur_minutes')
                                            ->label('Fraîcheur position GPS livreur')
                                            ->numeric()->suffix('minutes')->required(),
                                        Forms\Components\TextInput::make('delais_regles.refus_avant_penalite')
                                            ->label('Refus consécutifs avant pénalité')
                                            ->numeric()->required(),
                                        Forms\Components\TextInput::make('delais_regles.duree_penalite_minutes')
                                            ->label('Durée de suspension livreur')
                                            ->numeric()->suffix('minutes')->required(),
                                        Forms\Components\TextInput::make('delais_regles.erreurs_code_avant_blocage')
                                            ->label('Tentatives erronées code OTP')
                                            ->numeric()->required()
                                            ->helperText('Nombre d\'erreurs avant blocage de sécurité de la course.'),
                                        Forms\Components\TextInput::make('delais_regles.rayon_recherche_livreur_km')
                                            ->label('Rayon de recherche livreurs')
                                            ->numeric()->suffix('km')->required(),
                                    ]),
                            ]),

                        // ── 5. Plafonds Remboursements ────────────────────────
                        Forms\Components\Tabs\Tab::make('Remboursements')
                            ->icon('heroicon-o-shield-check')
                            ->schema([
                                Forms\Components\Section::make('Plafonds d\'autorisation de remboursement par rôle (F CFA)')
                                    ->description('Limite maximale qu\'un administrateur peut valider en direct sans escalade')
                                    ->schema([
                                        Forms\Components\TextInput::make('remboursements.plafonds.city_support')
                                            ->label('City Support (< 2 000 F)')
                                            ->numeric()->prefix('F')->required()
                                            ->helperText('Montant strictement < 2 000 F CFA (1 999 F max par défaut, D-REMB-1).'),
                                        Forms\Components\TextInput::make('remboursements.plafonds.city_finance')
                                            ->label('City Finance (2 000 – 10 000 F)')
                                            ->numeric()->prefix('F')->required()
                                            ->helperText('Plafond inclusif de 2 000 à 10 000 F CFA.'),
                                        Forms\Components\TextInput::make('remboursements.plafonds.city_manager')
                                            ->label('City Manager (10 001 – 50 000 F)')
                                            ->numeric()->prefix('F')->required()
                                            ->helperText('Plafond inclusif jusqu\'à 50 000 F CFA.'),
                                        Forms\Components\TextInput::make('remboursements.plafonds.national_support')
                                            ->label('Support National')->numeric()->prefix('F')->required(),
                                        Forms\Components\TextInput::make('remboursements.plafonds.national_finance')
                                            ->label('Finance National')->numeric()->prefix('F')->required(),
                                        Forms\Components\TextInput::make('remboursements.plafonds.super_admin')
                                            ->label('Super Admin / DG')
                                            ->numeric()
                                            ->prefix('F')
                                            ->nullable()
                                            ->placeholder('Illimité (> 50 000)')
                                            ->helperText('Laisser vide pour illimité (CDC > 50 000 F CFA).'),
                                    ])->columns(3),
                            ]),

                        // ── 6. Modes Spéciaux ────────────────────────────────
                        Forms\Components\Tabs\Tab::make('Modes Spéciaux')
                            ->icon('heroicon-o-bolt')
                            ->schema([
                                Forms\Components\Toggle::make('modes_speciaux.pluie_automatique')
                                    ->label('Mode Pluie automatique (via OpenWeatherMap)')
                                    ->helperText('Active automatiquement la majoration pluie en cas de précipitations détectées.'),
                                Forms\Components\TextInput::make('modes_speciaux.meteo_check_interval_minutes')
                                    ->label('Intervalle de contrôle météo')
                                    ->numeric()->suffix('minutes')->required(),
                                Forms\Components\Textarea::make('modes_speciaux.urgence_bandeau_message')
                                    ->label('Message du bandeau Mode Urgence')
                                    ->rows(3)->required(),
                            ]),

                        // ── 7. Moyens de Paiement ────────────────────────────
                        Forms\Components\Tabs\Tab::make('Paiements')
                            ->icon('heroicon-o-credit-card')
                            ->schema([
                                Forms\Components\CheckboxList::make('paiements.modes_actifs')
                                    ->label('Moyens de paiement activés pour les clients')
                                    ->options(PaymentMethod::settingsOptions())
                                    ->columns(2),
                            ]),

                        // ── 8. Alertes SMS ───────────────────────────────────
                        Forms\Components\Tabs\Tab::make('SMS & Alertes')
                            ->icon('heroicon-o-chat-bubble-bottom-center-text')
                            ->schema([
                                Forms\Components\TextInput::make('sms.seuil_alerte_mensuel')
                                    ->label('Seuil d\'alerte consommation SMS / mois')
                                    ->numeric()->required()
                                    ->helperText('Une alerte est envoyée dès que ce quota mensuel est atteint.'),
                                Forms\Components\TextInput::make('sms.seuil_alerte_quotidien')
                                    ->label('Seuil d\'alerte consommation SMS / jour')
                                    ->numeric()->required()
                                    ->helperText('Une alerte est envoyée si les envois quotidiens dépassent ce seuil.'),
                                Forms\Components\TextInput::make('sms.alert_email')
                                    ->label('Email de réception de l\'alerte')
                                    ->email()->required(),
                            ])->columns(3),

                        // ── 9. Sécurité ──────────────────────────────────────
                        Forms\Components\Tabs\Tab::make('Sécurité')
                            ->icon('heroicon-o-lock-closed')
                            ->schema([
                                Forms\Components\TextInput::make('securite.session_duree_minutes')
                                    ->label('Durée de session admin (minutes)')
                                    ->numeric()->required(),
                                Forms\Components\TextInput::make('securite.tentatives_otp_max')
                                    ->label('Nombre maximal de tentatives OTP client')
                                    ->numeric()->required(),
                                Forms\Components\TextInput::make('securite.otp_validite_minutes')
                                    ->label('Durée de validité du code OTP')
                                    ->numeric()->suffix('minutes')->required(),
                            ])->columns(3),

                        // ── 10. Contenus & Versions ──────────────────────────
                        Forms\Components\Tabs\Tab::make('Contenus')
                            ->icon('heroicon-o-device-phone-mobile')
                            ->schema([
                                Forms\Components\TextInput::make('contenus.support_whatsapp')
                                    ->label('Numéro WhatsApp Support Client')
                                    ->prefix('+225')->required(),
                                Forms\Components\TextInput::make('contenus.faq_url')
                                    ->label('URL de la FAQ Client & Partenaires')
                                    ->url()->required(),
                                Forms\Components\TextInput::make('contenus.app_version_min')
                                    ->label('Version minimale requise des applications')
                                    ->required()
                                    ->helperText('Les versions inférieures recevront une incitation à la mise à jour.'),
                            ])->columns(3),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        if (FilamentCityScope::isLocal()) {
            abort(403, 'La configuration des paramètres est réservée aux rôles nationaux.');
        }

        $settingsService = app(SettingsService::class);
        $oldBranding = $settingsService->getBranding();
        $state = $this->form->getState();
        $brandingChanged = false;

        foreach ($state as $key => $value) {
            if ($key === 'selectedCityId') {
                continue;
            }

            if ($key === 'branding' && is_array($value)) {
                $newLogoPath = $value['logo_path'] ?? null;
                if (is_array($newLogoPath)) {
                    $newLogoPath = reset($newLogoPath) ?: null;
                }
                $newLogoPath = is_string($newLogoPath) && $newLogoPath !== '' ? $newLogoPath : null;

                $newEmoji = trim((string) ($value['default_emoji'] ?? '')) ?: '🛵';
                $normalizedBranding = [
                    'logo_path' => $newLogoPath,
                    'default_emoji' => $newEmoji,
                ];

                $oldLogoPath = $oldBranding['logo_path'] ?? null;
                if (is_string($oldLogoPath) && $oldLogoPath !== '' && $oldLogoPath !== $newLogoPath) {
                    Storage::disk('public')->delete($oldLogoPath);
                }

                $brandingChanged = ($oldLogoPath !== $newLogoPath)
                    || (($oldBranding['default_emoji'] ?? '🛵') !== $newEmoji);

                $settingsService->set(
                    key: 'branding',
                    value: $normalizedBranding,
                    cityId: null,
                    userId: auth()->id(),
                    description: 'Mise à jour identité visuelle via le panneau Filament Paramètres'
                );

                continue;
            }

            $existing = $settingsService->get($key, $this->selectedCityId);
            $mergedValue = $this->mergePreservingHiddenKeys($existing, $value);

            if ($mergedValue == $existing) {
                continue;
            }

            $settingsService->set(
                key: $key,
                value: $mergedValue,
                cityId: $this->selectedCityId,
                userId: auth()->id(),
                description: 'Mise à jour via le panneau Filament Paramètres'
            );
        }

        $this->loadSettings();

        Notification::make()
            ->title('Paramètres enregistrés avec succès')
            ->body('Les nouveaux tarifs, logos et règles ont été mis à jour et immédiatement appliqués.')
            ->success()
            ->send();

        if ($brandingChanged && ! app()->runningUnitTests()) {
            $this->redirect(static::getUrl(), navigate: false);
        }
    }

    private function mergePreservingHiddenKeys(mixed $existing, mixed $submitted): mixed
    {
        if (! is_array($existing) || ! is_array($submitted) || array_is_list($existing) || array_is_list($submitted)) {
            return $submitted;
        }

        $merged = $existing;
        foreach ($submitted as $subKey => $subVal) {
            $merged[$subKey] = array_key_exists($subKey, $existing)
                ? $this->mergePreservingHiddenKeys($existing[$subKey], $subVal)
                : $subVal;
        }

        return $merged;
    }
}

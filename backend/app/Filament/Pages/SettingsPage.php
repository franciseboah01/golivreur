<?php

namespace App\Filament\Pages;

use App\Models\City;
use App\Services\SettingsService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

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
        return $user !== null && $user->hasHabilitationTab('settings');
    }

    public function mount(): void
    {
        $this->loadSettings();
    }

    public function updatedSelectedCityId(): void
    {
        $this->loadSettings();
    }

    protected function loadSettings(): void
    {
        $settingsService = app(SettingsService::class);
        $settings = $settingsService->getAll($this->selectedCityId);

        $this->form->fill($settings);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('selectedCityId')
                    ->label('Périmètre de configuration')
                    ->options([
                        null => '🌐 Configuration Globale (Toutes les villes)',
                        ...City::pluck('name', 'id')->toArray(),
                    ])
                    ->reactive()
                    ->afterStateUpdated(fn ($state) => $this->selectedCityId = $state ? (int) $state : null)
                    ->helperText('Sélectionnez une ville pour surcharger ses tarifs locaux (ex: Daloa) ou restez en global.'),

                Forms\Components\Tabs::make('SettingsTabs')
                    ->tabs([
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
                                            ->label('Part plateforme sur livraison GoShop (%)')
                                            ->numeric()->suffix('%')->required()
                                            ->helperText('Pourcentage prélevé sur les frais de livraison (ex: 20%). Le livreur reçoit 80%.'),
                                        Forms\Components\TextInput::make('commissions.plateforme_gocolis')
                                            ->label('Commission plateforme GoColis (%)')
                                            ->numeric()->suffix('%')->required()
                                            ->helperText('Pourcentage prélevé sur les courses GoColis (ex: 25%). Le livreur reçoit 75%.'),
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
                                        Forms\Components\TextInput::make('delais_regles.acceptation_livreur_secondes')
                                            ->label('Délai sonnerie livreur')
                                            ->numeric()->suffix('secondes')->required(),
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
                                            ->label('City Support')->numeric()->prefix('F')->required(),
                                        Forms\Components\TextInput::make('remboursements.plafonds.city_finance')
                                            ->label('City Finance')->numeric()->prefix('F')->required(),
                                        Forms\Components\TextInput::make('remboursements.plafonds.city_manager')
                                            ->label('City Manager')->numeric()->prefix('F')->required(),
                                        Forms\Components\TextInput::make('remboursements.plafonds.national_support')
                                            ->label('Support National')->numeric()->prefix('F')->required(),
                                        Forms\Components\TextInput::make('remboursements.plafonds.national_finance')
                                            ->label('Finance National')->numeric()->prefix('F')->required(),
                                        Forms\Components\TextInput::make('remboursements.plafonds.super_admin')
                                            ->label('Super Admin / DG')->numeric()->prefix('F')->required(),
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
                                    ->options([
                                        'orange_money' => 'Orange Money Côte d\'Ivoire',
                                        'cash'         => 'Espèces à la livraison (Cash)',
                                        'wave'         => 'Wave Côte d\'Ivoire',
                                        'mtn_momo'     => 'MTN Mobile Money',
                                    ])
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
                                Forms\Components\TextInput::make('sms.alert_email')
                                    ->label('Email de réception de l\'alerte')
                                    ->email()->required(),
                            ])->columns(2),

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
        $settingsService = app(SettingsService::class);
        $state = $this->form->getState();

        foreach ($state as $key => $value) {
            if ($key === 'selectedCityId') continue;
            $settingsService->set(
                key: $key,
                value: $value,
                cityId: $this->selectedCityId,
                userId: auth()->id(),
                description: 'Mise à jour via le panneau Filament Paramètres'
            );
        }

        Notification::make()
            ->title('Paramètres enregistrés avec succès')
            ->body('Les nouveaux tarifs et règles ont été mis à jour et immédiatement appliqués.')
            ->success()
            ->send();
    }
}


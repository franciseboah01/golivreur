<?php

namespace App\Filament\Pages;

use App\Filament\Support\FilamentCityScope;
use App\Models\City;
use App\Services\SettingsService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Interface Admin CRUD des Bannières Promotionnelles Dynamiques (App Client).
 * Stockées dans la table existante `settings` (clé `promotional_banners`, globale ou par ville).
 * Auto-expiration automatique côté API selon `starts_at` et `ends_at`.
 *
 * @property Form $form
 */
class BannersPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-photo';

    protected static ?string $navigationGroup = 'Paramètres & Système';

    protected static ?string $navigationLabel = 'Bannières Client';

    protected static ?string $title = 'Gestion des Bannières Promotionnelles';

    protected static string $view = 'filament.pages.banners-page';

    protected static ?int $navigationSort = 98;

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
            abort(403, 'La gestion des bannières est réservée aux rôles nationaux.');
        }

        $this->loadBanners();
    }

    public function updatedSelectedCityId(): void
    {
        $this->loadBanners();
    }

    private function loadBanners(): void
    {
        $raw = app(SettingsService::class)->get('promotional_banners', $this->selectedCityId, []);
        $banners = is_array($raw) ? array_values($raw) : [];

        $this->form->fill([
            'banners' => $banners,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Carrousel Promotionnel (App GL Client)')
                    ->description('Configurez les bannières affichées sur l\'écran d\'accueil Client. Chaque bannière s\'affiche uniquement entre sa date de début et sa date de fin (expiration automatique).')
                    ->schema([
                        Forms\Components\Repeater::make('banners')
                            ->label('Bannières')
                            ->addActionLabel('Ajouter une bannière')
                            ->reorderableWithButtons()
                            ->collapsible()
                            ->itemLabel(fn (array $state): string => ($state['title'] ?? 'Nouvelle bannière').' — '.$this->formatStatusLabel($state))
                            ->schema([
                                Forms\Components\Hidden::make('id')
                                    ->default(fn (): string => Str::uuid()->toString()),

                                Forms\Components\FileUpload::make('image_path')
                                    ->label('Image de la bannière (Format 16:9 recommandé)')
                                    ->disk('public')
                                    ->directory('banners')
                                    ->image()
                                    ->imageEditor()
                                    ->maxSize(2048)
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('title')
                                    ->label('Titre')
                                    ->placeholder('Ex : Black Friday GoShop -20%')
                                    ->required()
                                    ->maxLength(120),

                                Forms\Components\TextInput::make('subtitle')
                                    ->label('Sous-titre')
                                    ->placeholder('Ex : Livraison offerte tout le week-end')
                                    ->maxLength(240),

                                Forms\Components\TextInput::make('deep_link')
                                    ->label('Deep Link (Produit / Commerce / Catégorie / URL)')
                                    ->placeholder('Ex : merchant:5, product:12, category:maquis ou https://...')
                                    ->helperText('Formats supportés : merchant:<id>, product:<id>, category:<slug>, gocolis ou une URL https://')
                                    ->maxLength(255)
                                    ->columnSpanFull(),

                                Forms\Components\DateTimePicker::make('starts_at')
                                    ->label('Date de début')
                                    ->required()
                                    ->seconds(false),

                                Forms\Components\DateTimePicker::make('ends_at')
                                    ->label('Date de fin (auto-expiration)')
                                    ->required()
                                    ->after('starts_at')
                                    ->seconds(false),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Bannière active')
                                    ->default(true)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $rawBanners = is_array($state['banners'] ?? null) ? array_values($state['banners']) : [];

        $normalized = [];
        foreach ($rawBanners as $index => $banner) {
            if (! is_array($banner)) {
                continue;
            }

            $normalized[] = [
                'id' => ! empty($banner['id']) ? (string) $banner['id'] : Str::uuid()->toString(),
                'title' => trim((string) ($banner['title'] ?? '')),
                'subtitle' => trim((string) ($banner['subtitle'] ?? '')),
                'image_path' => isset($banner['image_path']) && is_string($banner['image_path']) ? $banner['image_path'] : null,
                'deep_link' => isset($banner['deep_link']) && is_string($banner['deep_link']) ? trim($banner['deep_link']) : null,
                'starts_at' => ! empty($banner['starts_at']) ? Carbon::parse((string) $banner['starts_at'])->toIso8601String() : null,
                'ends_at' => ! empty($banner['ends_at']) ? Carbon::parse((string) $banner['ends_at'])->toIso8601String() : null,
                'is_active' => (bool) ($banner['is_active'] ?? true),
                'sort_order' => $index,
            ];
        }

        app(SettingsService::class)->set(
            'promotional_banners',
            $normalized,
            $this->selectedCityId,
            (int) auth()->id(),
        );

        Notification::make()
            ->title('Bannières promotionnelles enregistrées')
            ->body(count($normalized).' bannière(s) sauvegardée(s). L\'application Client est synchronisée.')
            ->success()
            ->send();
    }

    /**
     * @return array<int, string>
     */
    public function getCitiesOptionsProperty(): array
    {
        return City::orderBy('name')->pluck('name', 'id')->toArray();
    }

    /**
     * @param  array<string, mixed>  $banner
     */
    private function formatStatusLabel(array $banner): string
    {
        if (isset($banner['is_active']) && ! $banner['is_active']) {
            return 'Désactivée';
        }

        try {
            $now = now();
            $start = ! empty($banner['starts_at']) ? Carbon::parse((string) $banner['starts_at']) : null;
            $end = ! empty($banner['ends_at']) ? Carbon::parse((string) $banner['ends_at']) : null;

            if ($start && $now->lt($start)) {
                return 'Programmée';
            }
            if ($end && $now->gt($end)) {
                return 'Expirée';
            }
        } catch (\Throwable) {
            return 'Planifiée';
        }

        return 'En cours';
    }
}

<?php

namespace App\Filament\Pages;

use App\Models\Notification as InAppNotification;
use App\Models\User;
use App\Services\CustomerSupportService;
use App\Services\SupportAuditService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Interface Conseiller Service Client (Screen Pop) :
 *  - Saisie du téléphone -> affichage instantané du profil, rôles, commandes, colis, tickets ouverts.
 *  - Vérification d'identité hors bande (OTP) obligatoire — aucune question de sécurité.
 *  - Réinitialisation sécurisée mot de passe / PIN sans jamais révéler le mot de passe au conseiller.
 *  - Traçabilité immuable complète via SupportAuditService.
 */
class CustomerSupportCenterPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-phone-arrow-down-left';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Service Client (Screen Pop)';

    protected static ?string $title = 'Centre Service Client — Screen Pop';

    protected static string $view = 'filament.pages.customer-support-center-page';

    protected static ?int $navigationSort = 15;

    public string $searchPhone = '';

    public string $otpCode = '';

    public string $newTicketTitle = '';

    public string $newTicketBody = '';

    public ?int $selectedUserId = null;

    public bool $otpSent = false;

    public ?string $lookupNotice = null;

    /** @var array<string, mixed>|null */
    public ?array $callerData = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return in_array($user->role, [
            'super_admin',
            'dg_national',
            'national_support',
            'city_manager',
            'city_support',
        ], true) || $user->hasHabilitationTab('disputes');
    }

    public function updatedSearchPhone(string $value): void
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) >= 10) {
            $this->rechercherAppelant();
        }
    }

    public function rechercherAppelant(): void
    {
        $advisor = auth()->user();
        if (! $advisor instanceof User) {
            return;
        }

        $this->otpCode = '';
        $this->otpSent = false;

        $service = app(CustomerSupportService::class);
        $result = $service->rechercherFicheAppelant($this->searchPhone, $advisor, request()->ip());

        if ($result === null) {
            $this->callerData = null;
            $this->selectedUserId = null;
            $this->lookupNotice = 'Aucun utilisateur trouvé pour ce numéro de téléphone.';

            return;
        }

        $this->callerData = $result;
        /** @var User $foundUser */
        $foundUser = $result['user'];
        $this->selectedUserId = $foundUser->id;
        $this->lookupNotice = null;
    }

    public function envoyerOtpVerification(): void
    {
        $advisor = auth()->user();
        $target = $this->selectedUserId ? User::find($this->selectedUserId) : null;
        if (! $advisor instanceof User || ! $target) {
            return;
        }

        $res = app(CustomerSupportService::class)->envoyerVerificationIdentiteHorsBande($target, $advisor, request()->ip());
        $this->otpSent = true;

        Notification::make()
            ->title($res['sent'] ? 'Code de vérification envoyé' : 'Code généré (vérifiez le canal SMS/Email)')
            ->body('Demandez à l\'appelant de vous communiquer le code à 6 chiffres reçu hors bande (valable '.$res['expires_in_minutes'].' min).')
            ->color($res['sent'] ? 'success' : 'warning')
            ->send();

        $this->rechargerFicheSilencieusement();
    }

    public function validerOtpVerification(): void
    {
        $advisor = auth()->user();
        $target = $this->selectedUserId ? User::find($this->selectedUserId) : null;
        if (! $advisor instanceof User || ! $target) {
            return;
        }

        $ok = app(CustomerSupportService::class)->verifierIdentiteHorsBande(
            $target,
            $this->otpCode,
            $advisor,
            request()->ip(),
        );

        $this->otpCode = '';

        if ($ok) {
            Notification::make()
                ->title('Identité vérifiée (hors bande)')
                ->body('Les actions sensibles (réinitialisation mot de passe / PIN) sont déverrouillées pour 10 minutes.')
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('Échec de vérification OTP')
                ->body('Code incorrect ou expiré. L\'action a été enregistrée dans le journal d\'audit.')
                ->danger()
                ->send();
        }

        $this->rechargerFicheSilencieusement();
    }

    public function declencherResetMotDePasse(): void
    {
        $advisor = auth()->user();
        $target = $this->selectedUserId ? User::find($this->selectedUserId) : null;
        if (! $advisor instanceof User || ! $target) {
            return;
        }

        try {
            app(CustomerSupportService::class)->reinitialiserMotDePasseSecurise($target, $advisor, request()->ip());

            Notification::make()
                ->title('Mot de passe temporaire envoyé à l\'utilisateur')
                ->body('Le mot de passe temporaire a été transmis directement à l\'utilisateur hors bande (invisible pour le conseiller). Un changement de mot de passe sera exigé dès la prochaine connexion.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Action bloquée')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->rechargerFicheSilencieusement();
    }

    public function declencherResetPin(): void
    {
        $advisor = auth()->user();
        $target = $this->selectedUserId ? User::find($this->selectedUserId) : null;
        if (! $advisor instanceof User || ! $target) {
            return;
        }

        try {
            app(CustomerSupportService::class)->reinitialiserPinSecurise($target, $advisor, request()->ip());

            Notification::make()
                ->title('Code PIN rapide réinitialisé')
                ->body('Toutes les sessions actives ont été révoquées. L\'utilisateur devra se reconnecter avec son mot de passe.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Action bloquée')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }

        $this->rechargerFicheSilencieusement();
    }

    public function ouvrirTicketSupport(): void
    {
        $advisor = auth()->user();
        $target = $this->selectedUserId ? User::find($this->selectedUserId) : null;
        if (! $advisor instanceof User || ! $target) {
            return;
        }

        $title = trim($this->newTicketTitle);
        $body = trim($this->newTicketBody);
        if ($title === '' || $body === '') {
            Notification::make()
                ->title('Champs requis')
                ->body('Veuillez renseigner l\'objet et la description du ticket.')
                ->warning()
                ->send();

            return;
        }

        $notif = InAppNotification::create([
            'user_id' => $target->id,
            'type' => 'support_ticket',
            'title' => $title,
            'body' => $body,
            'data' => ['advisor_id' => $advisor->id, 'status' => 'open'],
            'is_read' => false,
        ]);

        app(SupportAuditService::class)->record(
            advisorId: $advisor->id,
            targetUserId: $target->id,
            action: 'support_ticket_created',
            result: 'success',
            context: ['ticket_id' => $notif->id, 'title' => $title],
            ipAddress: request()->ip(),
        );

        $this->newTicketTitle = '';
        $this->newTicketBody = '';

        Notification::make()
            ->title("Ticket TKT-#{$notif->id} créé")
            ->success()
            ->send();

        $this->rechargerFicheSilencieusement();
    }

    private function rechargerFicheSilencieusement(): void
    {
        if (! $this->selectedUserId) {
            return;
        }

        $advisor = auth()->user();
        $target = User::find($this->selectedUserId);
        if ($advisor instanceof User && $target) {
            $this->callerData = app(CustomerSupportService::class)
                ->rechercherFicheAppelant($target->phone, $advisor, request()->ip());
        }
    }
}

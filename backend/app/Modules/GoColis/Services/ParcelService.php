<?php

namespace App\Modules\GoColis\Services;

use App\Jobs\AlerterAdminCodeBloque;
use App\Jobs\AlerterAdminSmsEchec;
use App\Models\City;
use App\Models\Courier;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use App\Modules\GoShop\Services\CommissionService;
use App\Services\ActivityLogService;
use App\Services\AsyncJobDispatcher;
use App\Services\CityGeolocationService;
use App\Services\ConfirmationCodeService;
use App\Services\NotificationService;
use App\Services\ParcelPhotoStorageService;
use App\Services\Payment\ParcelPaymentProcessor;
use App\Services\SettingsService;
use App\Services\SmsService;
use App\Services\WalletService;
use App\Support\PhoneHelper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Service central GoColis — gère tout le cycle de vie d'une expédition :
 * création → double code → photos de preuve → paiement wallet livreur.
 */
class ParcelService
{
    private const PICKUP_CODE_STATUSES = [
        Parcel::STATUS_ACCEPTED,
        Parcel::STATUS_AT_SENDER,
    ];

    private const DELIVERY_CODE_STATUSES = [
        Parcel::STATUS_PICKED_UP,
        Parcel::STATUS_IN_TRANSIT,
        Parcel::STATUS_AT_RECIPIENT,
    ];

    private readonly SettingsService $settingsService;

    private readonly ConfirmationCodeService $confirmationCodeService;

    private readonly ParcelPhotoStorageService $photoStorageService;

    private readonly ParcelPaymentProcessor $paymentProcessor;

    public function __construct(
        private readonly CommissionService $commissionService,
        private readonly AssignmentService $assignmentService,
        private readonly WalletService $walletService,
        private readonly NotificationService $notificationService,
        private readonly SmsService $smsService,
        private readonly ActivityLogService $activityLogService,
        ?SettingsService $settingsService = null,
        ?ConfirmationCodeService $confirmationCodeService = null,
        ?ParcelPhotoStorageService $photoStorageService = null,
        ?ParcelPaymentProcessor $paymentProcessor = null,
    ) {
        $this->settingsService = $settingsService ?? app(SettingsService::class);
        $this->confirmationCodeService = $confirmationCodeService ?? app(ConfirmationCodeService::class);
        $this->photoStorageService = $photoStorageService ?? app(ParcelPhotoStorageService::class);
        $this->paymentProcessor = $paymentProcessor ?? app(ParcelPaymentProcessor::class);
    }

    /**
     * Crée une expédition GoColis, applique la stratégie de paiement configurée,
     * enregistre éventuellement la photo du colis fournie par l'expéditeur et envoie
     * le code destinataire par SMS.
     */
    public function creerExpedition(User $expediteur, array $donnees, ?UploadedFile $senderPhoto = null): Parcel
    {
        return DB::transaction(function () use ($expediteur, $donnees, $senderPhoto) {
            $city = City::findOrFail($expediteur->city_id);

            if ($city->emergency_mode_active) {
                $msg = $city->emergency_mode_message ?: 'Le service d\'expédition est temporairement interrompu dans cette zone (Mode Urgence).';

                throw new HttpException(503, $msg);
            }

            // Vérification coordonnées de ramassage et livraison dans la ville
            $hasRealPickup = isset($donnees['pickup_lat'], $donnees['pickup_lng'])
                && ! (abs((float) $donnees['pickup_lat']) < 0.0001 && abs((float) $donnees['pickup_lng']) < 0.0001);
            $hasRealDelivery = isset($donnees['delivery_lat'], $donnees['delivery_lng'])
                && ! (abs((float) $donnees['delivery_lat']) < 0.0001 && abs((float) $donnees['delivery_lng']) < 0.0001);

            $pickupLat = $hasRealPickup ? (float) $donnees['pickup_lat'] : (float) ($city->center_lat ?? 6.8770);
            $pickupLng = $hasRealPickup ? (float) $donnees['pickup_lng'] : (float) ($city->center_lng ?? -6.4502);
            $deliveryLat = $hasRealDelivery ? (float) $donnees['delivery_lat'] : (float) ($city->center_lat ?? 6.8770);
            $deliveryLng = $hasRealDelivery ? (float) $donnees['delivery_lng'] : (float) ($city->center_lng ?? -6.4502);

            if (! $city->estDansLaVille($pickupLat, $pickupLng)) {
                throw ValidationException::withMessages([
                    'pickup_lat' => [$this->construireMessageHorsPerimetre($city, $pickupLat, $pickupLng, 'ramassage')],
                ]);
            }

            if (! $city->estDansLaVille($deliveryLat, $deliveryLng)) {
                throw ValidationException::withMessages([
                    'delivery_lat' => [$this->construireMessageHorsPerimetre($city, $deliveryLat, $deliveryLng, 'livraison')],
                ]);
            }

            // Calcul distance serveur : GPS-à-GPS uniquement si le point de livraison possède un vrai GPS ;
            // sinon on utilise la distance estimée du quartier (distance_km) afin de ne jamais fausser le prix vers le centre-ville.
            $fallbackDistance = isset($donnees['distance_km']) && (float) $donnees['distance_km'] > 0
                ? (float) $donnees['distance_km']
                : 2.0;

            if ($hasRealPickup && $hasRealDelivery) {
                $haversineDistance = City::distanceHaversine(
                    $pickupLat,
                    $pickupLng,
                    $deliveryLat,
                    $deliveryLng
                );
                $distanceKm = $haversineDistance > 0.05
                    ? max(0.1, round($haversineDistance * 1.0, 2))
                    : max(0.1, round($fallbackDistance, 2));
            } else {
                $distanceKm = max(0.1, round($fallbackDistance, 2));
            }

            // Calculer les frais d'expédition avec la distance réelle et $city (Point 4)
            $fraisExpedition = $this->commissionService->calculerFraisExpedition(
                taille: $donnees['size'],
                distance: $distanceKm,
                city: $city,
            );

            // Calculer l'assurance si applicable avec $city (Point 4)
            $valeurDeclaree = $donnees['declared_value'] ?? 0;
            $montantAssurance = $this->commissionService->calculerAssurance($valeurDeclaree, $city);
            $aAssurance = $montantAssurance > 0;

            // Commissions avec $city (Point 4)
            $commissions = $this->commissionService->calculerCommissionsGoColis($fraisExpedition, $city);

            // Générer les codes (hashé + chiffré pour expéditeur, SMS uniquement pour destinataire)
            $senderPair = $this->confirmationCodeService->genererPaire();
            $codeExpediteur = $senderPair['code'];
            $codeDestinataire = $this->confirmationCodeService->genererCode();

            $parcel = Parcel::create([
                'sender_id' => $expediteur->id,
                'city_id' => $expediteur->city_id,
                'status' => Parcel::STATUS_PENDING,
                'size' => $donnees['size'],
                'weight_kg' => $donnees['weight_kg'] ?? null,
                'declared_value' => $valeurDeclaree,
                'has_insurance' => $aAssurance,
                'insurance_amount' => $montantAssurance,
                'sender_code' => $senderPair['hash'],
                'sender_code_enc' => $senderPair['enc'],
                'recipient_code' => Hash::make($codeDestinataire),
                'recipient_name' => $donnees['recipient_name'],
                'recipient_phone' => $donnees['recipient_phone'],
                'delivery_address' => $donnees['delivery_address'],
                'delivery_lat' => $deliveryLat,
                'delivery_lng' => $deliveryLng,
                'pickup_address' => $donnees['pickup_address'] ?? null,
                'pickup_lat' => $pickupLat,
                'pickup_lng' => $pickupLng,
                'distance_km' => $distanceKm,
                'description' => $donnees['description'] ?? null,
                'shipping_fee' => $fraisExpedition,
                'platform_commission' => $commissions['plateforme'],
                'courier_amount' => $commissions['livreur'],
                'payment_method' => $donnees['payment_method'],
                'payment_status' => $donnees['payment_method'] === 'cash'
                                            ? 'pending_cash' : 'pending',
            ]);

            // Traitement modulaire du paiement (Pattern Strategy : Cash / Simulation Test / futures API)
            $this->paymentProcessor->process($parcel);

            // Enregistrer la photo du colis fournie par l'expéditeur (si présente)
            if ($senderPhoto !== null) {
                $this->sauvegarderPhotoColisExpediteur($parcel, $senderPhoto, $pickupLat, $pickupLng);
            }

            // Envoyer le code destinataire par SMS (jamais loggé)
            $smsEnvoye = $this->smsService->envoyerCodeDestinataire(
                phone: $donnees['recipient_phone'],
                code: $codeDestinataire,
                expediteurNom: $expediteur->name,
            );

            if (! $smsEnvoye) {
                $parcel->update([
                    'sms_failed' => true,
                    'sms_failed_at' => now(),
                ]);

                $this->signalerEchecSmsDestinataire($parcel, $expediteur->id);
            }

            $this->activityLogService->log(
                userId: $expediteur->id,
                action: 'parcel_created',
                entityType: 'parcel',
                entityId: $parcel->id,
                properties: [
                    'size' => $donnees['size'],
                    'fee' => $fraisExpedition,
                    'payment_method' => $parcel->payment_method,
                    'payment_status' => $parcel->payment_status,
                    'has_sender_photo' => $senderPhoto !== null,
                ],
            );

            // Chercher un livreur immédiatement
            $this->assignmentService->attribuerLivreurGoColis($parcel, tentative: 1);

            $parcel->load('photos');
            // Retourner avec le code expéditeur en clair (une seule fois)
            $parcel->sender_code_plain = $codeExpediteur;

            return $parcel;
        });
    }

    /**
     * Enregistre la photo illustrative du colis jointe par l'expéditeur à la création (`type = 'parcel'`).
     * Distincte des photos de preuve livreur (`pickup` et `delivery`).
     */
    public function sauvegarderPhotoColisExpediteur(
        Parcel $parcel,
        UploadedFile $photo,
        ?float $lat = null,
        ?float $lng = null,
    ): ParcelPhoto {
        $storagePath = $this->photoStorageService->reencoderEtStocker($parcel, $photo, 'parcel');

        return ParcelPhoto::create([
            'parcel_id' => $parcel->id,
            'type' => 'parcel',
            'photo_url' => '',
            'storage_path' => $storagePath,
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    /**
     * Sauvegarde une photo de preuve (ramassage ou livraison) après validation et ré-encodage GD.
     * L'ordre strict at_sender -> photo (pickup) et at_recipient -> photo (delivery) est imposé.
     */
    public function sauvegarderPhoto(Parcel $parcel, UploadedFile $photo, string $type, ?float $lat = null, ?float $lng = null): ParcelPhoto
    {
        if ($type === 'pickup' && $parcel->status !== Parcel::STATUS_AT_SENDER) {
            throw ValidationException::withMessages([
                'status' => ['Vous devez d\'abord signaler votre arrivée chez l\'expéditeur avant de prendre la photo de ramassage.'],
            ]);
        }

        if ($type === 'delivery' && $parcel->status !== Parcel::STATUS_AT_RECIPIENT) {
            throw ValidationException::withMessages([
                'status' => ['Vous devez d\'abord signaler votre arrivée chez le destinataire avant de prendre la photo de livraison.'],
            ]);
        }

        $storagePath = $this->photoStorageService->reencoderEtStocker($parcel, $photo, $type);

        return ParcelPhoto::create([
            'parcel_id' => $parcel->id,
            'type' => $type,
            'photo_url' => '',
            'storage_path' => $storagePath,
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    /**
     * Le livreur signale son arrivée chez l'expéditeur (jalon STATUS_AT_SENDER).
     */
    public function signalerArriveeExpediteur(Parcel $parcel): Parcel
    {
        return $this->transitionnerJalon(
            parcel: $parcel,
            statutsAutorises: [Parcel::STATUS_ACCEPTED],
            statutCible: Parcel::STATUS_AT_SENDER,
            messageErreur: 'Vous ne pouvez signaler votre arrivée chez l\'expéditeur que pour un colis accepté.',
        );
    }

    /**
     * Le livreur signale son arrivée chez le destinataire (jalon STATUS_AT_RECIPIENT).
     */
    public function signalerArriveeDestinataire(Parcel $parcel): Parcel
    {
        return $this->transitionnerJalon(
            parcel: $parcel,
            statutsAutorises: [Parcel::STATUS_PICKED_UP, Parcel::STATUS_IN_TRANSIT],
            statutCible: Parcel::STATUS_AT_RECIPIENT,
            messageErreur: 'Le colis doit être ramassé ou en transit avant de signaler l\'arrivée chez le destinataire.',
        );
    }

    /**
     * @param  list<string>  $statutsAutorises
     */
    private function transitionnerJalon(Parcel $parcel, array $statutsAutorises, string $statutCible, string $messageErreur): Parcel
    {
        return DB::transaction(function () use ($parcel, $statutsAutorises, $statutCible, $messageErreur): Parcel {
            /** @var Parcel $lockedParcel */
            $lockedParcel = Parcel::lockForUpdate()->findOrFail($parcel->id);

            if (! in_array($lockedParcel->status, $statutsAutorises, true)) {
                throw ValidationException::withMessages([
                    'status' => [$messageErreur],
                ]);
            }

            $lockedParcel->update(['status' => $statutCible]);

            return $lockedParcel->fresh(['sender', 'courier', 'photos']);
        });
    }

    /**
     * Le livreur valide le code expéditeur au ramassage.
     * La photo de ramassage doit exister avant d'appeler cette méthode.
     */
    public function validerCodeExpediteur(Parcel $parcel, string $codeSaisi): Parcel
    {
        $this->verifierStatutRamassage($parcel);

        // Vérifier que la photo de ramassage existe
        if ($parcel->pickupPhoto()->doesntExist()) {
            throw ValidationException::withMessages([
                'photo' => ['La photo du colis doit être prise avant de valider le code.'],
            ]);
        }

        $this->verifierTentativeCode(
            parcel: $parcel,
            codeSaisi: $codeSaisi,
            codeHash: (string) $parcel->sender_code,
            isLocked: (bool) $parcel->sender_code_locked,
            attemptsColumn: 'sender_code_attempts',
            lockedColumn: 'sender_code_locked',
            alertType: 'gocolis_sender',
            label: 'expéditeur',
            lockedMessage: 'Code expéditeur bloqué. Le support a été alerté.',
            blockedOnFailureMessage: 'Code incorrect. Expédition bloquée — le support a été alerté.',
        );

        // Code correct — ramassage validé
        $ramasse = DB::transaction(function () use ($parcel) {
            $lockedParcel = Parcel::lockForUpdate()->findOrFail($parcel->id);

            $this->verifierStatutRamassage($lockedParcel);

            $lockedParcel->update([
                'status' => Parcel::STATUS_PICKED_UP,
                'sender_code_attempts' => 0,
                'picked_up_at' => now(),
            ]);

            $this->notificationService->notifierColisRamasse($lockedParcel);

            return $lockedParcel->fresh(['sender', 'courier']);
        });

        $this->smsService->envoyerNotificationColisRamasse((string) $ramasse->recipient_phone, $ramasse);

        return $ramasse;
    }

    /**
     * Le livreur valide le code destinataire à la livraison.
     * La photo de livraison doit exister avant d'appeler cette méthode.
     */
    public function validerCodeDestinataire(Parcel $parcel, string $codeSaisi): Parcel
    {
        $this->verifierStatutLivraison($parcel);

        // Vérifier que la photo de livraison existe
        if ($parcel->deliveryPhoto()->doesntExist()) {
            throw ValidationException::withMessages([
                'photo' => ['La photo de remise doit être prise avant de valider le code.'],
            ]);
        }

        $this->verifierTentativeCode(
            parcel: $parcel,
            codeSaisi: $codeSaisi,
            codeHash: (string) $parcel->recipient_code,
            isLocked: (bool) $parcel->recipient_code_locked,
            attemptsColumn: 'recipient_code_attempts',
            lockedColumn: 'recipient_code_locked',
            alertType: 'gocolis_recipient',
            label: 'destinataire',
            lockedMessage: 'Code destinataire bloqué. Le support a été alerté.',
            blockedOnFailureMessage: 'Code incorrect. Livraison bloquée — le support a été alerté.',
        );

        // Code correct — livraison confirmée
        $livre = DB::transaction(function () use ($parcel) {
            $lockedParcel = Parcel::lockForUpdate()->findOrFail($parcel->id);

            $this->verifierStatutLivraison($lockedParcel);

            $estCash = $lockedParcel->payment_method === 'cash';

            $lockedParcel->update([
                'status' => Parcel::STATUS_DELIVERED,
                'payment_status' => $estCash ? 'paid_cash' : $lockedParcel->payment_status,
                'recipient_code_attempts' => 0,
                'delivered_at' => now(),
            ]);

            if ($lockedParcel->courier_id !== null) {
                if ($estCash) {
                    // Encaissement cash GoColis : livreur garde 75%, reverse 25% + assurance
                    $this->walletService->debiterReversementCashParcel($lockedParcel);
                } else {
                    // Paiement électronique : crédit livreur (75% des frais)
                    $this->walletService->crediterLivreurGoColis($lockedParcel);
                }
            }

            // Notifier l'expéditeur
            $this->notificationService->notifierColisLivre($lockedParcel);

            $courier = $lockedParcel->courier_id !== null
                ? Courier::find($lockedParcel->courier_id)
                : null;
            $acteurId = $courier !== null ? (int) $courier->user_id : (int) auth()->id();

            if ($estCash) {
                $this->activityLogService->log(
                    userId: $acteurId,
                    action: 'cash_remittance_recorded',
                    entityType: 'parcel',
                    entityId: $lockedParcel->id,
                    properties: [
                        'parcel_id' => $lockedParcel->id,
                        'shipping_fee' => (int) $lockedParcel->shipping_fee,
                        'insurance_amount' => (int) $lockedParcel->insurance_amount,
                        'courier_kept' => (int) $lockedParcel->courier_amount,
                        'platform_commission' => (int) $lockedParcel->platform_commission,
                        'remitted_amount' => $this->walletService->calculerMontantAReverserParcel($lockedParcel),
                    ],
                );
            }

            $this->activityLogService->log(
                userId: $acteurId,
                action: 'parcel_delivered',
                entityType: 'parcel',
                entityId: $lockedParcel->id,
                properties: [
                    'courier_amount' => $lockedParcel->courier_amount,
                    'payment_method' => $lockedParcel->payment_method,
                ],
            );

            return $lockedParcel->fresh(['sender', 'courier', 'photos']);
        });

        $this->smsService->envoyerNotificationColisLivre((string) $livre->recipient_phone, $livre);

        return $livre;
    }

    private function construireMessageHorsPerimetre(City $city, float $lat, float $lng, string $typeLabel): string
    {
        $distanceKm = ($city->center_lat !== null && $city->center_lng !== null)
            ? round(City::distanceHaversine((float) $city->center_lat, (float) $city->center_lng, $lat, $lng), 1)
            : null;
        $rayonKm = $city->radius_km;

        $nearest = app(CityGeolocationService::class)->findNearestActiveCity($lat, $lng);
        if ($nearest !== null && (int) $nearest['city']->id !== (int) $city->id) {
            $detectedName = $nearest['city']->name;
            $distText = $distanceKm !== null ? " ({$distanceKm} km du centre, rayon {$rayonKm} km)" : '';

            return "Les coordonnées de {$typeLabel} sont en dehors du périmètre de {$city->name}{$distText} : position détectée proche de {$detectedName}.";
        }

        if ($distanceKm !== null) {
            return "Les coordonnées de {$typeLabel} sont en dehors du périmètre de {$city->name} ({$distanceKm} km du centre, rayon configuré : {$rayonKm} km). Vérifiez les coordonnées ou le rayon de la ville dans l'administration.";
        }

        return "Les coordonnées de {$typeLabel} sont situées en dehors du périmètre de {$city->name}.";
    }

    private function verifierStatutRamassage(Parcel $parcel): void
    {
        if (in_array($parcel->status, [Parcel::STATUS_PICKED_UP, Parcel::STATUS_IN_TRANSIT, Parcel::STATUS_AT_RECIPIENT, Parcel::STATUS_DELIVERED], true)) {
            throw ValidationException::withMessages([
                'code' => ['Ce colis a déjà été pris en charge.'],
            ]);
        }

        if (! in_array($parcel->status, self::PICKUP_CODE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Le code expéditeur n\'est accepté qu\'au stade de ramassage.'],
            ]);
        }
    }

    private function verifierStatutLivraison(Parcel $parcel): void
    {
        if ($parcel->status === Parcel::STATUS_DELIVERED) {
            throw ValidationException::withMessages([
                'code' => ['Ce colis a déjà été livré et validé.'],
            ]);
        }

        if (! in_array($parcel->status, self::DELIVERY_CODE_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Le code destinataire n\'est accepté qu\'au stade de livraison.'],
            ]);
        }
    }

    private function verifierTentativeCode(
        Parcel $parcel,
        string $codeSaisi,
        string $codeHash,
        bool $isLocked,
        string $attemptsColumn,
        string $lockedColumn,
        string $alertType,
        string $label,
        string $lockedMessage,
        string $blockedOnFailureMessage,
    ): void {
        if ($isLocked) {
            throw ValidationException::withMessages([
                'code' => [$lockedMessage],
            ]);
        }

        if (! Hash::check($codeSaisi, $codeHash)) {
            $parcel->increment($attemptsColumn);
            $parcel->refresh();

            $attempts = (int) $parcel->{$attemptsColumn};
            $maxErreurs = $this->settingsService->getDelaiRegle('erreurs_code_avant_blocage', (int) $parcel->city_id, 3);

            if ($attempts >= $maxErreurs) {
                $parcel->update([$lockedColumn => true]);
                AsyncJobDispatcher::dispatchAfterCommit(
                    static fn () => AlerterAdminCodeBloque::dispatch($parcel, $alertType)
                        ->onQueue('high'),
                    operation: 'alerter_admin_code_bloque_gocolis',
                    contexte: ['parcel_id' => $parcel->id, 'alert_type' => $alertType],
                );

                throw ValidationException::withMessages([
                    'code' => [$blockedOnFailureMessage],
                ]);
            }

            $restants = max(0, $maxErreurs - $attempts);
            throw ValidationException::withMessages([
                'code' => ["Code {$label} incorrect. {$restants} tentative(s) restante(s)."],
            ]);
        }
    }

    /**
     * Déblocage manuel d'un code par le City Support (après vérification vocale).
     */
    public function debloquerCode(Parcel $parcel, string $typeCode, User $admin): Parcel
    {
        if ($typeCode === 'sender') {
            $parcel->update([
                'sender_code_locked' => false,
                'sender_code_attempts' => 0,
                'sender_code_unlocked_by' => $admin->id,
            ]);
        } else {
            $parcel->update([
                'recipient_code_locked' => false,
                'recipient_code_attempts' => 0,
                'recipient_code_unlocked_by' => $admin->id,
            ]);
        }

        $this->activityLogService->log(
            userId: $admin->id,
            action: "parcel_{$typeCode}_code_unlocked",
            entityType: 'parcel',
            entityId: $parcel->id,
            properties: [
                'type_code' => $typeCode,
                'parcel_id' => $parcel->id,
                'unlocked_by' => $admin->id,
                'called_client' => true,
            ],
            severity: 'warning',
        );

        return $parcel->fresh();
    }

    /**
     * Régénère le code expéditeur GoColis (ex: anciennes lignes sans chiffrement) avec trace admin.
     */
    public function regenererCodeExpediteur(Parcel $parcel, User $admin): Parcel
    {
        return DB::transaction(function () use ($parcel, $admin): Parcel {
            /** @var Parcel $lockedParcel */
            $lockedParcel = Parcel::lockForUpdate()->findOrFail($parcel->id);

            if (! in_array($lockedParcel->status, [
                Parcel::STATUS_PENDING,
                Parcel::STATUS_WAITING,
                Parcel::STATUS_ACCEPTED,
                Parcel::STATUS_AT_SENDER,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Impossible de régénérer le code expéditeur après prise en charge ou clôture du colis.'],
                ]);
            }

            $paire = $this->confirmationCodeService->genererPaire();

            $lockedParcel->update([
                'sender_code' => $paire['hash'],
                'sender_code_enc' => $paire['enc'],
                'sender_code_attempts' => 0,
                'sender_code_locked' => false,
                'sender_code_unlocked_by' => $admin->id,
            ]);

            $this->activityLogService->log(
                userId: $admin->id,
                action: 'parcel_sender_code_regenerated',
                entityType: 'parcel',
                entityId: $lockedParcel->id,
                properties: [
                    'parcel_id' => $lockedParcel->id,
                    'city_id' => $lockedParcel->city_id,
                    'regenerated_by' => $admin->id,
                ],
                severity: 'warning',
            );

            return $lockedParcel->fresh();
        });
    }

    /**
     * Génère et renvoie le code destinataire par SMS sans jamais exposer le code en logs.
     */
    public function renvoyerCodeDestinataire(Parcel $parcel, User $admin): bool
    {
        return DB::transaction(function () use ($parcel, $admin): bool {
            /** @var Parcel $lockedParcel */
            $lockedParcel = Parcel::with('sender')->lockForUpdate()->findOrFail($parcel->id);

            if (in_array($lockedParcel->status, [
                Parcel::STATUS_DELIVERED,
                Parcel::STATUS_CANCELLED,
                Parcel::STATUS_REFUSED,
                Parcel::STATUS_LOST,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Impossible de renvoyer le code destinataire d\'un colis clôturé.'],
                ]);
            }

            $nouveauCode = $this->confirmationCodeService->genererCode();

            $lockedParcel->update([
                'recipient_code' => Hash::make($nouveauCode),
                'recipient_code_attempts' => 0,
                'recipient_code_locked' => false,
            ]);

            /** @var User|null $sender */
            $sender = $lockedParcel->sender;
            $expediteurNom = $sender instanceof User ? $sender->name : 'GoLivreur';
            $smsEnvoye = $this->smsService->envoyerCodeDestinataire(
                phone: (string) $lockedParcel->recipient_phone,
                code: $nouveauCode,
                expediteurNom: $expediteurNom,
            );

            if (! $smsEnvoye) {
                $lockedParcel->update([
                    'sms_failed' => true,
                    'sms_failed_at' => now(),
                ]);

                $this->signalerEchecSmsDestinataire($lockedParcel, $admin->id);

                return false;
            }

            $lockedParcel->update([
                'sms_failed' => false,
                'sms_failed_at' => null,
            ]);

            $this->activityLogService->log(
                userId: $admin->id,
                action: 'parcel_recipient_sms_resent',
                entityType: 'parcel',
                entityId: $lockedParcel->id,
                properties: [
                    'parcel_id' => $lockedParcel->id,
                    'city_id' => $lockedParcel->city_id,
                    'resent_by' => $admin->id,
                ],
                severity: 'warning',
            );

            return true;
        });
    }

    private function signalerEchecSmsDestinataire(Parcel $parcel, ?int $actorId = null): void
    {
        $phone = (string) $parcel->recipient_phone;
        $phoneMasked = PhoneHelper::mask($phone);

        $this->activityLogService->log(
            userId: $actorId,
            action: 'parcel_recipient_sms_failed',
            entityType: 'parcel',
            entityId: $parcel->id,
            properties: [
                'parcel_id' => $parcel->id,
                'city_id' => $parcel->city_id,
                'recipient_phone_masked' => $phoneMasked,
            ],
            severity: 'critical',
        );

        AsyncJobDispatcher::dispatchAfterCommit(
            static fn () => AlerterAdminSmsEchec::dispatch($parcel)->onQueue('high'),
            operation: 'alerter_admin_sms_echec',
            contexte: ['parcel_id' => $parcel->id, 'city_id' => $parcel->city_id],
        );
    }
}

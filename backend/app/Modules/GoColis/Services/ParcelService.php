<?php

namespace App\Modules\GoColis\Services;

use App\Jobs\AlerterAdminCodeBloque;
use App\Models\City;
use App\Models\Courier;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\User;
use App\Modules\GoShop\Services\AssignmentService;
use App\Modules\GoShop\Services\CommissionService;
use App\Services\ActivityLogService;
use App\Services\NotificationService;
use App\Services\SmsService;
use App\Services\WalletService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

    public function __construct(
        private readonly CommissionService $commissionService,
        private readonly AssignmentService $assignmentService,
        private readonly WalletService $walletService,
        private readonly NotificationService $notificationService,
        private readonly SmsService $smsService,
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * Crée une expédition GoColis et envoie le code destinataire par SMS.
     */
    public function creerExpedition(User $expediteur, array $donnees): Parcel
    {
        return DB::transaction(function () use ($expediteur, $donnees) {
            $city = City::findOrFail($expediteur->city_id);

            // Calculer les frais d'expédition
            $fraisExpedition = $this->commissionService->calculerFraisExpedition(
                taille: $donnees['size'],
                distance: $donnees['distance_km'],
            );

            // Calculer l'assurance si applicable
            $valeurDeclaree = $donnees['declared_value'] ?? 0;
            $montantAssurance = $this->commissionService->calculerAssurance($valeurDeclaree);
            $aAssurance = $montantAssurance > 0;

            // Commissions
            $commissions = $this->commissionService->calculerCommissionsGoColis($fraisExpedition);

            // Générer les codes (jamais stockés en clair)
            $codeExpediteur = (string) random_int(1000, 9999);
            $codeDestinataire = (string) random_int(1000, 9999);

            $parcel = Parcel::create([
                'sender_id' => $expediteur->id,
                'city_id' => $expediteur->city_id,
                'status' => Parcel::STATUS_PENDING,
                'size' => $donnees['size'],
                'weight_kg' => $donnees['weight_kg'] ?? null,
                'declared_value' => $valeurDeclaree,
                'has_insurance' => $aAssurance,
                'insurance_amount' => $montantAssurance,
                'sender_code' => Hash::make($codeExpediteur),
                'recipient_code' => Hash::make($codeDestinataire),
                'recipient_name' => $donnees['recipient_name'],
                'recipient_phone' => $donnees['recipient_phone'],
                'delivery_address' => $donnees['delivery_address'],
                'delivery_lat' => $donnees['delivery_lat'] ?? null,
                'delivery_lng' => $donnees['delivery_lng'] ?? null,
                'pickup_address' => $donnees['pickup_address'] ?? null,
                'pickup_lat' => $donnees['pickup_lat'] ?? null,
                'pickup_lng' => $donnees['pickup_lng'] ?? null,
                'description' => $donnees['description'] ?? null,
                'shipping_fee' => $fraisExpedition,
                'platform_commission' => $commissions['plateforme'],
                'courier_amount' => $commissions['livreur'],
                'payment_method' => $donnees['payment_method'],
                'payment_status' => $donnees['payment_method'] === 'cash'
                                            ? 'pending_cash' : 'pending',
            ]);

            // Envoyer le code destinataire par SMS (jamais loggé)
            $this->smsService->envoyerCodeDestinataire(
                phone: $donnees['recipient_phone'],
                code: $codeDestinataire,
                expediteurNom: $expediteur->name,
            );

            $this->activityLogService->log(
                userId: $expediteur->id,
                action: 'parcel_created',
                entityType: 'parcel',
                entityId: $parcel->id,
                properties: ['size' => $donnees['size'], 'fee' => $fraisExpedition],
            );

            // Chercher un livreur immédiatement
            $this->assignmentService->attribuerLivreurGoColis($parcel, tentative: 1);

            // Retourner avec le code expéditeur en clair (une seule fois)
            $parcel->sender_code_plain = $codeExpediteur;

            return $parcel;
        });
    }

    /**
     * Sauvegarde une photo de preuve (ramassage ou livraison).
     * La photo est obligatoire AVANT de pouvoir valider le code.
     */
    public function sauvegarderPhoto(Parcel $parcel, UploadedFile $photo, string $type, ?float $lat = null, ?float $lng = null): ParcelPhoto
    {
        // Upload vers DigitalOcean Spaces
        $chemin = $photo->store(
            "photos/colis/{$parcel->id}/{$type}",
            's3'
        );

        return ParcelPhoto::create([
            'parcel_id' => $parcel->id,
            'type' => $type,
            'photo_url' => Storage::disk('s3')->url($chemin),
            'lat' => $lat,
            'lng' => $lng,
        ]);
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
        return DB::transaction(function () use ($parcel) {
            $lockedParcel = Parcel::lockForUpdate()->findOrFail($parcel->id);

            $this->verifierStatutRamassage($lockedParcel);

            $lockedParcel->update([
                'status' => Parcel::STATUS_PICKED_UP,
                'picked_up_at' => now(),
            ]);

            $this->notificationService->notifierColisRamasse($lockedParcel);

            return $lockedParcel->fresh(['sender', 'courier']);
        });
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
        return DB::transaction(function () use ($parcel) {
            $lockedParcel = Parcel::lockForUpdate()->findOrFail($parcel->id);

            $this->verifierStatutLivraison($lockedParcel);

            $estCash = $lockedParcel->payment_method === 'cash';

            $lockedParcel->update([
                'status' => Parcel::STATUS_DELIVERED,
                'payment_status' => $estCash ? 'paid_cash' : $lockedParcel->payment_status,
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

            if ($attempts >= 3) {
                $parcel->update([$lockedColumn => true]);
                AlerterAdminCodeBloque::dispatch($parcel, $alertType)
                    ->onQueue('high');

                throw ValidationException::withMessages([
                    'code' => [$blockedOnFailureMessage],
                ]);
            }

            $restants = 3 - $attempts;
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
        );

        return $parcel->fresh();
    }
}

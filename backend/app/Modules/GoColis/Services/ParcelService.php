<?php

namespace App\Modules\GoColis\Services;

use App\Models\{City, Parcel, ParcelPhoto, User};
use App\Modules\GoShop\Services\{AssignmentService, CommissionService};
use App\Services\{ActivityLogService, NotificationService, SmsService, WalletService};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB, Hash, Storage};
use Illuminate\Validation\ValidationException;

/**
 * Service central GoColis — gère tout le cycle de vie d'une expédition :
 * création → double code → photos de preuve → paiement wallet livreur.
 */
class ParcelService
{
    public function __construct(
        private readonly CommissionService   $commissionService,
        private readonly AssignmentService   $assignmentService,
        private readonly WalletService       $walletService,
        private readonly NotificationService $notificationService,
        private readonly SmsService          $smsService,
        private readonly ActivityLogService  $activityLogService,
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
            $valeurDeclaree  = $donnees['declared_value'] ?? 0;
            $montantAssurance = $this->commissionService->calculerAssurance($valeurDeclaree);
            $aAssurance       = $montantAssurance > 0;

            // Commissions
            $commissions = $this->commissionService->calculerCommissionsGoColis($fraisExpedition);

            // Générer les codes (jamais stockés en clair)
            $codeExpediteur  = (string) random_int(1000, 9999);
            $codeDestinataire = (string) random_int(1000, 9999);

            $parcel = Parcel::create([
                'sender_id'           => $expediteur->id,
                'city_id'             => $expediteur->city_id,
                'status'              => Parcel::STATUS_PENDING,
                'size'                => $donnees['size'],
                'weight_kg'           => $donnees['weight_kg'] ?? null,
                'declared_value'      => $valeurDeclaree,
                'has_insurance'       => $aAssurance,
                'insurance_amount'    => $montantAssurance,
                'sender_code'         => Hash::make($codeExpediteur),
                'recipient_code'      => Hash::make($codeDestinataire),
                'recipient_name'      => $donnees['recipient_name'],
                'recipient_phone'     => $donnees['recipient_phone'],
                'delivery_address'    => $donnees['delivery_address'],
                'delivery_lat'        => $donnees['delivery_lat'] ?? null,
                'delivery_lng'        => $donnees['delivery_lng'] ?? null,
                'pickup_address'      => $donnees['pickup_address'] ?? null,
                'pickup_lat'          => $donnees['pickup_lat'] ?? null,
                'pickup_lng'          => $donnees['pickup_lng'] ?? null,
                'description'         => $donnees['description'] ?? null,
                'shipping_fee'        => $fraisExpedition,
                'platform_commission' => $commissions['plateforme'],
                'courier_amount'      => $commissions['livreur'],
                'payment_method'      => $donnees['payment_method'],
                'payment_status'      => $donnees['payment_method'] === 'cash'
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
            'type'      => $type,
            'photo_url' => Storage::disk('s3')->url($chemin),
            'lat'       => $lat,
            'lng'       => $lng,
        ]);
    }

    /**
     * Le livreur valide le code expéditeur au ramassage.
     * La photo de ramassage doit exister avant d'appeler cette méthode.
     */
    public function validerCodeExpediteur(Parcel $parcel, string $codeSaisi): Parcel
    {
        // Vérifier que la photo de ramassage existe
        if ($parcel->pickupPhoto()->doesntExist()) {
            throw ValidationException::withMessages([
                'photo' => ['La photo du colis doit être prise avant de valider le code.'],
            ]);
        }

        if ($parcel->sender_code_locked) {
            throw ValidationException::withMessages([
                'code' => ['Code expéditeur bloqué. Le support a été alerté.'],
            ]);
        }

        if (!Hash::check($codeSaisi, $parcel->sender_code)) {
            $parcel->increment('sender_code_attempts');

            if ($parcel->sender_code_attempts >= 3) {
                $parcel->update(['sender_code_locked' => true]);
                \App\Jobs\AlerterAdminCodeBloque::dispatch($parcel, 'gocolis_sender')
                    ->onQueue('high');

                throw ValidationException::withMessages([
                    'code' => ['Code incorrect. Expédition bloquée — le support a été alerté.'],
                ]);
            }

            $restants = 3 - $parcel->sender_code_attempts;
            throw ValidationException::withMessages([
                'code' => ["Code expéditeur incorrect. {$restants} tentative(s) restante(s)."],
            ]);
        }

        // Code correct — ramassage validé
        return DB::transaction(function () use ($parcel) {
            $parcel->update([
                'status'       => Parcel::STATUS_PICKED_UP,
                'picked_up_at' => now(),
            ]);

            $this->notificationService->notifierColisRamasse($parcel);

            return $parcel->fresh(['sender', 'courier']);
        });
    }

    /**
     * Le livreur valide le code destinataire à la livraison.
     * La photo de livraison doit exister avant d'appeler cette méthode.
     */
    public function validerCodeDestinataire(Parcel $parcel, string $codeSaisi): Parcel
    {
        // Vérifier que la photo de livraison existe
        if ($parcel->deliveryPhoto()->doesntExist()) {
            throw ValidationException::withMessages([
                'photo' => ['La photo de remise doit être prise avant de valider le code.'],
            ]);
        }

        if ($parcel->recipient_code_locked) {
            throw ValidationException::withMessages([
                'code' => ['Code destinataire bloqué. Le support a été alerté.'],
            ]);
        }

        if (!Hash::check($codeSaisi, $parcel->recipient_code)) {
            $parcel->increment('recipient_code_attempts');

            if ($parcel->recipient_code_attempts >= 3) {
                $parcel->update(['recipient_code_locked' => true]);
                \App\Jobs\AlerterAdminCodeBloque::dispatch($parcel, 'gocolis_recipient')
                    ->onQueue('high');

                throw ValidationException::withMessages([
                    'code' => ['Code incorrect. Livraison bloquée — le support a été alerté.'],
                ]);
            }

            $restants = 3 - $parcel->recipient_code_attempts;
            throw ValidationException::withMessages([
                'code' => ["Code destinataire incorrect. {$restants} tentative(s) restante(s)."],
            ]);
        }

        // Code correct — livraison confirmée
        return DB::transaction(function () use ($parcel) {
            $parcel->update([
                'status'       => Parcel::STATUS_DELIVERED,
                'delivered_at' => now(),
            ]);

            // Créditer le wallet du livreur (75% des frais)
            $this->walletService->crediterLivreurGoColis($parcel);

            // Notifier l'expéditeur
            $this->notificationService->notifierColisLivre($parcel);

            $this->activityLogService->log(
                userId: $parcel->courier->user_id,
                action: 'parcel_delivered',
                entityType: 'parcel',
                entityId: $parcel->id,
                properties: ['courier_amount' => $parcel->courier_amount],
            );

            return $parcel->fresh(['sender', 'courier', 'photos']);
        });
    }

    /**
     * Déblocage manuel d'un code par le City Support (après vérification vocale).
     */
    public function debloquerCode(Parcel $parcel, string $typeCode, User $admin): Parcel
    {
        if ($typeCode === 'sender') {
            $parcel->update([
                'sender_code_locked'    => false,
                'sender_code_attempts'  => 0,
                'sender_code_unlocked_by' => $admin->id,
            ]);
        } else {
            $parcel->update([
                'recipient_code_locked'    => false,
                'recipient_code_attempts'  => 0,
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

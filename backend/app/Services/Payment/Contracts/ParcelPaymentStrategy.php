<?php

namespace App\Services\Payment\Contracts;

use App\Models\Parcel;
use App\Services\Payment\ParcelPaymentResult;

/**
 * Pattern Strategy pour le traitement des paiements d'expéditions GoColis.
 *
 * Chaque fournisseur (Cash, Simulation Test, puis plus tard Wave, Orange Money, MTN MoMo, Moov)
 * implémente ce contrat de façon indépendante sans modifier ParcelService ni ParcelController.
 */
interface ParcelPaymentStrategy
{
    public function supports(string $paymentMethod): bool;

    public function process(Parcel $parcel): ParcelPaymentResult;
}

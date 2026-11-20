<?php

return [
    /*
     * Mode simulation temporaire pour les paiements Mobile Money (Wave, Orange Money, MTN MoMo, Moov)
     * afin de permettre les tests terrain sans appeler les API externes.
     *
     * Passer PAYMENT_SIMULATE_MOBILE_MONEY=false en production lors du branchement des vraies API.
     */
    'simulate_mobile_money' => filter_var(
        env('PAYMENT_SIMULATE_MOBILE_MONEY', true),
        FILTER_VALIDATE_BOOL,
    ),

    /*
     * Statut enregistré en base lors d'une simulation de paiement Mobile Money ('paid' ou 'pending').
     */
    'simulated_status' => env('PAYMENT_SIMULATED_STATUS', 'paid'),
];

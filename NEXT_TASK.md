# NEXT_TASK.md — Prochaine Tâche à Exécuter
## Projet GoLivreur v5.0

> Ce fichier contient TOUJOURS une seule chose : la prochaine action précise et exécutable.
> Mis à jour après chaque tâche terminée. Francis peut lire ce fichier pour savoir exactement "c'est quoi la suite".

---

## 🎯 Tâche actuelle

**Sprint :** 7 — Paiement et notifications (S11-S12)

**Objectif de la tâche :** Intégrer les paiements Mobile Money (Orange Money, Wave, MTN via Hub2/CinetPay) et les notifications en temps réel (WebSockets Reverb + Push FCM).

**Actions précises à exécuter dans l'ordre :**

1. **Agrégateurs de paiement Hub2 / CinetPay :**
   - Configurer le client API de paiement sandbox (Orange Money / Wave / MTN).
   - Valider le traitement idempotent des webhooks de paiement dans `ProcessPaymentWebhook`.
   - Tester la vérification de signature HMAC sur `POST /api/v1/webhooks/payment`.
2. **Notifications Push & Temps réel :**
   - Configurer Firebase Cloud Messaging (FCM) avec `SendPushNotification`.
   - Configurer Laravel Reverb / Pusher pour le suivi temps réel des positions de livreurs sur la carte.
3. **Validation et tests de flux financiers complets :**
   - Écrire et exécuter la suite de tests `PaymentWebhookTest` (paiement validé, échec, tentative frauduleuse sans HMAC).

**Critère de succès (Definition of Done) :**
- Un paiement sandbox Orange Money déclenche le webhook sécurisé, passe la commande en `paid` et crédite instantanément les portefeuilles sans double crédit.
- 100% des tests Pest passent.

**Bloqué sur quelque chose ?** Non (les tests sandbox peuvent s'exécuter avec des mocks et HMAC locaux en attendant l'ouverture officielle du compte marchand par Francis).

**Nécessite une action de Francis (non technique) ?**
- ⚠️ Finalisation des démarches administratives pour l'ouverture du compte marchand Hub2/CinetPay.
- Réservation du nom de domaine `golivreur.ci`.

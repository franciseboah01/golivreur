# GoLivreur — Diagrammes de Séquence Métier

---

## 1. Séquence complète GoShop — Commande à la livraison

```mermaid
sequenceDiagram
    participant C as Client Flutter
    participant API as API Laravel
    participant B as Business Flutter
    participant D as Driver Flutter
    participant WS as Laravel Reverb
    participant Q as Queue Redis
    participant HUB2 as Hub2/CinetPay
    participant DB as PostgreSQL

    Note over C: Le client choisit ses produits

    C->>API: POST /api/v1/client/orders
    Note right of C: {merchant_id, items[], delivery_address,<br/>payment_method: "orange_money"}
    API->>DB: Crée order (statut: pending)
    API->>DB: Crée order_items (unit_price snapshot)
    API->>DB: Génère confirmation_code (4 chiffres, hashé bcrypt)

    alt Paiement Orange Money
        API->>HUB2: Initie transaction
        HUB2-->>C: Invite de paiement sur téléphone client
        C->>HUB2: Saisit code Orange Money
        HUB2->>API: Webhook POST /api/v1/webhooks/payment
        API->>API: Vérifie signature HMAC (rejet si invalide)
        API->>DB: Log dans webhook_logs
        API->>Q: Job ProcessPaymentWebhook (queue: high)
        Q->>DB: order.payment_status → paid
    else Paiement espèces
        API->>DB: order.payment_status → pending_cash
    end

    API->>Q: Job NotifyMerchant (queue: default)
    Q->>B: Push FCM "Nouvelle commande !"
    API-->>C: 201 {order_id, status: pending, confirmation_code: "XXXX"}

    Note over B: Commerce a 45 secondes pour répondre

    alt Commerce accepte
        B->>API: POST /api/v1/merchant/orders/{id}/accept
        API->>DB: statut → accepted
        API->>WS: Broadcast OrderStatusUpdated
        WS-->>C: Statut mis à jour en temps réel
        API->>API: AssignmentService::findNearestDriver()
        API->>Q: Job NotifyNearestDriver (queue: default)
        Q->>D: Push FCM "Course disponible — 2.3 km"

    else Commerce refuse
        B->>API: POST /api/v1/merchant/orders/{id}/refuse
        API->>DB: statut → refused
        API->>Q: Job NotifyClientRefused (queue: default)
        Q->>C: Push FCM "Le commerce a refusé votre commande"

    else Timeout 45 secondes
        API->>DB: statut → cancelled (via scheduler)
        API->>Q: Job NotifyClientTimeout (queue: default)
    end

    Note over D: Livreur a 30 secondes pour accepter

    D->>API: POST /api/v1/driver/orders/{id}/accept
    API->>DB: order.courier_id = driver_id, statut → ready
    API->>WS: Broadcast CourierAssigned {courier_name, courier_photo, lat, lng}
    WS-->>C: Carte affiche le livreur en temps réel

    loop GPS toutes 5 secondes (si déplacement > 10m)
        D->>API: POST /api/v1/courier/location {lat, lng}
        API->>WS: Broadcast CourierLocationUpdated
        WS-->>C: Position mise à jour sur carte
    end

    D->>API: POST /api/v1/driver/orders/{id}/pickup
    API->>DB: statut → picked_up, picked_up_at = now()
    API->>WS: Broadcast OrderPickedUp
    WS-->>C: "Votre commande est en route !"

    D->>API: POST /api/v1/driver/orders/{id}/arrive
    API->>DB: statut → in_transit
    Note over D,C: Livreur arrive chez le client
    Note over C: Client donne son code à 4 chiffres

    D->>API: POST /api/v1/orders/{id}/verify-code
    Note right of D: {code: "4829"}
    API->>DB: bcrypt verify(code, confirmation_code)

    alt Code correct
        API->>DB: statut → delivered, delivered_at = now()
        API->>DB: WalletService::creditCourier() — 80% frais livraison
        API->>DB: WalletService::creditMerchant() — 85% prix produits
        API->>Q: Push FCM client "Commande livrée !" (queue: default)
        API-->>D: 200 {success: true, gain: "640 F CFA"}
        Note over C: Interface de notation s'affiche

    else Code incorrect (tentative < 3)
        API->>DB: confirmation_attempts++
        API-->>D: 422 {attempts_remaining: 2}

    else 3 erreurs — blocage
        API->>DB: confirmation_locked = true
        API->>Q: AlerteAdmin City Support (queue: high)
        API-->>D: 423 {message: "Course bloquée — support contacté"}
    end

    C->>API: POST /api/v1/client/orders/{id}/rate
    Note right of C: {merchant_rating: 5, courier_rating: 4, comment: "Rapide !"}
    API->>DB: Crée ratings (goshop_merchant + goshop_courier)
```

---

## 2. Séquence complète GoColis — Double code de confirmation

```mermaid
sequenceDiagram
    participant E as Expéditeur Flutter
    participant API as API Laravel
    participant D as Driver Flutter
    participant SMS as Twilio/Orange API
    participant WS as Laravel Reverb
    participant Q as Queue Redis
    participant DB as PostgreSQL

    Note over E: L'expéditeur crée une expédition

    E->>API: POST /api/v1/client/parcels
    Note right of E: {recipient_name, recipient_phone: "0709000001",<br/>delivery_address, size: "moyen",<br/>declared_value: 25000, description: "Vêtements"}
    API->>DB: Calcule shipping_fee (taille + distance)
    API->>DB: Calcule insurance (3% × 25000 = 750 F CFA si > 20000)
    API->>DB: Génère sender_code (4 chiffres, hashé bcrypt)
    API->>DB: Génère recipient_code (4 chiffres, hashé bcrypt)
    API->>DB: Crée parcel (statut: pending)
    API->>Q: Job SendRecipientSms (queue: default)
    Q->>SMS: SMS au destinataire "Vous allez recevoir un colis. Code : 3847"
    API-->>E: 201 {parcel_id, sender_code: "7291", shipping_fee: 1500, insurance: 750}

    Note over E: L'expéditeur voit son code dans l'app

    API->>API: AssignmentService::findNearestDriver()
    Note over D: Livreur reçoit notification avec TAILLE du colis visible

    D->>API: POST /api/v1/driver/parcels/{id}/accept
    Note right of D: Le livreur a vu "MOYEN" avant d'accepter
    API->>DB: parcel.courier_id = driver_id, statut → accepted
    API->>WS: Broadcast ParcelAccepted
    WS-->>E: "Livreur assigné — en route vers vous"

    loop GPS toutes 5 secondes
        D->>API: POST /api/v1/courier/location {lat, lng}
        API->>WS: Broadcast CourierLocationUpdated
        WS-->>E: Position livreur sur carte
    end

    D->>API: POST /api/v1/driver/parcels/{id}/at-sender
    API->>DB: statut → at_sender
    API->>WS: Broadcast AtSender
    WS-->>E: "Le livreur est arrivé chez vous"

    Note over D,E: Étape 1 — Validation expéditeur

    D->>API: POST /api/v1/parcels/{id}/photo
    Note right of D: {type: "pickup", photo: base64}
    API->>DB: Sauvegarde photo dans parcel_photos (type: pickup)

    Note over E: L'expéditeur donne son code verbal au livreur

    D->>API: POST /api/v1/parcels/{id}/verify-sender-code
    Note right of D: {code: "7291"}
    API->>DB: bcrypt verify(code, sender_code)

    alt Code expéditeur correct
        API->>DB: statut → picked_up, picked_up_at = now()
        API->>WS: Broadcast ParcelPickedUp
        WS-->>E: "Colis récupéré — en route vers le destinataire"
        API-->>D: 200 {success: true, next: "navigate_to_recipient"}
    else 3 erreurs expéditeur
        API->>DB: sender_code_locked = true
        API->>Q: Alerte Admin (queue: high)
        API-->>D: 423 {message: "Bloqué — City Support contacté"}
    end

    Note over D: Navigation vers le destinataire

    D->>API: POST /api/v1/driver/parcels/{id}/at-recipient
    API->>DB: statut → at_recipient
    API->>WS: Broadcast AtRecipient
    WS-->>E: "Livreur chez le destinataire"

    Note over D: Étape 2 — Validation destinataire

    D->>API: POST /api/v1/parcels/{id}/photo
    Note right of D: {type: "delivery", photo: base64}
    API->>DB: Sauvegarde photo dans parcel_photos (type: delivery)

    Note over D: Le destinataire donne le code reçu par SMS

    D->>API: POST /api/v1/parcels/{id}/verify-recipient-code
    Note right of D: {code: "3847"}
    API->>DB: bcrypt verify(code, recipient_code)

    alt Code destinataire correct
        API->>DB: statut → delivered, delivered_at = now()
        API->>DB: WalletService::creditCourier() — 75% frais expédition
        API->>WS: Broadcast ParcelDelivered
        WS-->>E: "Colis livré avec succès ✓"
        API-->>D: 200 {success: true, gain: "1125 F CFA"}
        Note over E: Interface notation livreur s'affiche

    else 3 erreurs destinataire
        API->>DB: recipient_code_locked = true
        API->>Q: Alerte Admin (queue: high)
        Note over D: Attendre déblocage par City Support
    end

    E->>API: POST /api/v1/client/parcels/{id}/rate
    Note right of E: {courier_rating: 5, comment: "Parfait, colis intact"}
    API->>DB: Crée rating (type: gocolis_courier)
```

---

## 3. Séquence Mode Pluie — Déclenchement automatique

```mermaid
sequenceDiagram
    participant SCHED as Laravel Scheduler
    participant API as OpenWeatherMap
    participant DB as PostgreSQL
    participant WS as Laravel Reverb
    participant Q as Queue Redis
    participant APPS as 3 Apps Flutter

    Note over SCHED: Toutes les 15 minutes

    SCHED->>API: GET /weather?q=Daloa,CI&appid={key}
    API-->>SCHED: {weather: [{main: "Rain"}], rain: {1h: 2.3}}

    alt Pluie détectée ET Mode Pluie non actif
        SCHED->>DB: UPDATE cities SET rain_mode = true WHERE name = 'Daloa'
        SCHED->>DB: Log activity_logs (action: rain_mode_auto_activated)
        SCHED->>Q: Job NotifyRainMode (queue: default)
        Q->>APPS: Push FCM "Mode Pluie activé — tarifs majorés"
        Q->>WS: Broadcast RainModeActivated
        Note over APPS: Les apps affichent les nouveaux tarifs automatiquement
    else Pas de pluie ET Mode Pluie non actif
        SCHED->>SCHED: Aucune action
    end

    Note over DB: La désactivation est TOUJOURS manuelle
    Note over DB: (City Manager ou supérieur dans Filament)
```

# GoLivreur — Schéma de Base de Données

---

## Diagramme ERD complet

```mermaid
erDiagram
    cities {
        bigint id PK
        string name
        boolean is_active
        decimal center_lat
        decimal center_lng
        timestamps
    }

    users {
        bigint id PK
        string name
        string phone UK
        string role
        bigint city_id FK
        string device_token
        string session_token
        boolean is_active
        timestamp phone_verified_at
        timestamp deleted_at
        timestamps
    }

    merchants {
        bigint id PK
        bigint user_id FK
        bigint city_id FK
        string name
        string category
        string address
        decimal lat
        decimal lng
        json open_hours
        int prep_time_minutes
        boolean is_open
        boolean is_active
        decimal commission_rate
        string photo_url
        string description
        timestamp deleted_at
        timestamps
    }

    couriers {
        bigint id PK
        bigint user_id FK
        bigint city_id FK
        boolean is_online
        decimal current_lat
        decimal current_lng
        string vehicle_type
        boolean is_active
        string id_card_number
        string id_card_photo_url
        timestamp deleted_at
        timestamps
    }

    products {
        bigint id PK
        bigint merchant_id FK
        string name
        text description
        decimal price
        string photo_url
        boolean is_available
        string category
        int sort_order
        timestamp deleted_at
        timestamps
    }

    orders {
        bigint id PK
        bigint client_id FK
        bigint merchant_id FK
        bigint courier_id FK
        bigint city_id FK
        string status
        decimal products_total
        decimal delivery_fee
        decimal platform_commission
        decimal merchant_amount
        decimal courier_amount
        string payment_method
        string payment_status
        string payment_reference
        string confirmation_code
        int confirmation_attempts
        boolean confirmation_locked
        text special_instructions
        string delivery_address
        decimal delivery_lat
        decimal delivery_lng
        decimal pickup_lat
        decimal pickup_lng
        timestamp accepted_at
        timestamp picked_up_at
        timestamp delivered_at
        timestamps
    }

    order_items {
        bigint id PK
        bigint order_id FK
        bigint product_id FK
        int quantity
        decimal unit_price
        string product_name_snapshot
        timestamps
    }

    parcels {
        bigint id PK
        bigint sender_id FK
        bigint courier_id FK
        bigint city_id FK
        string size
        decimal weight_kg
        decimal declared_value
        boolean has_insurance
        decimal insurance_amount
        string sender_code
        string recipient_code
        string recipient_name
        string recipient_phone
        string delivery_address
        decimal delivery_lat
        decimal delivery_lng
        decimal pickup_lat
        decimal pickup_lng
        string description
        string status
        decimal shipping_fee
        decimal platform_commission
        decimal courier_amount
        string payment_method
        string payment_status
        string payment_reference
        int assignment_attempts
        int sender_code_attempts
        boolean sender_code_locked
        int recipient_code_attempts
        boolean recipient_code_locked
        timestamp picked_up_at
        timestamp delivered_at
        timestamps
    }

    parcel_photos {
        bigint id PK
        bigint parcel_id FK
        string type
        string photo_url
        decimal lat
        decimal lng
        timestamps
    }

    wallets {
        bigint id PK
        string owner_type
        bigint owner_id
        decimal balance
        decimal total_earned
        decimal total_withdrawn
        timestamps
    }

    wallet_transactions {
        bigint id PK
        bigint wallet_id FK
        string type
        decimal amount
        decimal balance_before
        decimal balance_after
        string reference
        string description
        string status
        bigint order_id FK
        bigint parcel_id FK
        timestamps
    }

    ratings {
        bigint id PK
        bigint rater_id FK
        string type
        bigint rateable_id
        int rating
        text comment
        bigint order_id FK
        bigint parcel_id FK
        timestamps
    }

    notifications {
        bigint id PK
        bigint user_id FK
        string type
        string title
        string body
        json data
        boolean is_read
        timestamp read_at
        timestamps
    }

    webhook_logs {
        bigint id PK
        string source
        string event_type
        json payload
        string signature_received
        boolean signature_valid
        string status
        text error_message
        int retry_count
        timestamps
    }

    admin_sessions {
        bigint id PK
        bigint user_id FK
        string ip_address
        string device_info
        string session_token
        timestamp last_activity
        boolean is_active
        timestamps
    }

    activity_logs {
        bigint id PK
        bigint user_id FK
        string action
        string entity_type
        bigint entity_id
        json properties
        string ip_address
        string severity
        timestamps
    }

    otp_codes {
        bigint id PK
        string phone
        string code_hash
        string purpose
        int attempts
        timestamp expires_at
        boolean is_used
        timestamps
    }

    cities ||--o{ users : "city_id"
    cities ||--o{ merchants : "city_id"
    cities ||--o{ couriers : "city_id"
    cities ||--o{ orders : "city_id"
    cities ||--o{ parcels : "city_id"

    users ||--o| merchants : "user_id"
    users ||--o| couriers : "user_id"
    users ||--o{ orders : "client_id"
    users ||--o{ parcels : "sender_id"
    users ||--o{ notifications : "user_id"
    users ||--o{ ratings : "rater_id"
    users ||--o{ admin_sessions : "user_id"
    users ||--o{ activity_logs : "user_id"

    merchants ||--o{ products : "merchant_id"
    merchants ||--o{ orders : "merchant_id"
    merchants ||--o| wallets : "owner_id (merchant)"

    couriers ||--o{ orders : "courier_id"
    couriers ||--o{ parcels : "courier_id"
    couriers ||--o| wallets : "owner_id (courier)"

    orders ||--o{ order_items : "order_id"
    orders ||--o{ ratings : "order_id"
    orders ||--o{ wallet_transactions : "order_id"

    products ||--o{ order_items : "product_id"

    parcels ||--o{ parcel_photos : "parcel_id"
    parcels ||--o{ wallet_transactions : "parcel_id"
    parcels ||--o{ ratings : "parcel_id"

    wallets ||--o{ wallet_transactions : "wallet_id"
```

---

## Description des tables

### Table `cities`
Référentiel des villes opérationnelles. Ajouter Bouaké = insérer une ligne ici.

| Colonne | Type | Contrainte | Description |
|---|---|---|---|
| id | BIGSERIAL | PK | — |
| name | VARCHAR(100) | NOT NULL, UNIQUE | "Daloa", "Bouaké" |
| is_active | BOOLEAN | DEFAULT true | Désactive une ville sans supprimer |
| center_lat | DECIMAL(10,8) | NOT NULL | Centre géographique pour météo |
| center_lng | DECIMAL(11,8) | NOT NULL | — |

---

### Table `users`
Tous les utilisateurs de la plateforme : clients, merchants, drivers ET admins.

| Colonne | Type | Contrainte | Description |
|---|---|---|---|
| id | BIGSERIAL | PK | — |
| name | VARCHAR(150) | NOT NULL | — |
| phone | VARCHAR(20) | UNIQUE, NOT NULL | Identifiant principal |
| role | VARCHAR(50) | NOT NULL | client, merchant, driver, super_admin, city_manager… |
| city_id | BIGINT | FK cities, NULLABLE | NULL pour les admins nationaux |
| device_token | VARCHAR(255) | NULLABLE | Token FCM pour push notifications |
| session_token | VARCHAR(255) | NULLABLE | Session unique merchant/driver |
| is_active | BOOLEAN | DEFAULT true | Désactivation sans soft delete |
| phone_verified_at | TIMESTAMP | NULLABLE | NULL = non vérifié |
| deleted_at | TIMESTAMP | NULLABLE | Soft delete |

**Index** : `phone` (UNIQUE), `(role, city_id)`, `(is_active, role)`

---

### Table `wallets`
Portefeuilles virtuels — merchants et couriers uniquement. Utilise une relation polymorphique (`owner_type` = 'merchant' ou 'courier', `owner_id` = FK).

**Contrainte** : `owner_type` IN ('merchant', 'courier') — les clients n'ont pas de wallet.

---

### Table `orders` — Statuts GoShop

| Statut | Signification |
|---|---|
| pending | Commande créée, en attente commerce |
| accepted | Commerce accepté, préparation en cours |
| refused | Commerce a refusé volontairement |
| ready | Commande prête, en attente livreur |
| picked_up | Livreur a récupéré |
| in_transit | En cours de livraison |
| delivered | Livrée avec succès |
| cancelled | Annulée (timeout ou Admin) |

---

### Table `parcels` — Statuts GoColis

| Statut | Signification |
|---|---|
| pending | Expédition créée, recherche livreur |
| waiting | Aucun livreur après 3 tentatives — alerte Admin |
| accepted | Livreur accepté |
| at_sender | Livreur chez l'expéditeur |
| picked_up | Colis récupéré (photo + code expéditeur validés) |
| in_transit | En cours de livraison |
| at_recipient | Livreur chez le destinataire |
| delivered | Livré (photo + code destinataire validés) |
| refused | Refusé par le livreur (colis suspect) |
| cancelled | Annulée |

---

### Table `ratings` — Types

| Type | Qui note | Qui est noté |
|---|---|---|
| goshop_merchant | Client | Commerce |
| goshop_courier | Client | Livreur |
| gocolis_courier | Expéditeur | Livreur |

---

### Table `webhook_logs`
Chaque webhook Hub2/CinetPay est loggé intégralement avant tout traitement. Permet le rejeu depuis Horizon en cas d'échec du job.

**Règle** : Un webhook avec `signature_valid = false` ne génère jamais de traitement. Il est loggé avec `status = 'rejected'` et une alerte `critical` dans `activity_logs`.

---

### Soft delete — Tables concernées

| Table | Soft delete | Raison |
|---|---|---|
| users | ✅ | Compte désactivé récupérable |
| merchants | ✅ | Commerce peut revenir |
| couriers | ✅ | Livreur peut revenir |
| products | ✅ | Produit retiré temporairement |
| orders | ❌ | Historique immuable (litiges) |
| parcels | ❌ | Historique immuable (litiges) |
| wallets | ❌ | Traçabilité financière absolue |
| wallet_transactions | ❌ | Traçabilité financière absolue |
| ratings | ❌ | Pas de suppression |
| activity_logs | ❌ | Audit immuable |

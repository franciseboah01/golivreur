# GoLivreur — Diagrammes de Séquence Authentification

---

## 1. Inscription Client (OTP SMS)

```mermaid
sequenceDiagram
    participant C as GoLivreur Client
    participant API as API Laravel
    participant SMS as Twilio/Orange API
    participant DB as PostgreSQL
    participant R as Redis

    C->>API: POST /api/v1/auth/register<br/>{phone: "0700000001", name: "Kouassi Ama"}
    API->>R: Vérifie rate limit OTP (3/10min par numéro)
    R-->>API: OK (première demande)
    API->>DB: Crée user (statut: pending_verification)
    API->>R: Stocke OTP hashé (bcrypt) + expiration 5 min
    API->>SMS: Envoie SMS "Votre code GoLivreur : 4829"
    SMS-->>API: 200 OK
    API-->>C: 200 {message: "Code envoyé au 07****01"}

    C->>API: POST /api/v1/auth/verify-otp<br/>{phone: "0700000001", otp: "4829"}
    API->>R: Récupère OTP hashé + vérifie expiration
    API->>DB: bcrypt verify(otp, hash)
    DB-->>API: Valid
    API->>DB: User statut → active, email_verified_at = now()
    API->>R: Supprime OTP
    API->>DB: Génère JWT access token (1h) + refresh token (7j)
    API-->>C: 201 {access_token, refresh_token, user: {...}}

    Note over C,R: Le client est connecté et peut utiliser l'app
```

---

## 2. Connexion Client (téléphone + OTP)

```mermaid
sequenceDiagram
    participant C as GoLivreur Client
    participant API as API Laravel
    participant R as Redis
    participant SMS as Twilio/Orange API
    participant DB as PostgreSQL

    C->>API: POST /api/v1/auth/login<br/>{phone: "0700000001"}
    API->>DB: Cherche user par numéro
    DB-->>API: User trouvé (statut: active)
    API->>R: Vérifie rate limit (3 tentatives/10min)
    R-->>API: OK
    API->>R: Stocke OTP hashé + expiration 5 min
    API->>SMS: Envoie SMS "Votre code de connexion : 7312"
    API-->>C: 200 {message: "Code envoyé"}

    C->>API: POST /api/v1/auth/verify-login<br/>{phone: "0700000001", otp: "7312"}
    API->>R: Vérifie OTP (bcrypt)
    R-->>API: Valid

    alt Merchant ou Driver
        API->>DB: Révoque ancienne session (SingleSessionMiddleware)
        API->>DB: Crée nouvelle session + device_token
    end

    API->>DB: Génère JWT access_token (1h) + refresh_token (7j)
    API-->>C: 200 {access_token, refresh_token, user: {...}, role: "client"}
```

---

## 3. Refresh Token JWT

```mermaid
sequenceDiagram
    participant APP as Application Flutter
    participant API as API Laravel
    participant DB as PostgreSQL

    Note over APP: Access token expiré (1h écoulée)
    APP->>API: POST /api/v1/auth/refresh<br/>Authorization: Bearer {refresh_token}
    API->>DB: Vérifie refresh_token (non révoqué, non expiré)
    DB-->>API: Valid (expire dans 6j)
    API->>DB: Génère nouveau access_token (1h)
    API->>DB: Rotate refresh_token (nouveau token 7j)
    API-->>APP: 200 {access_token, refresh_token}

    Note over APP: Intercepteur Dio met à jour les tokens en mémoire
```

---

## 4. Connexion Admin (email + mot de passe + 2FA)

```mermaid
sequenceDiagram
    participant ADM as Navigateur Admin
    participant WEB as Laravel Filament
    participant DB as PostgreSQL
    participant SMS as Twilio/Orange API
    participant R as Redis

    ADM->>WEB: POST /admin/login<br/>{email, password}
    WEB->>DB: Vérifie email + bcrypt password
    DB-->>WEB: Admin trouvé (rôle: city_manager)

    WEB->>DB: Génère code 2FA (6 chiffres, hashé, 5 min)
    WEB->>SMS: Envoie SMS "Code admin GoLivreur : 847291"
    WEB-->>ADM: Redirige vers /admin/2fa

    ADM->>WEB: POST /admin/2fa<br/>{code: "847291"}
    WEB->>DB: Vérifie code 2FA (bcrypt + expiration)
    DB-->>WEB: Valid
    WEB->>DB: Invalide le code 2FA
    WEB->>R: Crée session admin avec IP + user_agent
    WEB->>DB: Log dans admin_sessions (ip, device_info, timestamp)

    alt Connexion depuis une nouvelle IP
        WEB->>DB: Log alerte dans activity_logs (sévérité: warning)
        WEB->>ADM: Email "Nouvelle connexion détectée depuis [IP]"
    end

    WEB-->>ADM: Redirige vers /admin/dashboard
    Note over ADM,R: Session expire après inactivité (2h Super Admin, 4h City Manager, 8h City Agent)
```

---

## 5. Déconnexion forcée (Merchant/Driver — session unique)

```mermaid
sequenceDiagram
    participant APP2 as Nouveau téléphone (Merchant)
    participant API as API Laravel
    participant DB as PostgreSQL
    participant APP1 as Ancien téléphone (Merchant)

    APP2->>API: POST /api/v1/auth/login<br/>{phone: "0708000001", otp: "5521"}
    API->>DB: Vérifie OTP → Valid
    API->>DB: Cherche session active existante pour ce merchant
    DB-->>API: Session active trouvée (device_token: "abc123")
    API->>DB: Révoque ancien session_token
    API->>DB: Envoie notification FCM "Déconnexion — nouvelle connexion détectée"

    Note over APP1: Reçoit notification FCM et est déconnecté
    Note over APP1: Prochaine requête API → 401 Unauthorized

    API->>DB: Crée nouvelle session (device_token: "xyz789")
    API-->>APP2: 200 {access_token, refresh_token}
```

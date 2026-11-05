# GUIDE D'EXÉCUTION — GoLivreur v5.0
## À suivre à la lettre, étape par étape, par Francis EBOAH

> Ce guide couvre tout : installation des logiciels, comptes et API externes à créer, organisation des fichiers, et les prompts exacts à copier-coller dans Claude Code à chaque situation (démarrage, reprise après coupure, changement de sprint, blocage).
> Tu n'as jamais besoin d'écrire une phrase technique toi-même — tu copies, tu colles, tu adaptes juste ce qui est entre [crochets].

---

# PARTIE 1 — INSTALLATION DES LOGICIELS (une seule fois)

Fais ça dans l'ordre. Chaque étape doit être terminée avant de passer à la suivante.

## 1.1. Docker Desktop

**Pourquoi :** fait tourner la base de données et les services du projet sur ta machine.

1. Va sur **https://www.docker.com/products/docker-desktop/**
2. Télécharge la version pour ton système (Windows / Mac)
3. Installe comme un logiciel normal (double-clic, suivant, suivant, terminer)
4. Redémarre ta machine si demandé
5. Ouvre Docker Desktop une fois — tu dois voir l'icône de la baleine active dans ta barre des tâches

**Vérification :** ouvre un terminal et tape :
```bash
docker --version
```
Tu dois voir un numéro de version s'afficher. Si erreur → redémarre ta machine.

---

## 1.2. Node.js

**Pourquoi :** nécessaire pour Claude Code et certains outils du projet.

1. Va sur **https://nodejs.org**
2. Télécharge la version **LTS** (celle marquée "Recommandé pour la plupart des utilisateurs")
3. Installe comme un logiciel normal

**Vérification :**
```bash
node --version
npm --version
```
Les deux doivent afficher un numéro de version.

---

## 1.3. Claude Code

**Pourquoi :** c'est l'outil avec qui tu vas discuter pour construire GoLivreur.

Une fois Node.js installé, ouvre un terminal et tape :
```bash
npm install -g @anthropic-ai/claude-code
```

**Vérification :**
```bash
claude --version
```

Ensuite connecte ton compte :
```bash
claude
```
Suis les instructions à l'écran pour te connecter avec ton compte Anthropic (Claude Pro ou Claude Max).

---

## 1.4. Flutter SDK

**Pourquoi :** nécessaire pour compiler les 3 apps mobiles (Client, Business, Driver).

1. Va sur **https://docs.flutter.dev/get-started/install**
2. Choisis ton système (Windows / Mac)
3. Suis le guide officiel — il te fera télécharger un ZIP et l'ajouter au PATH de ta machine
4. Installe aussi **Android Studio** (proposé dans le même guide) — nécessaire pour l'émulateur Android

**Vérification :**
```bash
flutter doctor
```
Ce n'est pas grave si tout n'est pas ✅ au premier coup — corrige un point à la fois en suivant ce que `flutter doctor` te recommande, jusqu'à avoir un maximum de coches vertes.

---

## 1.5. Git

**Pourquoi :** pour sauvegarder le code au fur et à mesure (comme une machine à remonter le temps du projet).

1. Va sur **https://git-scm.com/downloads**
2. Télécharge et installe pour ton système

**Vérification :**
```bash
git --version
```

---

## 1.6. Un terminal confortable (optionnel mais recommandé)

Tu peux utiliser le terminal par défaut de ta machine, mais si tu veux plus confortable :
- **Windows** : installe **Windows Terminal** (Microsoft Store, gratuit)
- **Mac** : le Terminal par défaut suffit très bien

---

# PARTIE 2 — COMPTES ET API EXTERNES À CRÉER

> Cette partie liste TOUT ce que tu dois créer en dehors de ta machine : comptes en ligne, clés API, moyens de paiement. Chaque service est classé par urgence pour que tu saches quoi faire en premier.

## 2.1. Vue d'ensemble — tous les accès nécessaires

| # | Service | Pourquoi | Coût estimé | Urgence |
|---|---|---|---|---|
| 1 | Hub2 ou CinetPay | Paiement Orange Money | Frais par transaction | 🔴 **S1 — dès maintenant** |
| 2 | Nom de domaine `golivreur.ci` | Adresse du site et des emails | ~15 000 F CFA/an | 🔴 S1 |
| 3 | VPS DigitalOcean | Serveur qui héberge tout | ~600 000 F CFA/an | 🔴 S1 |
| 4 | DigitalOcean Spaces | Stockage des photos GoColis | ~100 000 F CFA/an | 🔴 S1 |
| 5 | Google Cloud (Maps API) | Cartes et géolocalisation | ~150 000 F CFA/an | 🟠 S2-S3 |
| 6 | Firebase | Notifications push + Crashlytics | Gratuit | 🟠 S2-S3 |
| 7 | Twilio ou Orange API | Envoi de SMS OTP | ~65 000 F CFA/mois | 🟠 S2 |
| 8 | Google Play Console | Publier les apps Android | 25 000 F CFA (unique) | 🟡 S3-S4 |
| 9 | Apple Developer Program | Publier l'app iOS | ~50 000 F CFA/an | 🟡 S3-S4 |
| 10 | GitHub | Stockage du code, CI/CD | Gratuit | 🔴 S1 |
| 11 | Sentry | Suivi des erreurs | Gratuit (plan de base) | 🟢 S1, sans urgence |
| 12 | Bitwarden | Coffre-fort pour toutes les clés | Gratuit | 🔴 **Avant tout le reste** |
| 13 | OpenWeatherMap | Mode Pluie automatique | Gratuit | 🟢 S10 |

**Légende urgence :** 🔴 Bloquant, à faire immédiatement — 🟠 Nécessaire avant le sprint concerné — 🟡 Nécessaire avant le lancement public — 🟢 Non bloquant

---

## 2.2. Étape 0 : Bitwarden (à faire en tout premier, avant tout le reste)

Bitwarden est ton coffre-fort numérique. Chaque clé API que tu vas créer dans les étapes suivantes sera stockée là, jamais ailleurs, jamais dans un chat, jamais dans un fichier texte simple sur ton bureau.

1. Va sur **https://bitwarden.com**
2. Crée un compte gratuit avec ton email professionnel
3. Installe l'extension navigateur ET l'application mobile
4. Crée un dossier nommé **"GoLivreur"**
5. À l'intérieur, crée une "Note sécurisée" pour chaque service listé au tableau 2.1 — tu rempliras chaque note au fur et à mesure que tu crées les comptes

**Quand donner l'accès Bitwarden au Lead Dev :** une fois recruté (prévu Sprint 1), tu l'invites dans le dossier "GoLivreur" uniquement — pas dans ton coffre personnel entier. Bitwarden permet de partager un dossier précis sans donner accès au reste.

---

## 2.3. Les comptes à créer, dans l'ordre, avec le détail

### 🔴 Hub2 ou CinetPay (Paiement Mobile Money)

**C'est quoi :** l'intermédiaire technique entre GoLivreur et Orange Money. C'est lui qui confirme qu'un client a bien payé.

**Où :** Hub2 → **https://hub2.io** · CinetPay → **https://cinetpay.com**

**Comment choisir :** compare les frais par transaction et le délai d'ouverture de compte au moment où tu t'inscris — les conditions changent, vérifie sur leur site le jour J.

**Démarche :**
1. Remplis le formulaire d'inscription marchand sur leur site
2. Ils demandent : registre de commerce (RCCM), pièce d'identité, RIB ou compte Mobile Money pour recevoir les fonds
3. Délai d'ouverture : **entre 2 et 4 semaines** — lance cette démarche **dès le premier jour du projet**, en parallèle de tout le reste
4. Une fois validé : **clé API**, **clé secrète**, accès à un **environnement sandbox** (pour tester sans vrai argent)

**Où stocker :** Bitwarden → note "Hub2/CinetPay"

**Quand le donner à Claude Code :** Sprint 7 (Paiement et notifications).

---

### 🔴 Nom de domaine golivreur.ci

**Où :** **NIC-CI** (registre officiel ivoirien, https://www.nic.ci) ou un registraire international proposant le `.ci`.

**Démarche :**
1. Vérifie que `golivreur.ci` est disponible
2. Achète-le (~15 000 F CFA/an)
3. Note les identifiants du panneau de gestion dans Bitwarden

**Quand le donner à Claude Code :** Sprint 1, une fois le VPS loué — pour pointer le domaine vers l'IP du serveur.

---

### 🔴 VPS DigitalOcean (le serveur)

**C'est quoi :** l'ordinateur distant, allumé 24h/24, qui fait tourner le backend, la base de données et l'admin.

**Où :** **https://www.digitalocean.com**

**Démarche :**
1. Crée un compte, ajoute un moyen de paiement
2. Ne crée PAS encore le serveur toi-même — Claude Code te guidera pour la bonne configuration (8 Go RAM / 4 CPU, Ubuntu 24.04)
3. Génère un **Token API** (Settings → API → Generate New Token)

**Où stocker :** Bitwarden → note "DigitalOcean" (email, mot de passe, Token API)

**Quand le donner à Claude Code :** Sprint 1.

---

### 🔴 DigitalOcean Spaces (stockage des photos)

**C'est quoi :** l'espace de stockage pour les photos GoColis et les backups.

**Démarche :**
1. Dans ton compte DigitalOcean → section "Spaces" → crée un Space nommé `golivreur-storage`
2. Génère une **clé d'accès Spaces** : Settings → API → Spaces access keys → Generate New Key
3. Tu obtiens une **Access Key** et une **Secret Key**

**Où stocker :** Bitwarden → note "DigitalOcean Spaces"

**Quand le donner à Claude Code :** Sprint 1 (config), utilisé dès Sprint 4 (photos GoColis) et pour les backups.

---

### 🟠 Google Cloud — Google Maps API

**C'est quoi :** les cartes affichées dans les 3 apps, le calcul de distance, le suivi GPS des livreurs.

**Où :** **https://console.cloud.google.com**

**Démarche :**
1. Crée un compte (email dédié au projet si possible)
2. Crée un projet "GoLivreur"
3. Active une carte bancaire (ne débite rien sous le quota gratuit)
4. Active : **Maps SDK for Android**, **Maps SDK for iOS**, **Directions API**, **Geocoding API**
5. Crée une clé API : APIs & Services → Credentials → Create Credentials → API Key
6. **Important :** restreins la clé à tes 3 apps (par nom de package/bundle) — Claude Code peut t'expliquer comment

**Où stocker :** Bitwarden → note "Google Maps API"

**Quand le donner à Claude Code :** avant le Sprint 4 (écran de suivi GPS).

---

### 🟠 Firebase (notifications + crash reporting)

**C'est quoi :** l'envoi des notifications push (FCM) et la détection automatique des plantages d'app (Crashlytics).

**Où :** **https://console.firebase.google.com**

**Démarche :**
1. Connecte-toi avec le même compte Google que Google Cloud
2. Crée un projet "GoLivreur"
3. Ajoute 3 applications (une par app Flutter) — Firebase donne un fichier de configuration par app (`google-services.json` / `GoogleService-Info.plist`)
4. Active **Cloud Messaging** et **Crashlytics**

**Où stocker :** ces fichiers ne sont pas des mots de passe — Claude Code te dira où les placer directement dans le code (`android/app/` et `ios/Runner/` de chaque app).

**Coût :** gratuit sous quota.

**Quand le donner à Claude Code :** Sprint 2.

---

### 🟠 Twilio ou Orange API (SMS OTP)

**C'est quoi :** l'envoi des codes de vérification par SMS (inscription, code destinataire GoColis).

**Où :** Twilio → **https://www.twilio.com** · Orange Developer → **https://developer.orange.com**

**Démarche (Twilio) :**
1. Crée un compte, vérifie ton propre numéro pour tester
2. Achète un numéro d'envoi ou utilise un Sender ID alphanumérique — vérifie la couverture SMS vers les numéros ivoiriens avant de t'engager
3. Récupère : **Account SID**, **Auth Token**, numéro/Sender ID

**Où stocker :** Bitwarden → note "Twilio" (ou "Orange SMS API")

**Quand le donner à Claude Code :** Sprint 2.

**Attention budget :** ~65 000 F CFA/mois prévu — surveille dès le premier mois, une alerte automatique t'informe si tu dépasses 2 500 SMS/mois.

---

### 🟡 Google Play Console (publication Android)

**Où :** **https://play.google.com/console**

**Démarche :**
1. Crée un compte développeur
2. Paye les frais d'inscription unique (~25 000 F CFA)
3. Complète le profil développeur (entreprise, coordonnées)

**Quand :** à créer avant S15 (Sprint 9).

---

### 🟡 Apple Developer Program (publication iOS)

**Où :** **https://developer.apple.com/programs/**

**Démarche :**
1. Crée un compte avec un Apple ID dédié au projet
2. Inscris-toi au programme (~50 000 F CFA/an)
3. Anticipe : Apple peut demander une vérification d'identité qui prend quelques jours

**Quand :** à créer avant S15 (Sprint 9), en parallèle de Google Play.

---

### 🔴 GitHub (stockage du code)

**Où :** **https://github.com**

**Démarche :**
1. Crée un compte gratuit
2. Crée une **organisation** nommée "GoLivreur" (plutôt qu'un compte personnel)
3. Crée un repository privé nommé `golivreur`
4. Ajoute le Lead Dev comme collaborateur une fois recruté

**Où configurer les secrets d'automatisation :** Settings → Secrets and variables → Actions — ajoute les clés listées dans `GITHUB_SECRETS.md` (Livrable 04). C'est le seul endroit, avec Bitwarden et ton `.env` local, où des clés sont stockées — chiffrées par GitHub.

**Quand :** dès le Sprint 1.

---

### 🟢 Sentry (suivi des erreurs)

**Où :** **https://sentry.io**

**Démarche :** crée un compte gratuit, crée un projet "GoLivreur Backend" (et un par app si tu veux les distinguer), récupère le "DSN" de chaque projet.

**Coût :** gratuit jusqu'à 5 000 erreurs/mois.

**Quand :** Sprint 1, pas bloquant si en retard.

---

### 🟢 OpenWeatherMap (Mode Pluie automatique)

**Où :** **https://openweathermap.org/api**

**Démarche :** crée un compte gratuit, récupère la clé API (limite 1 000 appels/jour, largement suffisant).

**Quand :** Sprint 10, pas bloquant avant.

---

## 2.4. Comment donner ces accès à Claude Code, en sécurité

### Règle absolue à respecter toujours

> **Une clé API réelle ne se colle JAMAIS dans Claude.ai (ce chat web). Elle se colle UNIQUEMENT dans ton terminal, à Claude Code, sur ta machine.**

Ce chat-ci (claude.ai) et Claude Code (dans ton terminal) sont deux choses différentes. Le premier sert à réfléchir et documenter. Le second exécute et a besoin des vraies clés pour connecter le projet aux vrais services.

### La méthode concrète, étape par étape

1. Tu crées le compte du service (ex : Twilio)
2. Tu copies la clé API générée
3. Tu la colles **dans Bitwarden**, dans le dossier "GoLivreur"
4. Quand Claude Code te demande cette clé (il te le dira explicitement — voir Prompt 5.5 plus bas), tu :
   - Ouvres Bitwarden
   - Copies la clé
   - Colles-la directement dans ton terminal, dans le message que tu envoies à Claude Code

Claude Code écrira alors cette clé dans le fichier `.env` de ton projet — un fichier qui **ne part jamais sur GitHub** (exclu automatiquement via un `.gitignore` déjà prévu dans les livrables).

### Ce qu'il ne faut jamais faire

- ❌ Ne jamais coller une vraie clé API dans ce chat (claude.ai)
- ❌ Ne jamais envoyer une clé API par email ou WhatsApp non chiffré
- ❌ Ne jamais committer un fichier `.env` sur GitHub
- ❌ Ne jamais réutiliser le même mot de passe entre Bitwarden, GitHub, DigitalOcean, etc.

---

## 2.5. Calendrier récapitulatif des accès

```
SEMAINE 1 (Sprint 1) — À FAIRE IMMÉDIATEMENT
├── Bitwarden (coffre-fort) ─────────────── EN PREMIER, avant tout
├── Hub2 ou CinetPay ─────────────────────── 🔴 démarche longue, lancer aujourd'hui
├── Nom de domaine golivreur.ci ──────────── 🔴
├── VPS DigitalOcean ──────────────────────── 🔴
├── DigitalOcean Spaces ───────────────────── 🔴
└── GitHub (organisation + repo) ──────────── 🔴

SEMAINE 3-4 (Sprint 2) — AVANT L'AUTHENTIFICATION
├── Firebase (notifications) ──────────────── 🟠
└── Twilio ou Orange API (SMS) ─────────────── 🟠

SEMAINE 5-8 (Sprint 3-4) — AVANT LE MODULE CLIENT
└── Google Maps API ────────────────────────── 🟠

SEMAINE 13-16 (Sprint 8-9) — AVANT LE LANCEMENT PUBLIC
├── Google Play Console ────────────────────── 🟡
└── Apple Developer Program ────────────────── 🟡

DÈS QUE POSSIBLE, NON BLOQUANT
├── Sentry ─────────────────────────────────── 🟢
└── OpenWeatherMap (Sprint 10 uniquement) ──── 🟢
```

---

# PARTIE 3 — ORGANISATION DES FICHIERS

## 3.1. Crée le dossier principal

Ouvre un terminal et tape :
```bash
cd Desktop
mkdir golivreur
cd golivreur
```

*(Tu peux remplacer `Desktop` par l'emplacement que tu préfères — Documents, etc.)*

## 3.2. Structure exacte à créer

À l'intérieur du dossier `golivreur`, crée cette arborescence exacte :

```
golivreur/
│
├── CLAUDE.md                          ← fichier de pilotage
├── PROGRESS.md                        ← fichier de pilotage
├── DECISIONS.md                       ← fichier de pilotage
├── ERRORS.md                          ← fichier de pilotage
├── NEXT_TASK.md                       ← fichier de pilotage
├── GUIDE_EXECUTION_GOLIVREUR.md        ← ce guide-ci
│
├── CDC/
│   └── GoLivreur_CahierDesCharges_v5.md
│
├── docs/                              ← contenu du ZIP 01 décompressé ici
├── backend/                           ← contenu du ZIP 02 décompressé ici
├── mobile/                            ← contenu du ZIP 03 décompressé ici
├── devops/                            ← contenu du ZIP 04 décompressé ici
└── api-tools/                         ← contenu du ZIP 05 décompressé ici
```

## 3.3. Comment décompresser chaque ZIP au bon endroit

Pour chaque ZIP livrable :

1. Crée le dossier de destination (ex: `mkdir backend`)
2. Décompresse le ZIP correspondant
3. Copie **le contenu** du ZIP décompressé (pas le dossier lui-même) directement dans `backend/`, `mobile/`, etc.

**Vérification finale :** tape `ls` (Mac) ou `dir` (Windows) dans le dossier `golivreur` — tu dois voir exactement les 6 fichiers `.md` + les 5 dossiers listés ci-dessus.

---

# PARTIE 4 — INITIALISER GIT (une seule fois)

Toujours dans le dossier `golivreur`, tape :

```bash
git init
git add .
git commit -m "Initialisation du projet GoLivreur v5.0"
```

Ceci crée ton premier point de sauvegarde. Tu pourras toujours revenir ici si quelque chose casse gravement.

---

# PARTIE 5 — LES PROMPTS À UTILISER

> Tout se passe dans le terminal. Tu tapes `claude` pour démarrer une session, puis tu colles le prompt correspondant à ta situation.

## 5.1. 🚀 PROMPT DE DÉMARRAGE — Sprint 1 (à utiliser UNE SEULE FOIS, au tout début)

Ouvre un terminal, place-toi dans le dossier `golivreur` :
```bash
cd Desktop/golivreur
claude
```

Puis colle ce prompt :

```
Lis entièrement les fichiers suivants dans cet ordre :
1. CLAUDE.md
2. CDC/GoLivreur_CahierDesCharges_v5.md
3. PROGRESS.md
4. NEXT_TASK.md
5. DECISIONS.md
6. ERRORS.md

Confirme-moi en 3 phrases maximum que tu as compris le projet GoLivreur,
son objectif, et la tâche actuelle à exécuter selon NEXT_TASK.md.

Ensuite, exécute cette tâche en autonomie complète.
Ne me pose aucune question technique — décide et documente tes choix
dans DECISIONS.md. Ne me sollicite que si tu as besoin d'un accès,
d'une clé API réelle, ou d'une décision business, comme précisé
dans la section 9 de CLAUDE.md.

Quand la tâche est terminée et validée, mets à jour PROGRESS.md,
DECISIONS.md si nécessaire, ERRORS.md si nécessaire, et réécris
NEXT_TASK.md avec la prochaine étape précise. Confirme-moi ensuite
en langage simple, sans jargon technique, ce qui a été fait et
ce qu'il reste à faire.
```

---

## 5.2. 🔁 PROMPT DE REPRISE — après une coupure, fermeture, ou nouvelle session

À utiliser à chaque fois que tu rouvres Claude Code (nouvelle journée, après une coupure internet, après avoir fermé le terminal).

```bash
cd Desktop/golivreur
claude
```

Puis colle :

```
Reprise de session — projet GoLivreur.

Lis PROGRESS.md et NEXT_TASK.md pour savoir où on en est.
Si besoin, relis CLAUDE.md et DECISIONS.md pour te remettre
dans le contexte des règles déjà établies.

Résume-moi en 3 phrases maximum, en langage simple :
1. Ce qui a été fait jusqu'ici
2. Ce sur quoi tu vas travailler maintenant
3. Si tu es bloqué sur quelque chose qui nécessite mon action

Ensuite continue l'exécution du projet en autonomie, en suivant
les mêmes règles que d'habitude (section 8 et 9 de CLAUDE.md).
```

---

## 5.3. ✅ PROMPT DE VALIDATION — quand tu veux tester toi-même ce qui a été fait

```
Je veux tester ce qui vient d'être fait sur mon téléphone /
mon navigateur avant de continuer.

Donne-moi la liste exacte des étapes pour voir et tester ce
qui a été développé : quelle commande lancer, quelle URL ouvrir,
quel compte de test utiliser (téléphone, code, etc.).
Explique comme si je n'étais pas développeur.
```

---

## 5.4. ➡️ PROMPT DE PASSAGE AU SPRINT SUIVANT

À utiliser uniquement quand tu as testé et validé toi-même que le sprint en cours fonctionne.

```
J'ai testé et validé le sprint actuel de mon côté, tout fonctionne
comme attendu.

Mets à jour PROGRESS.md pour marquer ce sprint comme terminé,
puis passe au sprint suivant selon le planning du CDC (Chapitre 13).
Réécris NEXT_TASK.md avec la première tâche précise de ce nouveau
sprint, puis commence à l'exécuter.
```

---

## 5.5. 🆘 PROMPT DE BLOCAGE — quand Claude Code te dit qu'il a besoin de toi

Si Claude Code t'indique qu'il lui manque une clé API, un compte, ou une décision business, réponds avec ce type de prompt (adapte selon la demande) :

```
Voici l'information demandée : [colle ici la clé, l'information,
ou la décision demandée — copiée depuis Bitwarden].

Continue l'exécution du projet à partir de là où tu t'étais arrêté.
```

**Exemple concret :**
```
Voici l'information demandée : la clé API Google Maps est
AIzaSy... (celle que j'ai récupérée depuis Bitwarden).

Continue l'exécution du projet à partir de là où tu t'étais arrêté.
```

---

## 5.6. 🐛 PROMPT QUAND QUELQUE CHOSE NE MARCHE PAS

Si tu testes quelque chose et que ça ne fonctionne pas comme attendu :

```
J'ai testé [décris ce que tu as essayé de faire] et voici ce qui
s'est passé à la place : [décris ce que tu as vu — message d'erreur,
écran bloqué, comportement bizarre, etc. — copie une capture d'écran
si possible].

Vérifie d'abord dans ERRORS.md si ce problème a déjà été rencontré.
Ensuite corrige-le, documente la solution dans ERRORS.md, et
confirme-moi quand c'est réglé.
```

---

## 5.7. 📊 PROMPT POUR UN POINT D'ÉTAPE (à utiliser quand tu veux juste un résumé)

```
Fais-moi un résumé de l'avancement global du projet GoLivreur,
en langage simple, sans jargon technique.
Je veux savoir : combien de sprints sont terminés, ce qui reste
à faire, et s'il y a des points bloquants qui nécessitent mon
attention.
```

---

## 5.8. 🔒 PROMPT AVANT UN DÉPLOIEMENT EN PRODUCTION (Sprint 9 et au-delà)

À utiliser uniquement quand le projet arrive au stade du vrai lancement à Daloa.

```
Avant de déployer quoi que ce soit en production, montre-moi
la liste complète des changements qui vont être déployés,
en langage simple. Attends ma confirmation explicite avant
de lancer le déploiement.
```

---

# PARTIE 6 — RÈGLES DE SÉCURITÉ POUR TOI

Quelques règles simples à respecter, toujours :

1. **Ne partage jamais tes vraies clés API dans ce chat-ci (Claude.ai)** — donne-les uniquement à Claude Code sur ta machine, jamais ici. Elles vivent dans Bitwarden entre les deux.
2. **Fais un `git commit` régulièrement** — au minimum à la fin de chaque sprint validé :
   ```bash
   git add .
   git commit -m "Sprint X terminé et validé"
   ```
   Ça te permet de revenir en arrière si besoin.
3. **Ne modifie jamais toi-même le code directement** — passe toujours par un prompt à Claude Code, même pour une petite modification. Ça garde `PROGRESS.md` et `DECISIONS.md` à jour.
4. **Si Claude Code veut supprimer un fichier ou une table de données**, demande-lui toujours d'expliquer pourquoi avant de valider.
5. **Un seul mot de passe fort et unique par service** — jamais de réutilisation entre Bitwarden, GitHub, DigitalOcean, Google, etc.

---

# PARTIE 7 — RÉCAPITULATIF VISUEL DU FLUX

```
┌─────────────────────────────────────────────┐
│  1. Tu ouvres un terminal                    │
│     cd Desktop/golivreur                     │
│     claude                                   │
└──────────────────┬────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────┐
│  2. Tu colles le prompt adapté à ta situation │
│     (démarrage / reprise / validation /       │
│      sprint suivant / blocage / bug)          │
└──────────────────┬────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────┐
│  3. Claude Code exécute, met à jour           │
│     PROGRESS.md / DECISIONS.md / ERRORS.md /  │
│     NEXT_TASK.md                              │
└──────────────────┬────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────┐
│  4. Tu testes toi-même (prompt 5.3)           │
└──────────────────┬────────────────────────────┘
                   │
          ┌────────┴────────┐
          ▼                 ▼
   Ça marche          Ça ne marche pas
   (prompt 5.4)          (prompt 5.6)
   → sprint suivant    → correction puis re-test
```

---

# PARTIE 8 — CHECKLIST DE DÉMARRAGE (coche au fur et à mesure)

### Logiciels
- [ ] Docker Desktop installé et lancé
- [ ] Node.js installé
- [ ] Claude Code installé et connecté à ton compte
- [ ] Flutter SDK installé (`flutter doctor` majoritairement vert)
- [ ] Git installé

### Comptes et API (voir Partie 2 pour le détail de chacun)
- [ ] Bitwarden créé — dossier "GoLivreur" prêt
- [ ] Démarche Hub2/CinetPay lancée (formulaire envoyé, documents fournis)
- [ ] Nom de domaine `golivreur.ci` acheté
- [ ] Compte DigitalOcean créé + Token API généré
- [ ] Space DigitalOcean créé + clés générées
- [ ] Compte GitHub + organisation "GoLivreur" créés
- [ ] Compte Google Cloud créé + Maps API activée + clé générée
- [ ] Projet Firebase créé + 3 apps enregistrées
- [ ] Compte Twilio ou Orange API créé + clé générée
- [ ] Compte Google Play Console créé
- [ ] Compte Apple Developer créé
- [ ] Compte Sentry créé
- [ ] Toutes les clés stockées dans Bitwarden — **aucune dans ce chat**

### Fichiers et code
- [ ] Dossier `golivreur/` créé avec la bonne arborescence
- [ ] Les 6 fichiers `.md` de pilotage placés à la racine
- [ ] Le CDC placé dans `CDC/`
- [ ] Les 5 ZIP décompressés dans `docs/`, `backend/`, `mobile/`, `devops/`, `api-tools/`
- [ ] `git init` + premier commit fait

### Lancement
- [ ] Prompt de démarrage (5.1) envoyé à Claude Code

---

*Une fois cette checklist complète, tu es officiellement en Sprint 1. Bonne exécution, Francis — GoLivreur est entre de bonnes mains, les tiennes en supervision et celles de Claude Code en exécution.*

**Daloa bouge, on livre !**

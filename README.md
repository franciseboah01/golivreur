# GoLivreur

**Daloa bouge, on livre !**

Plateforme technologique ivoirienne de livraison (**GoShop**) et d'expédition (**GoColis**), qui connecte clients, commerces et livreurs à Daloa, Côte d'Ivoire, via trois applications mobiles et une interface d'administration centralisée.

> Document confidentiel — Propriété de Francis EBOAH, Fondateur GoLivreur. Ne pas diffuser en dehors de l'équipe projet.

---

## À propos

| | |
|---|---|
| **Fondateur** | Francis EBOAH |
| **Ville de lancement** | Daloa, Côte d'Ivoire (~500 000 habitants) |
| **Services** | GoShop (achat + livraison < 1h) et GoColis (expédition avec double code de vérification) |
| **Statut** | Pré-lancement — développement du MVP en cours |
| **Cahier des charges** | Version 5.0 — voir [`CDC/GoLivreur_CahierDesCharges_v5.md`](./CDC/GoLivreur_CahierDesCharges_v5.md) |

---

## Stack technique

| Composant | Technologie |
|---|---|
| Applications mobiles (3) | Flutter 3.x + Riverpod |
| Backend API | Laravel 11 |
| Base de données | PostgreSQL 16 |
| Cache / Queues | Redis 7.x |
| Temps réel | Laravel Reverb (WebSocket) |
| Interface Admin | Laravel Filament 3 |
| Vitrine web | Blade + Bootstrap 5 |
| Paiement Mobile Money | Hub2 / CinetPay |
| Hébergement | VPS Ubuntu 24.04 |

Détail complet des décisions techniques : [`DECISIONS.md`](./DECISIONS.md)

---

## Structure du dépôt

```
golivreur/
├── CLAUDE.md                    # Règles permanentes pour Claude Code
├── PROGRESS.md                  # Avancement des 10 sprints
├── DECISIONS.md                 # Décisions techniques actées
├── ERRORS.md                    # Erreurs rencontrées et zones à risque
├── NEXT_TASK.md                 # Prochaine tâche à exécuter
├── GUIDE_EXECUTION_GOLIVREUR.md # Guide complet (installation, comptes, prompts)
│
├── CDC/                         # Cahier des charges v5.0 — source de vérité produit
├── docs/                        # Documentation technique (ERD, séquences, ADR)
├── backend/                     # API Laravel + Admin Filament + Vitrine Blade
├── mobile/                      # 3 apps Flutter (Client, Business, Driver) + package core
├── devops/                      # Docker, Makefile, CI/CD, scripts VPS
├── api-tools/                   # Collection Bruno, scripts k6, CHANGELOG_API.md
├── design-stitch/               # Plans directeurs UI, Design System, maquettes Stitch
└── entreprise/                  # Volet juridique/business (hors périmètre technique)
```

---

## Démarrage rapide

```bash
git clone [url-du-repo]
cd golivreur
cp backend/.env.example backend/.env   # renseigner les clés depuis Bitwarden
make install
```

`make install` installe les dépendances, lance les conteneurs Docker, exécute les migrations et peuple la base avec le seeder Daloa.

Guide détaillé, logiciels requis et comptes API à créer : voir [`GUIDE_EXECUTION_GOLIVREUR.md`](./GUIDE_EXECUTION_GOLIVREUR.md)

---

## Pour Claude Code

Toute session de développement doit commencer par la lecture, dans l'ordre, de :
1. [`CLAUDE.md`](./CLAUDE.md) — règles et contraintes du projet
2. [`CDC/GoLivreur_CahierDesCharges_v5.md`](./CDC/GoLivreur_CahierDesCharges_v5.md) — spécifications complètes
3. [`PROGRESS.md`](./PROGRESS.md) et [`NEXT_TASK.md`](./NEXT_TASK.md) — état d'avancement

Ces règles sont détaillées dans `CLAUDE.md`, section 1.

---

## Planning

| Sprint | Semaines | Objectif |
|---|---|---|
| 1 | S1-S2 | Préparation — VPS, domaine, Hub2/CinetPay |
| 2 | S3-S4 | Authentification (3 apps) |
| 3 | S5-S6 | Module Business + contrats API GoColis figés |
| 4 | S7-S8 | Module Client GoShop |
| 5 | S9 | Module Driver GoShop |
| 6 | S10 | Administration de base |
| 7 | S11-S12 | Paiement et notifications |
| 8 | S13-S14 | Tests et QA |
| 9 | S15-S16 | **Lancement officiel GoShop — Daloa** |
| 10 | S17-S22 | **Lancement officiel GoColis — Daloa** |

Suivi en temps réel dans [`PROGRESS.md`](./PROGRESS.md).

---

## Équipe

- **Francis EBOAH** — Fondateur, Super Admin
- **City Manager Daloa**, **City Support Daloa**, **2 City Agents** — équipe opérationnelle de lancement
- **Lead Developer** — développement technique
- **Renfort Flutter** — rejoint à partir du Sprint 3 (S7), dédié à GoColis

---

*GoLivreur — GoShop pour tous vos achats. GoColis pour toutes vos expéditions.*

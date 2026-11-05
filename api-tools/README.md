# GoLivreur — Outils API (Livrable 05/05)

Ce livrable contient tous les outils pour tester, documenter et valider l'API GoLivreur avant le lancement.

---

## Contenu

```
api_tools/
├── bruno/                    ← Collection Bruno complète (tous les endpoints)
│   ├── environments/         ← local / staging / production
│   ├── auth/                 ← Inscription, connexion, refresh
│   ├── client/                ← GoShop + GoColis côté client
│   ├── merchant/              ← Réception et gestion commandes
│   ├── driver/                ← Courses GoShop + double code GoColis
│   ├── admin/                  ← Dashboard, remboursements, modes
│   └── webhooks/              ← Tests HMAC valide/invalide
├── k6/                        ← Tests de charge
│   ├── scenarios/             ← 3 scénarios (commandes, GPS, paiements)
│   ├── lib/                   ← Helpers communs
│   └── RAPPORT_TEMPLATE.md    ← Template de rapport à remplir
├── changelog/
│   └── CHANGELOG_API.md       ← Historique des versions de l'API
└── guides/
    └── GUIDE_BRUNO_K6.md      ← Démarrage en 10 minutes
```

---

## Démarrage rapide

**Pour tester l'API manuellement** → Commencez par `guides/GUIDE_BRUNO_K6.md`, section Bruno.

**Pour valider la charge avant le lancement** → `guides/GUIDE_BRUNO_K6.md`, section k6.

**Pour documenter un changement d'API** → Ajoutez une entrée dans `changelog/CHANGELOG_API.md` avant chaque merge sur `develop`.

---

## Synchronisation avec le mock Flutter

Les codes de test GoColis (`7291` pour expéditeur, `3847` pour destinataire)
sont identiques dans :
- `bruno/environments/local.bru` (variables `sender_code_test` / `recipient_code_test`)
- `flutter/apps/golivreur_client/lib/mocks/mock_gocolis_api.dart`

Si vous modifiez l'un, modifiez l'autre pour garder la cohérence entre
les tests Bruno et le développement Flutter en mode mock.

---

## Documentation OpenAPI (Scramble)

En complément de cette collection Bruno, l'API expose une documentation
OpenAPI 3.0 auto-générée, accessible directement depuis le backend :

```
http://localhost:8000/docs       (local)
https://staging.golivreur.ci/docs (staging)
```

Cette documentation est toujours à jour avec le code (générée par Scramble),
contrairement à un document statique qui devient obsolète.

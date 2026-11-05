# GoLivreur — Rapport de Tests de Charge k6

**Date du test** : _____________  
**Exécuté par** : _____________  
**Environnement testé** : ☐ Local · ☐ Staging · ☐ Production (déconseillé)

---

## Comment utiliser ce template

Après chaque exécution de scénario k6, copiez les résultats affichés dans
le terminal dans les sections correspondantes ci-dessous. Ce document
devient votre preuve que le serveur tient la charge avant le lancement.

---

## Résumé exécutif (à remplir en dernier)

| Scénario | Statut | Action requise |
|---|---|---|
| 1. Commandes simultanées | ☐ ✅ Réussi · ☐ ❌ Échoué | |
| 2. GPS simultanés | ☐ ✅ Réussi · ☐ ❌ Échoué | |
| 3. Paiements simultanés | ☐ ✅ Réussi · ☐ ❌ Échoué | |

**Décision de lancement** : ☐ GO · ☐ NO-GO (optimisations nécessaires)

---

## Scénario 1 — 100 Commandes GoShop Simultanées

**Commande exécutée :**
```bash
k6 run scenarios/commandes_simultanees.js
```

**Résultats copiés depuis le terminal :**

```
Commandes réussies   : ___/100
Taux de réussite     : ___%
Temps réponse (p95)  : ___ms
Taux d'échec HTTP    : ___%
```

**Seuils de réussite (déjà configurés dans le script) :**
- [ ] 95% des commandes créées avec succès
- [ ] Temps de réponse p95 < 2000ms (2 secondes)
- [ ] Moins de 5% d'échecs HTTP

**CPU du serveur pendant le test** (vérifié manuellement) :
- Pic CPU observé : _____%
- [ ] CPU resté sous 70%

**Notes / problèmes observés :**
```
_______________________________________________
_______________________________________________
```

---

## Scénario 2 — 50 Livreurs GPS Simultanés

**Commande exécutée :**
```bash
k6 run scenarios/gps_simultanes.js
```

**Résultats copiés depuis le terminal :**

```
Positions envoyées   : ___
Positions échouées   : ___
Taux de réussite     : ___%
Temps réponse (p95)  : ___ms
CPU max observé      : ___%
```

**Seuils de réussite :**
- [ ] 95% des positions GPS envoyées avec succès
- [ ] Temps de réponse p95 < 1000ms (1 seconde)
- [ ] CPU resté sous 70%

**Vérification Redis (manuelle) :**
```bash
docker compose exec golivreur_redis redis-cli INFO stats
```
- Connected clients : _____
- Instantaneous ops per sec : _____

**Notes / problèmes observés :**
```
_______________________________________________
_______________________________________________
```

---

## Scénario 3 — 20 Paiements Orange Money Simultanés

**Commande exécutée :**
```bash
k6 run -e WEBHOOK_SECRET=[votre_secret] scenarios/paiements_simultanes.js
```

**Résultats copiés depuis le terminal :**

```
PAIEMENTS VALIDES
  Réussis              : ___/20
  Échoués              : ___
  Temps réponse (p95)  : ___ms

SÉCURITÉ
  Correctement rejetés : ___/5
  Acceptés par erreur  : ___  ⚠️ DOIT ÊTRE 0
```

**Seuils de réussite (CRITIQUES) :**
- [ ] 19/20 minimum paiements valides traités avec succès
- [ ] **0 paiement avec signature invalide accepté** (sécurité absolue)
- [ ] Temps de réponse p95 < 3000ms

> ⚠️ Si "Acceptés par erreur" est différent de 0, **NE PAS LANCER EN PRODUCTION**.
> Cela signifie qu'un attaquant pourrait créditer de faux paiements.
> Contactez immédiatement le Lead Dev.

**Notes / problèmes observés :**
```
_______________________________________________
_______________________________________________
```

---

## Actions correctives (si NO-GO)

| Problème identifié | Action | Responsable | Date limite |
|---|---|---|---|
| | | | |
| | | | |

---

## Validation finale

**Signé** : _____________  
**Date** : _____________

☐ Les 3 scénarios sont GO — le serveur est prêt pour le lancement à Daloa.  
☐ Des corrections sont nécessaires avant le lancement (voir actions correctives ci-dessus).

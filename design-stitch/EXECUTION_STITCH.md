# EXECUTION_STITCH.md
## Guide d'exécution — Génération des écrans GoLivreur avec Google Stitch

> À suivre à la lettre, Francis. Ce guide t'explique comment utiliser les 6 fichiers du dossier `design-stitch/` pour générer tous les écrans des 3 apps, lot par lot, sans rien oublier.

---

## Avant de commencer

**Ce dont tu as besoin :**
- Un compte Google (pour accéder à Stitch)
- Les 6 fichiers du dossier `golivreur/design-stitch/`
- Environ 2-3h de disponibilité — ce n'est pas à faire en 10 minutes, chaque lot mérite une vérification

**Accès à Stitch :** va sur **https://stitch.withgoogle.com** et connecte-toi avec ton compte Google.

---

## Le principe général

Tu ne colles jamais un fichier entier d'un coup. Stitch fonctionne **par lot de 5 à 8 écrans**. Pour chaque lot, tu donnes à Stitch :

1. Le **Design System** (Fichier 3) — toujours en premier, une fois par session si possible
2. Les **données réalistes** utiles à ce lot (extrait du Fichier 4)
3. La **description des écrans du lot** (extrait du Fichier 1a/1b/1c)

Stitch génère alors les écrans du lot avec un style cohérent et du vrai contenu, pas du Lorem Ipsum.

---

## Étape 0 — Initialiser le projet dans Stitch

1. Crée un nouveau projet dans Stitch, nomme-le **"GoLivreur"**
2. Colle en premier message le contenu complet du **Fichier 3 (DESIGN.md)**
3. Ajoute cette phrase avant de coller :
   ```
   Voici le Design System complet à utiliser pour tous les écrans que je 
   vais te demander dans ce projet. Applique ces tokens (couleurs, 
   typographie, espacements, composants) sur chaque écran généré, sans 
   exception, même dans les prochains messages de cette conversation.
   ```
4. Vérifie que Stitch confirme avoir bien pris en compte la palette (Orange `#F4A623`, Vert `#1B5E20`) avant de continuer

---

## Étape 1 — Générer les écrans, lot par lot

Traite les lots **dans cet ordre** (du plus structurant au plus périphérique) :

### App Client (6 lots — Fichier 1a)
1. Lot Auth
2. Lot GoShop-Découverte
3. Lot GoShop-Commande
4. Lot GoShop-Suivi + GoColis-Début
5. Lot GoColis-Suite
6. Lot Profil

### App Business (2 lots — Fichier 1b)
7. Lot Auth + Catalogue
8. Lot Commandes + Revenus + Paramètres

### App Driver (3 lots — Fichier 1c)
9. Lot Auth + Gains
10. Lot Portefeuille + Courses GoShop
11. Lot Courses GoColis

**Pour chaque lot, le message à envoyer à Stitch suit toujours cette structure :**

```
Génère les écrans suivants pour l'application [Client / Business / Driver] 
de GoLivreur, en respectant strictement le Design System déjà fourni.

[Colle ici le tableau du lot concerné, extrait du Fichier 1a/1b/1c — 
uniquement les lignes des écrans de CE lot, pas tout le fichier]

Utilise ces données réalistes pour remplir les écrans (pas de Lorem Ipsum) :
[Colle ici l'extrait pertinent du Fichier 4 — ex : les commerces et 
produits pour un lot GoShop, les livreurs pour un lot Driver]

Consulte aussi le Fichier 2 (Interconnexions) si l'écran a un lien avec 
une autre application ou un statut partagé.
```

---

## Étape 2 — Vérifier chaque lot avant de passer au suivant

Après chaque génération, vérifie ces 4 points avant de continuer :

- [ ] Les couleurs correspondent bien à la palette (orange `#F4A623` / vert `#1B5E20`) — pas une couleur inventée par Stitch
- [ ] Les boutons d'action font au moins 52px de hauteur (lisibilité tactile)
- [ ] Le texte utilise du vrai contenu (noms de commerces, produits, prix en F CFA) — pas de Lorem Ipsum
- [ ] L'emplacement du logo est bien marqué `[LOGO GOLIVREUR — À INTÉGRER]`, pas un logo inventé par Stitch

**Si un point ne va pas :** redemande à Stitch de corriger précisément ce point, ne passe pas au lot suivant avec un problème non résolu — les erreurs de design system se répètent sur tous les lots suivants si tu ne corriges pas tout de suite.

---

## Étape 3 — Exporter et ranger les écrans générés

Pour chaque lot validé, exporte le code (Stitch propose un export HTML/Tailwind) et range-le ainsi :

```
golivreur/
└── design-stitch/
    ├── 1a_PlanDirecteur_Client.md
    ├── 1b_PlanDirecteur_Business.md
    ├── 1c_PlanDirecteur_Driver.md
    ├── 2_Interconnexions.md
    ├── 3_DESIGN.md
    ├── 4_HubDonnees.json
    └── exports/
        ├── client/
        │   ├── auth/
        │   ├── goshop/
        │   ├── gocolis/
        │   └── profil/
        ├── business/
        │   ├── auth-catalogue/
        │   └── commandes-revenus/
        └── driver/
            ├── auth-gains/
            ├── portefeuille-goshop/
            └── gocolis/
```

Un sous-dossier par lot, nommé comme le lot (ex : `goshop-decouverte/`).

---

## Étape 4 — Une fois TOUS les lots générés (les 11 lots)

Ne transmets rien à Claude Code avant d'avoir fini les 11 lots — un transfert partiel l'oblige à deviner la suite, ce qui casse la cohérence.

Quand tout est prêt :

1. Vérifie une dernière fois la cohérence visuelle en ouvrant 3-4 écrans de chaque app côte à côte
2. Copie le prompt de blocage habituel (voir `GUIDE_EXECUTION_GOLIVREUR.md`, section 5.5) et adapte-le :

```
Voici les maquettes générées par Stitch pour les 3 applications, 
disponibles dans golivreur/design-stitch/exports/.

Utilise ces maquettes comme référence exacte pour l'implémentation 
Flutter des écrans — respecte les couleurs, espacements et composants 
tels qu'ils apparaissent, sans réinterpréter le design.

Continue l'exécution du projet à partir de là où tu t'étais arrêté.
```

3. Envoie ce message à Claude Code dans ta session habituelle

---

## Erreurs fréquentes à éviter

| Erreur | Pourquoi ça pose problème | Ce qu'il faut faire à la place |
|---|---|---|
| Coller les 6 fichiers d'un coup dans un seul message | Stitch perd en cohérence sur un trop gros volume | Toujours lot par lot |
| Changer l'ordre des lots | Certains écrans (ex: Auth) servent de référence visuelle aux suivants | Respecter l'ordre de l'Étape 1 |
| Valider un lot avec une couleur ou une taille de bouton fausse | L'erreur se propage sur tous les lots suivants | Corriger immédiatement avant de continuer |
| Envoyer les exports à Claude Code au fur et à mesure | Il devra reprendre le travail plusieurs fois | Attendre que les 11 lots soient terminés |
| Laisser Stitch générer un vrai logo | Incohérent avec le logo final du designer | Toujours garder le placeholder `[LOGO GOLIVREUR — À INTÉGRER]` |

---

## Checklist finale

- [ ] Design System initialisé dans Stitch (Étape 0)
- [ ] Lot 1 à 6 — App Client générés et vérifiés
- [ ] Lot 7 à 8 — App Business générés et vérifiés
- [ ] Lot 9 à 11 — App Driver générés et vérifiés
- [ ] Cohérence visuelle vérifiée sur les 3 apps ensemble
- [ ] Exports rangés dans `design-stitch/exports/`
- [ ] Message envoyé à Claude Code pour intégration Flutter

---

*Une fois cette checklist complète, les maquettes de GoLivreur sont prêtes à devenir du vrai code. Retour à `GUIDE_EXECUTION_GOLIVREUR.md` pour la suite du pilotage avec Claude Code.*

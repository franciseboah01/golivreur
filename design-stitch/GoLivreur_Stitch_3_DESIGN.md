# FICHIER 3 — DESIGN.md — DESIGN SYSTEM UNIFIÉ GOLIVREUR

> Design system unique pour les 3 applications (Client, Business, Driver). Priorité absolue : lisibilité sur smartphones Android d'entrée de gamme (Tecno, Infinix) utilisés par la majorité des utilisateurs à Daloa.

---

## 1. Logo & Identité

- **Logo GoLivreur : `[LOGO GOLIVREUR — À INTÉGRER]`** — fichier `.svg` fourni séparément par le designer graphique, à placer aux emplacements réservés ci-dessous. Ne pas générer de logo automatiquement.
- Emplacement Splash Screen : centré, 120×120px minimum, fond `#212121` (Dark) ou `#FAFAFA` (Off-white) selon le thème
- Emplacement Header : icône seule, 32×32px, aligné à gauche
- Espace de respiration minimum autour du logo : équivalent à 50% de sa propre hauteur

---

## 2. Couleurs

### Palette de marque (commune aux 3 apps)

| Token | Nom | Hex | Usage |
|---|---|---|---|
| `color-primary` | Orange GoLivreur | `#F4A623` | Boutons d'action, éléments actifs, CTA principaux |
| `color-secondary` | Vert Confiance | `#1B5E20` | Confirmation, succès, éléments secondaires |
| `color-background` | Blanc cassé | `#FAFAFA` | Fond par défaut |
| `color-surface` | Blanc pur | `#FFFFFF` | Cartes, modales, surfaces élevées |
| `color-text-primary` | Gris très foncé | `#212121` | Texte principal |
| `color-text-secondary` | Gris moyen | `#595959` | Texte secondaire, légendes |
| `color-text-disabled` | Gris clair | `#9E9E9E` | Texte désactivé |
| `color-border` | Gris bordure | `#E0E0E0` | Bordures, séparateurs |

### Couleurs sémantiques

| Token | Hex | Usage |
|---|---|---|
| `color-success` | `#4CAF50` | Confirmation, livraison réussie |
| `color-error` | `#D32F2F` | Erreurs, refus, code invalide |
| `color-warning` | `#F9A825` | Alertes, timeout imminent (compte à rebours) |
| `color-info` | `#2E75B6` | Informations neutres |

### Neutres (échelle de gris)

| Token | Hex |
|---|---|
| `color-gray-50` | `#FAFAFA` |
| `color-gray-100` | `#F2F2F2` |
| `color-gray-300` | `#E0E0E0` |
| `color-gray-500` | `#9E9E9E` |
| `color-gray-700` | `#595959` |
| `color-gray-900` | `#212121` |

### Accents d'identification par application

Les 3 apps partagent la même palette de marque. Un accent discret aide à distinguer visuellement laquelle est ouverte (utile pour un utilisateur qui a les 3 apps installées) :

| App | Accent d'identification | Usage |
|---|---|---|
| GoLivreur Client | `#F4A623` (Orange, couleur primaire pleine) | Tab bar active, header |
| GoLivreur Business | `#2E75B6` (Bleu Info) en complément de l'orange | Icône d'app, bandeau de statut Ouvert/Fermé |
| GoLivreur Driver | `#1B5E20` (Vert secondaire, dominant) | Tab bar active, bouton En ligne/Hors ligne |

---

## 3. Typographie

**Police unique : Inter** (toutes graisses : Regular 400, Medium 500, SemiBold 600, Bold 700)

| Token | Taille | Graisse | Hauteur de ligne | Usage |
|---|---|---|---|---|
| `text-h1` | 28px | Bold (700) | 34px | Titre d'écran principal |
| `text-h2` | 22px | Bold (700) | 28px | Titre de section |
| `text-h3` | 18px | SemiBold (600) | 24px | Sous-titre, titre de carte |
| `text-body` | 16px | Regular (400) | 22px | Texte courant |
| `text-body-medium` | 16px | Medium (500) | 22px | Texte mis en avant (labels de boutons) |
| `text-caption` | 13px | Regular (400) | 18px | Légendes, métadonnées, timestamps |
| `text-button` | 16px | SemiBold (600) | 20px | Texte de bouton |

**Règle stricte :** aucune taille de texte en dessous de 13px, pour rester lisible sur écrans d'entrée de gamme.

---

## 4. Espacements — échelle

| Token | Valeur | Usage |
|---|---|---|
| `space-xs` | 4px | Entre icône et texte adjacent |
| `space-sm` | 8px | Entre éléments d'un même groupe |
| `space-md` | 16px | Padding interne standard des cartes, marge entre sections |
| `space-lg` | 24px | Marge entre blocs de contenu |
| `space-xl` | 32px | Marge de section importante |
| `space-2xl` | 48px | Séparation entre grandes zones d'écran |

**Marge d'écran standard :** `space-md` (16px) à gauche et à droite sur tous les écrans, sur les 3 apps.

---

## 5. Rayons de bordure (radius)

| Token | Valeur | Usage |
|---|---|---|
| `radius-sm` | 6px | Badges, petits éléments |
| `radius-md` | 12px | Boutons, champs de saisie |
| `radius-lg` | 16px | Cartes |
| `radius-full` | 999px | Avatars, pastilles rondes |

---

## 6. Composants partagés

### Boutons

| Variant | id/class | Fond | Texte | Hauteur | Radius |
|---|---|---|---|---|---|
| Primaire | `.btn-primary` | `color-primary` plein | Blanc | 52px | `radius-md` |
| Secondaire | `.btn-secondary` | Transparent, bordure `color-primary` 1.5px | `color-primary` | 52px | `radius-md` |
| Succès | `.btn-success` | `color-success` plein | Blanc | 52px | `radius-md` |
| Danger | `.btn-danger` | `color-error` plein | Blanc | 52px | `radius-md` |
| Désactivé | `.btn-disabled` | `color-gray-300` | `color-text-disabled` | 52px | `radius-md` |
| Texte seul | `.btn-text` | Transparent | `color-primary` | 44px | — |

**Règle :** hauteur minimum 52px sur tous les boutons d'action principale — zone tactile large pour usage au pouce.

### Champs de saisie (Inputs)

| Variant | id/class | Détail |
|---|---|---|
| Défaut | `.input-default` | Hauteur 48px, bordure `color-border` 1px, radius `radius-md`, padding horizontal 16px |
| Focus | `.input-focus` | Bordure `color-primary` 2px |
| Erreur | `.input-error` | Bordure `color-error` 2px, message d'erreur en dessous en `text-caption` rouge |
| Code OTP | `.input-otp` | 4 cases séparées, 56×56px chacune, centré, `text-h2` |

### Cartes (Cards)

| Variant | id/class | Détail |
|---|---|---|
| Carte standard | `.card-default` | Fond `color-surface`, radius `radius-lg`, ombre légère (0px 2px 8px rgba(0,0,0,0.06)), padding `space-md` |
| Carte commerce/produit | `.card-item` | Image en haut (ratio 16:9), contenu en dessous |
| Carte statistique | `.card-stat` | Centré, valeur en `text-h2` couleur `color-primary`, libellé en `text-caption` |

### Headers

| Variant | id/class | Détail |
|---|---|---|
| Header standard | `.header-default` | Hauteur 56px, fond `color-surface`, titre centré `text-h3`, icône retour à gauche |
| Header avec logo | `.header-brand` | Logo à gauche (voir section 1), icône notification à droite avec badge |

### Tab Bar (navigation inférieure)

| Variant | id/class | Détail |
|---|---|---|
| Tab bar Client | `.tabbar-client` | 4 onglets : Accueil, GoShop, GoColis, Profil — icône active en `color-primary` |
| Tab bar Business | `.tabbar-business` | 3 onglets : Commandes, Catalogue, Revenus — icône active en accent Business |
| Tab bar Driver | `.tabbar-driver` | 3 onglets : Courses, Portefeuille, Gains — icône active en accent Driver |

### Modals

| Variant | id/class | Détail |
|---|---|---|
| Modal confirmation | `.modal-confirm` | Fond assombri 60%, carte centrée `radius-lg`, 2 boutons (Annuler / Confirmer) |
| Modal plein écran | `.modal-fullscreen` | Utilisé pour AlerteNouvelleCommande (Business) et AlerteCourse (Driver) — non-fermable sans action |

### Toasts / Notifications inline

| Variant | id/class | Détail |
|---|---|---|
| Toast succès | `.toast-success` | Fond `color-success` léger (10% opacité), texte `color-success`, icône check |
| Toast erreur | `.toast-error` | Fond `color-error` léger, texte `color-error`, icône alerte |
| Bannière connexion lente | `.banner-slow-connection` | Fond `color-warning` léger, persistante en haut d'écran après 8s de timeout, option "Annuler" |

### List Items

| Variant | id/class | Détail |
|---|---|---|
| Item liste standard | `.list-item-default` | Hauteur 64px min, icône/avatar à gauche, texte principal + secondaire, chevron à droite |
| Item commande/course | `.list-item-order` | Badge de statut coloré à droite (voir codes couleur statuts ci-dessous) |

### Avatars

| Variant | id/class | Détail |
|---|---|---|
| Avatar utilisateur | `.avatar-user` | Cercle `radius-full`, 40×40px par défaut, 64×64px en grand (profil) |
| Avatar avec statut | `.avatar-status` | Pastille de statut en bas à droite (vert = en ligne, gris = hors ligne) |

### Badges

| Variant | id/class | Couleur |
|---|---|---|
| Badge statut "En attente" | `.badge-pending` | Fond `color-gray-300`, texte `color-text-secondary` |
| Badge statut "En cours" | `.badge-active` | Fond `color-info` léger, texte `color-info` |
| Badge statut "Livré/Succès" | `.badge-success` | Fond `color-success` léger, texte `color-success` |
| Badge statut "Refusé/Annulé" | `.badge-error` | Fond `color-error` léger, texte `color-error` |
| Badge notification (compteur) | `.badge-count` | Fond `color-error` plein, texte blanc, cercle, 18×18px min |

### États d'écran (4 états atomiques obligatoires)

Chaque écran de liste/données doit prévoir ces 4 variantes de contenu :

| État | id/class | Contenu |
|---|---|---|
| Chargement | `.state-loading` | Skeleton shimmer gris clair, formes des cartes à venir |
| Erreur | `.state-error` | Icône alerte, message, `.btn-secondary` "Réessayer" (data-action="retry") |
| Vide | `.state-empty` | Illustration légère, message, bouton d'action contextuel |
| Contenu | `.state-content` | Le contenu réel |

---

## 7. Thème Admin (Laravel Filament) — note courte

L'interface d'administration web n'est pas générée dans Stitch (elle utilise les composants natifs de Filament). Appliquer uniquement :
- Couleur primaire du thème Filament : `#F4A623`
- Couleur secondaire : `#1B5E20`
- Logo GoLivreur dans le header admin (même fichier que section 1)
- Police : Inter (si personnalisable dans Filament, sinon police système par défaut)

---

## 8. Icônographie

- Style : outline (contour), épaisseur de trait 1.5-2px, jamais de style "filled" pour rester cohérent
- Taille standard : 24×24px en contexte de liste, 32×32px en header
- Bibliothèque de référence suggérée : Phosphor Icons ou Lucide (style outline disponible dans les deux)

---

## 9. Grille et disposition

- Marge d'écran : 16px de chaque côté (voir `space-md`)
- Colonnes en grille (catégories, produits en grille) : 2 colonnes sur mobile standard, gap `space-sm` (8px)
- Ratio image produit/commerce : 16:9 pour les couvertures, 1:1 pour les vignettes produit

# GoLivreur — Guide de Contribution

**Temps de lecture estimé : 25 minutes**  
Après lecture, le renfort Flutter GoColis est opérationnel.

---

## 1. Règle d'or

> Un sprint qui casse quelque chose de précédent n'est pas terminé, peu importe que les nouveaux tests passent.  
> Vérifiez toujours qu'aucune régression n'est introduite avant de créer une Pull Request.

---

## 2. Branches Git

| Branche | Rôle | Protection |
|---|---|---|
| `main` | Production — toujours stable | Merge uniquement via PR + approbation Fondateur |
| `develop` | Intégration — toujours déployable en staging | Merge uniquement via PR + pipeline vert |
| `feature/nom` | Fonctionnalité en cours | — |

### Créer une branche de fonctionnalité

```bash
git checkout develop
git pull origin develop
git checkout -b feature/gocolis-creation-expedition
```

### Nommer une branche

```
feature/goshop-validation-code-confirmation
feature/gocolis-photos-preuve
feature/admin-mode-pluie-automatique
fix/driver-gps-filtre-10m
docs/diagramme-sequence-gocolis
```

---

## 3. Messages de commit

**Format :**
```
type(scope): description courte en français (max 72 caractères)

Corps optionnel si nécessaire (explication du pourquoi, pas du quoi)
```

**Types autorisés :**

| Type | Usage |
|---|---|
| `feat` | Nouvelle fonctionnalité |
| `fix` | Correction de bug |
| `refactor` | Refactoring sans changement de comportement |
| `test` | Ajout ou modification de tests |
| `docs` | Documentation uniquement |
| `chore` | Tâche de maintenance (dépendances, config) |

**Scopes autorisés :** `auth`, `goshop`, `gocolis`, `wallet`, `admin`, `driver`, `client`, `merchant`, `notifications`, `paiement`

**Exemples corrects :**
```
feat(gocolis): ajoute validation code destinataire avec blocage 3 erreurs
fix(goshop): corrige calcul commission sur commandes avec frais pluie
test(auth): ajoute tests permissions City Finance sur remboursements
docs(gocolis): met à jour diagramme séquence double code
```

---

## 4. Checklist avant de créer une Pull Request

```
[ ] Les tests Pest passent localement
    docker compose exec golivreur_app php artisan test

[ ] Larastan niveau 5 sans erreur
    docker compose exec golivreur_app ./vendor/bin/phpstan analyse --level=5

[ ] flutter analyze sans erreur (apps concernées)
    flutter analyze

[ ] Aucun TODO, placeholder ou méthode vide dans le code

[ ] Les migrations sont réversibles (vérifiée en dev, pas en prod)

[ ] Le CHANGELOG_API.md est mis à jour si un endpoint a changé

[ ] La branche est à jour avec develop
    git fetch origin && git rebase origin/develop
```

---

## 5. Conventions de code Laravel

### Nommage

| Élément | Convention | Exemple |
|---|---|---|
| Contrôleurs | PascalCase + suffixe Controller | `OrderController` |
| Services | PascalCase + suffixe Service | `OrderService` |
| Modèles | PascalCase singulier | `Order`, `Parcel` |
| Migrations | snake_case avec date | `create_orders_table` |
| Routes API | kebab-case | `/api/v1/courier/location` |
| Variables | camelCase | `$orderService` |
| Constantes | UPPER_SNAKE_CASE | `MAX_CODE_ATTEMPTS` |

### Structure d'un contrôleur (contrôleur fin)

```php
// Les contrôleurs ne contiennent PAS de logique métier
// Ils font uniquement : valider → appeler Service → retourner réponse

public function store(StoreOrderRequest $request): JsonResponse
{
    $order = $this->orderService->creerCommande(
        client: auth()->user(),
        donnees: $request->validated(),
    );

    return response()->json([
        'succes' => true,
        'message' => 'Commande créée avec succès.',
        'donnees' => new OrderResource($order),
    ], 201);
}
```

### Format de réponse API (uniforme sur tous les endpoints)

```json
// Succès
{
    "succes": true,
    "message": "Description courte en français",
    "donnees": { ... }
}

// Erreur validation
{
    "succes": false,
    "message": "Les données fournies sont invalides.",
    "erreurs": {
        "phone": ["Le numéro de téléphone est obligatoire."]
    }
}

// Erreur serveur
{
    "succes": false,
    "message": "Une erreur est survenue. Veuillez réessayer.",
    "code": "INTERNAL_ERROR"
}
```

---

## 6. Conventions de code Flutter

### Structure d'un écran (4 états obligatoires)

```dart
// Chaque écran de liste DOIT implémenter ces 4 états
body: donneesAsync.when(
    loading: () => const EtatChargement(nombreLignes: 5),
    error: (err, _) => EtatErreur(
        message: 'Message explicite pour l\'utilisateur',
        onReessayer: () => ref.invalidate(monProvider),
    ),
    data: (donnees) => donnees.isEmpty
        ? const EtatVide(
            icone: Icons.inbox_outlined,
            message: 'Message explicite',
            labelAction: 'Action possible',
            routeAction: '/chemin/vers/creation',
        )
        : ListView.builder(...),
),
```

### Règle timeout 8 secondes

Toute requête API doit afficher un message si elle dépasse 8 secondes :

```dart
// Dans api_client.dart — configuré globalement
connectTimeout: const Duration(seconds: 8),
receiveTimeout: const Duration(seconds: 8),
// L'intercepteur gère automatiquement l'affichage du message
```

### Nommage Flutter

| Élément | Convention | Exemple |
|---|---|---|
| Widgets | PascalCase | `CarteColis`, `BoutonPrimaire` |
| Providers | camelCase + suffixe Provider | `commandesListeProvider` |
| Screens | PascalCase + suffixe Screen | `CommandeDetailScreen` |
| Fichiers | snake_case | `commande_detail_screen.dart` |

---

## 7. Onboarding renfort Flutter GoColis (S7)

### Jour 1 — Configuration (demi-journée)

```bash
# 1. Cloner le repo
git clone [URL_REPO] golivreur
cd golivreur

# 2. Démarrer Docker
docker compose up -d

# 3. Installer les dépendances Flutter (pour chaque app)
cd flutter/golivreur_client && flutter pub get
cd ../golivreur_business && flutter pub get
cd ../golivreur_driver && flutter pub get

# 4. Lancer l'app Client sur émulateur pour vérifier
cd flutter/golivreur_client
flutter run

# 5. Accéder au mock API GoColis
# URL : http://localhost:8000/api/v1/mock/gocolis/
# Documentation : http://localhost:8000/docs (Scramble)
```

### Références prioritaires

1. `documentation/01_projet/03_guide_utilisation_roles.md` — Comprendre GoColis
2. `documentation/02_architecture/02b_diagramme_sequence_metier.md` — Séquence GoColis
3. `documentation/02_architecture/03_diagramme_base_de_donnees.md` — Tables parcels
4. `documentation/05_fonctionnel/01_api_rest_reference.md` — Endpoints GoColis

### Module à développer

Le renfort Flutter travaille uniquement sur :
- `flutter/golivreur_client/lib/features/gocolis/` — App Client (expéditeur)
- `flutter/golivreur_driver/lib/features/gocolis/` — App Driver (livreur GoColis)

Il ne touche PAS aux modules GoShop ni à l'authentification.

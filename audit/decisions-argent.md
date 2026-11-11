# Audit Décisions Financières — Espèces & Remboursements

> **Statut :** Constat technique sur code réel (Bloc C — aucune modification applicative effectuée).  
> **Référentiel comparé :** `CDC/GoLivreur_CahierDesCharges_v5.md` vs code backend Laravel.

---

## (1) PAIEMENT EN ESPÈCES À LA LIVRAISON

### Faits constatés dans le code

#### a. Une commande/colis peut-elle être payé en espèces ? Où est le champ et quelles valeurs ?
- **Oui.** Les deux services (GoShop et GoColis) acceptent le paiement en espèces dès la création.
- **Colonnes en base :**
  - Table `orders` (`backend/database/migrations/2026_06_01_000007_create_orders_table.php:48-50`) :
    - `payment_method` (`string(30)`, défaut `'cash'`, valeurs attendues `'orange_money' | 'cash'`, étendues par l'API).
    - `payment_status` (`string(30)`, défaut `'pending'`, valeurs `'pending' | 'paid' | 'failed' | 'pending_cash'`).
  - Table `parcels` (`backend/database/migrations/2026_06_01_000009_create_parcels_table.php:75-77`) :
    - `payment_method` (`string(30)`, défaut `'cash'`) et `payment_status` (`string(30)`, défaut `'pending'`).
- **Validation API à la création :**
  - `ClientController::createOrder` (`backend/app/Http/Controllers/Api/V1/Client/ClientController.php:88`) : `payment_method` requis dans `cash,orange_money,wave,mtn,moov`.
  - `ParcelController::store` (`backend/app/Http/Controllers/Api/V1/GoColis/ParcelController.php:28`) : `payment_method` requis dans `cash,orange_money,wave,mtn,moov`.
- **Statut initial à la création :**
  - `OrderService::creerCommande` (`backend/app/Modules/GoShop/Services/OrderService.php:66-68`) affecte `'payment_status' => $donnees['payment_method'] === 'cash' ? 'pending_cash' : 'pending'`.
  - `ParcelService::creerExpedition` (`backend/app/Modules/GoColis/Services/ParcelService.php:87-89`) applique la même règle (`'pending_cash'` si `'cash'`, sinon `'pending'`).
- **Paramétrage Admin & Tableau de bord :**
  - `SettingsPage` (`backend/app/Filament/Pages/SettingsPage.php:321-328`) et `SettingsService::DEFAULTS` (`backend/app/Services/SettingsService.php:78-80`) listent `'orange_money'`, `'cash'`, `'wave'`, `'mtn_momo'`.
  - Le widget `PaymentMethodsChart` (`backend/app/Filament/Widgets/PaymentMethodsChart.php:21-28,34-37`) compte les `Order` et `Parcel` par `payment_method` (`cash`, `wallet`, `wave`, `orange`, `mtn`, `moov` — à noter que `'orange'` ne correspond pas à `'orange_money'` enregistré en base).

#### b. Quand le livreur encaisse en espèces, qu'est-ce qui est crédité sur son wallet, et à quel moment ?
- **Moment du crédit :** lors de la validation du code de remise au client/destinataire :
  - **GoShop :** `OrderService::validerCodeConfirmation` (`backend/app/Modules/GoShop/Services/OrderService.php:209-216`) passe la commande à `delivered`, puis appelle sans vérifier `payment_method` ni `payment_status` :
    - `WalletService::crediterLivreur($lockedOrder)` (`backend/app/Services/WalletService.php:21-32`) → crédite **`+courier_amount`** (80 % de `delivery_fee`, type `'credit_delivery'`) ;
    - `WalletService::crediterMerchant($lockedOrder)` (`backend/app/Services/WalletService.php:38-49`) → crédite **`+merchant_amount`** (`products_total` − 15 %, type `'credit_delivery'`).
  - **GoColis :** `ParcelService::validerCodeDestinataire` (`backend/app/Modules/GoColis/Services/ParcelService.php:228-236`) crédite également sans contrôle du mode de paiement :
    - `WalletService::crediterLivreurGoColis($lockedParcel)` (`backend/app/Services/WalletService.php:55-66`) → crédite **`+courier_amount`** (75 % de `shipping_fee`, type `'credit_shipping'`).
- **`payment_status` :** reste bloqué à `'pending_cash'` après la livraison ; aucune transition vers `'paid'` n'existe dans `OrderService` ou `ParcelService`.

#### c. Qui doit à qui ? Exemple chiffré : commande 4 000 + livraison 800 payée en espèces au livreur
- **Répartition théorique (CDC chap. 8.1) :**
  - Le client remet **4 800 F CFA** en liquide au livreur.
  - Commerce (85 % des produits) : **3 400 F CFA**.
  - Livreur (80 % de la livraison) : **640 F CFA**.
  - Plateforme (15 % produits = 600 F + 20 % livraison = 160 F) : **760 F CFA**.
  - Le livreur devrait donc reverser **4 160 F CFA** (= 4 800 F encaissés − 640 F de gain), dont **3 400 F** reviennent au commerce et **760 F** à la plateforme (ou 760 F + 640 F selon le modèle de règlement commerce au comptoir).
- **Résultat réel produit par le code actuel (`OrderService.php:215-216`, `WalletService.php:21-49,135-151`) :**
  - **En poche du livreur :** **+4 800 F CFA** en liquide (non tracés comme dette).
  - **Wallet livreur :** **`+640 F CFA`** crédités (`balance` augmente de `640`, `total_earned` augmente de `640`). Total apparent détenu par le livreur = **5 440 F CFA**.
  - **Wallet commerce :** **`+3 400 F CFA`** crédités alors que la plateforme n'a rien encaissé.
  - **Solde net plateforme :** la plateforme reconnaît **4 040 F CFA** de dettes retirables envers ses partenaires (3 400 F + 640 F) pour **0 F CFA** encaissé, et **0 F CFA** de créance enregistrée sur le livreur.

#### d. Existe-t-il un mécanisme de reversement / réconciliation des espèces ?
- **Non.** Aucun flux de reversement, d'enregistrement d'encaissement cash, de compensation sur wallet ni de réconciliation n'existe dans :
  - `OrderService` (`OrderService.php:200-233`), `ParcelService` (`ParcelService.php:223-254`), `WalletService` (`WalletService.php:15-169`) ;
  - `DriverController` (`backend/app/Http/Controllers/Api/V1/Driver/DriverController.php:60-181`) ;
  - Les ressources Filament `OrderResource`, `ParcelResource`, `WalletTransactionResource` (`backend/app/Filament/Resources/WalletTransactionResource.php:46` ne contient qu'un type manuel générique `'adjustment'`, sans lien ni calcul cash).
- La règle CDC chap. 7.4.1 (*« Solde minimum : 10 000 F CFA pour accepter les commandes en espèces »*) n'est implémentée nulle part.

#### e. Risque de double crédit : le livreur est-il crédité sur wallet ET garde-t-il l'argent ?
- **Oui, risque critique avéré.** Sur toute commande GoShop ou expédition GoColis payée en `'cash'`, le livreur :
  1. encaisse 100 % du total client en espèces (4 800 F dans l'exemple GoShop, ou 100 % de `shipping_fee` en GoColis) ;
  2. **et** voit simultanément son wallet virtuel crédité de `courier_amount` (`+640 F` ou `+75 %`) retirable en Mobile Money (`OrderService.php:215`, `ParcelService.php:235`).  
  Aucun débit automatique (`-4 160 F` ou `-commission plateforme`) ni blocage de retrait n'empêche le livreur de conserver le cash et de retirer en plus le crédit wallet.

---

### Options de solution — (1) Espèces

- **Option 1 — Compensation immédiate par portefeuille prépayé livreur (modèle « caution / float » prévu au CDC 7.4.1 et 16) :**  
  N'autoriser l'attribution/acceptation des courses `'cash'` qu'aux livreurs ayant un solde wallet ≥ 10 000 F CFA, puis lors de la livraison `'cash'`, **débiter** le wallet du livreur du montant à reverser (ex. `-(products_total + platform_commission - merchant_cash_paid)` selon qui paie le commerce) au lieu de le créditer, tout en passant `payment_status` à `'paid'`.  
  - *Avantage :* Élimine instantanément le double crédit et automatise 100 % du recouvrement sans gestion de caisse physique.  
  - *Inconvénient :* Bloque les livreurs dont le wallet tombe sous le seuil tant qu'ils ne rechargent pas via Mobile Money ou au bureau.  
  - *Effort estimé :* **8 à 12 h** (contrôle solde à l'assignation/acceptation, écritures `WalletService` spécifiques `'cash'`, statut `'paid'`, tests Pest).

- **Option 2 — Paiement comptant du commerce par le livreur au ramassage + débit uniquement de la commission plateforme sur wallet :**  
  Le livreur paie `merchant_amount` (ou `products_total` si commission prélevée autrement) au commerce ou la plateforme règle via wallet, et la plateforme débite uniquement les parts plateforme/commerce réellement encaissées par le livreur lors de la livraison `'cash'`, avec génération d'une écriture `cash_collected` + alerte si solde négatif.  
  - *Avantage :* Plus simple pour les commerces locaux qui souhaitent du liquide ou un crédit wallet sans attente.  
  - *Inconvénient :* Exige que le livreur avance du liquide chez le commerce (ou complique la séparation entre commande annulée au porte-à-porte et commande payée).  
  - *Effort estimé :* **10 à 14 h**.

- **Option 3 — Compte de dette espèces (« Cash à reverser ») + réconciliation manuelle au bureau Daloa :**  
  Ne créditer ni le livreur ni (optionnellement) rendre disponibles les fonds avant apurement, créer un compteur/journal `cash_collections` (`pending_remittance`) par livreur et un écran Filament pour encaisser les versements espèces au bureau Daloa avec blocage automatique des retraits/courses cash au-delà d'un plafond (ex. 10 000 F).  
  - *Avantage :* Zéro avance Mobile Money exigée au démarrage pour recruter facilement les livreurs à Daloa.  
  - *Inconvénient :* Crée de la gestion de caisse humaine au bureau et un risque de défaut de paiement si un livreur disparaît avec la recette.  
  - *Effort estimé :* **14 à 20 h**.

- **Recommandation motivée :** **Option 1** (portefeuille prépayé avec seuil minimal ≥ 10 000 F CFA et débit atomique du montant dû au moment du code de livraison). Elle est déjà cohérente avec le CDC (chap. 7.4.1 *« Solde minimum : 10 000 F CFA pour accepter les commandes en espèces »* et chap. 16 *« Portefeuille prépayé »*) et avec l'architecture actuelle de `WalletService`, tout en supprimant totalement le risque d'impayé et de double crédit dès le lancement. *(Il faut toutefois préciser explicitement qui règle le commerce au ramassage : la plateforme via wallet commerce avec débit total `total_client - courier_amount = 4 160 F` sur le wallet livreur, ou paiement cash direct du livreur au commerce.)*

---

## (2) REMBOURSEMENTS

### Faits constatés dans le code

#### a. Quand un admin rembourse une commande ou un colis, qui est crédité (client, commerce, livreur) et sur quel wallet ?
- **Côté API :**
  - `AdminController::refund` (`backend/app/Http/Controllers/Api/V1/Admin/AdminController.php:61-70`) :
    - `$ownerType = $request->input('owner_type', 'courier');`
    - `$ownerId = $request->input('owner_id', $order->courier_id ?? $order->client_id);`
  - `AdminController::parcelRefund` (`backend/app/Http/Controllers/Api/V1/Admin/AdminController.php:92-101`) :
    - `$ownerType = $request->input('owner_type', 'courier');`
    - `$ownerId = $request->input('owner_id', $parcel->courier_id ?? $parcel->sender_id);`
  - **Résultat réel :** par défaut, c'est **le wallet du livreur (`owner_type = 'courier'`)** qui reçoit un crédit positif `+montant` (`WalletService::effectuerRemboursement`, `backend/app/Services/WalletService.php:71-91,136-151`). Si `courier_id` est `null`, `ownerId` retombe sur `$order->client_id` / `$parcel->sender_id` tout en gardant `ownerType = 'courier'` — ce qui crée ou crédite le wallet d'un livreur homonyme en ID plutôt que le client !
- **Côté Filament Admin :**
  - `ListRefunds` (`backend/app/Filament/Resources/RefundResource/Pages/ListRefunds.php:104-123`) ne propose **aucun champ** pour choisir le bénéficiaire et code en dur `$ownerType = 'courier'` (`ListRefunds.php:106,111`) avec le même fallback buggé `$order->courier_id ?? $order->client_id` / `$parcel->courier_id ?? $parcel->sender_id` (`ListRefunds.php:107,112`).
- **Le client n'est donc jamais remboursé** par le système actuel : `WalletService::effectuerRemboursement` crédite un wallet `courier` (ou `merchant` si passé explicitement à l'API), et ne déclenche aucun virement Mobile Money vers le téléphone du client.

#### b. Un client a-t-il un wallet en base ? Que se passe-t-il s'il n'en a pas ?
- **Non.** Le CDC (chap. 11.6 : *« Contrainte wallets : `user_type` IN ('merchant', 'courier') — les clients n'ont pas de portefeuille »*) et la migration `wallets` (`backend/database/migrations/2026_06_01_000011_create_wallets_table.php:11-12,35-36`) interdisent les wallets clients via la contrainte SQL :  
  `CHECK (owner_type IN ('merchant', 'courier'))`.
- Confirmé par `WalletServiceTest.php:130-144` (`it('ne crée pas de wallet pour un client')`).
- **Ce qui se passe si l'on tente de créditer un client :** `WalletService::effectuerRemboursement` (`WalletService.php:80`) appelle `obtenirOuCreerWallet('client', $clientId)` (`WalletService.php:107-113`), qui exécute `Wallet::firstOrCreate(['owner_type' => 'client', ...])`. L'insertion SQL échoue immédiatement avec une **`QueryException` (HTTP 500)** sur violation de contrainte `CHECK`.

#### c. Les plafonds 2 000 / 10 000 / 50 000 par rôle sont-ils appliqués ? Où ?
- **Oui, partiellement et avec de fortes incohérences entre 3 endroits :**
  1. **API `AdminController::PLAFONDS_REMBOURSEMENT`** (`backend/app/Http/Controllers/Api/V1/Admin/AdminController.php:13-21,52-59,83-90`) — valeurs codées en dur (ignorent `SettingsService`) :
     - `city_agent`: `0`, `city_support`: `2000`, `city_finance`: `10000`, `city_manager`: **`50000`**, `national_finance`/`dg_national`/`super_admin`: `PHP_INT_MAX`, `national_support`: absent (donc `0`).
  2. **Filament `ListRefunds`** (`backend/app/Filament/Resources/RefundResource/Pages/ListRefunds.php:65-72,83-99`) + **`SettingsService::DEFAULTS`** (`backend/app/Services/SettingsService.php:60-70`) :
     - `city_support`: `2000`, `city_finance`: `10000`, `city_manager`: **`10000`** (différent du `50000` de `AdminController` et du CDC 8.5 `10 000 – 50 000`), `national_support`: `2000`, `national_finance`: `50000`, `super_admin`: **`50000`** (empêche Super Admin de dépasser 50 000 F alors que le CDC chap. 8.5 attribue `> 50 000 F` au Super Admin), `dg_national`: `50000` (absent du formulaire `SettingsPage.php:291-303`).
  3. **`WalletService::effectuerRemboursement`** (`WalletService.php:71-91`) n'applique **aucun contrôle de plafond**.
  4. **Absence d'isolation par ville dans `AdminController::refund()` / `parcelRefund()` :** bien que le groupe de routes `admin` utilise le middleware `city_context` (`backend/routes/api.php:143`), `refund()` et `parcelRefund()` ne vérifient pas que `$order->city_id === $user->city_id` pour un rôle local.

#### d. Un même remboursement peut-il être fait deux fois sur la même commande ?
- **Oui :**
  - **Sur une commande GoShop :** ni `AdminController::refund` (`AdminController.php:41-77`) ni `ListRefunds` (`ListRefunds.php:104-123`) ne vérifient le statut de la commande, ni l'existence d'un remboursement précédent, et ne modifient rien sur `orders`. On peut appeler l'action ou l'endpoint **plusieurs fois de suite** et créditer le wallet à chaque appel.
  - **Sur un colis GoColis :** `AdminController::parcelRefund` (`AdminController.php:95-103`) et `ListRefunds` (`ListRefunds.php:114-123`) passent bien `$parcel->status` à `'refunded'` **après** avoir crédité le wallet, **mais ne vérifient pas avant** (ni via `lockForUpdate()`) que `$parcel->status !== 'refunded'`. Un second appel sur un colis `'refunded'` recrédite donc le wallet.
  - De plus, `AdminController::refund()` et `parcelRefund()` ne passent ni `$order->id` ni `$parcel->id` à `WalletService::effectuerRemboursement()` (`AdminController.php:64-70,95-101` ; `ListRefunds.php:117-123`), donc même `wallet_transactions` ne permet pas une contrainte d'unicité sur `order_id` / `parcel_id`.
  - *(Également constaté dans `parcelRefund()` `AdminController.php:82` : absence de `$request->validate(...)`, donc un `montant <= 0` n'est pas rejeté.)*

#### e. Le remboursement est-il tracé dans `activity_logs` et `wallet_transactions` ?
- **`wallet_transactions` :** **Oui, partiellement.** `WalletService::effectuerRemboursement` (`WalletService.php:82-90,154-166`) enregistre une ligne immuable (`type = 'refund'`, `amount`, `balance_before`, `balance_after`, `reference`, `description`, `status = 'completed'`, `processed_by = $adminId`), affichée dans `RefundResource` (`backend/app/Filament/Resources/RefundResource.php:40-103`). En revanche, **`order_id` et `parcel_id` restent `NULL`** car aucun appelant ne les fournit.
- **`activity_logs` :** **Non.** Ni `WalletService::effectuerRemboursement()`, ni `AdminController::refund()` / `parcelRefund()`, ni `ListRefunds::createRefund` n'appellent `ActivityLogService` ou `ActivityLog::create()`. *(Constat connexe dans `ListRefunds.php:41,51,108,113` : le code référence `$order->order_number`, `$order->total` et `$parcel->tracking_number`, attributs absents des tables `orders` et `parcels`.)*

---

### Options de solution — (2) Remboursements

- **Option 1 — Remboursement client réel via Mobile Money (ou avoir promo) sans wallet client + table dédiée `refunds` :**  
  Conserver l'interdiction de wallet client (`CDC 11.6`), créer une table/entité `refunds` liée à `order_id` / `parcel_id` (avec unicité/idempotence sous `lockForUpdate()` et vérification `payment_status === 'paid'`), déclencher un remboursement Mobile Money (API Hub2/CinetPay Payout ou bon d'achat) vers le téléphone du client pour les commandes payées en ligne, et réserver `WalletService` uniquement aux ajustements/dédommagements `courier`/`merchant`, avec journalisation systématique dans `activity_logs`.  
  - *Avantage :* Respecte le CDC chap. 11.6 (pas de wallet client) et rembourse **vraiment** le client lésé sur son compte Mobile Money.  
  - *Inconvénient :* Dépend de la capacité de payout/refund de l'agrégateur Hub2/CinetPay (ou du traitement manuel Mobile Money par la finance locale pour les commandes `'cash'`).  
  - *Effort estimé :* **12 à 18 h**.

- **Option 2 — Ouvrir un wallet « avoir / crédit client » strictement non-retirable (`owner_type = 'client'`) :**  
  Modifier la contrainte SQL `wallets_owner_type_check` pour autoriser `'client'` uniquement pour recevoir des remboursements utilisables comme crédit sur les prochaines commandes GoShop/GoColis (sans retrait possible), tout en verrouillant `order_id`/`parcel_id` contre les doubles remboursements et en alignant les plafonds via `SettingsService`.  
  - *Avantage :* Réutilise `WalletService` existant, instantané même si la commande initiale était en espèces, et garde l'argent dans l'écosystème GoLivreur.  
  - *Inconvénient :* Contredit la règle CDC chap. 11.6 (*« les clients n'ont pas de portefeuille »*) et nécessite un nouveau moyen de paiement `'wallet'` côté client.  
  - *Effort estimé :* **10 à 14 h**.

- **Option 3 — Remboursement manuel hors-ligne tracé dans `refunds` + ajustement partenaire séparé :**  
  Créer une table `refunds` (statuts `pending` → `paid_by_finance`, référence Mobile Money saisie par `city_finance`, unicité par `order_id`/`parcel_id`, trace obligatoire `activity_logs`) pour le remboursement client effectué manuellement via OM/Wave/espèces, et séparer l'éventuel débit/indemnisation `courier`/`merchant` sur `WalletService`.  
  - *Avantage :* Opérationnel dès le jour 1 à Daloa même sans API refund automatique Hub2/CinetPay, tout en corrigeant l'erreur comptable actuelle (ne crédite plus le livreur par erreur).  
  - *Inconvénient :* Étape manuelle pour `city_finance` lors du virement au client.  
  - *Effort estimé :* **8 à 12 h**.

- **Recommandation motivée :** **Option 3 au lancement (évolutive vers Option 1).** Elle respecte strictement le CDC chap. 11.6 (*aucun wallet client*), corrige immédiatement le défaut majeur actuel (qui crédite le livreur lors d'un remboursement !), empêche tout double remboursement grâce à une table `refunds` liée à `order_id` / `parcel_id`, unifie les plafonds (`SettingsService` aligné sur CDC 8.5 : 2 000 / 10 000 / 50 000 / illimité Super Admin) et journalise dans `activity_logs` — sans bloquer le lancement sur l'API refund automatique de l'agrégateur, qui pourra être branchée ensuite sur le même modèle `refunds`.

---

## Questions à poser au fondateur (en français simple)

### Sur les paiements en espèces
1. **Quand un client paie en espèces à la livraison (GoShop), qui encaisse l'argent du commerce et comment le commerce est-il réglé ?**
   - *Choix A :* Le livreur paie lui-même le commerce en espèces au moment où il récupère le plat, puis garde les espèces du client (la plateforme ne prélève que sa commission sur le wallet du livreur).
   - *Choix B :* Le livreur encaisse tout chez le client (ex. 4 800 F), le commerce est crédité dans son portefeuille GoLivreur (+3 400 F), et la plateforme récupère les 4 160 F chez le livreur (par débit automatique de son portefeuille prépayé ou dépôt au bureau).
2. **Est-ce que la règle des 10 000 F CFA de solde minimum sur le portefeuille livreur pour accepter les commandes en espèces doit être activée dès le lancement à Daloa ?** (Sinon, quel plafond maximum de cash en poche autoriser avant de bloquer le livreur ou exiger un versement au bureau ?)
3. **Pour GoColis payé en espèces à l'expédition ou à la livraison :** à quel moment l'argent est-il encaissé (au ramassage chez l'expéditeur ou à la remise au destinataire), et la part plateforme (25 %) doit-elle être automatiquement débitée du portefeuille du livreur ?

### Sur les remboursements et litiges
4. **Quand on doit rembourser un client (commande annulée après paiement Orange Money, erreur, ou colis perdu/cassé), comment veut-on lui rendre son argent ?**
   - *Choix A :* Transfert Mobile Money (Orange Money / Wave) directement sur le numéro du client (effectué par le service Finance locale ou automatiquement via l'agrégateur).
   - *Choix B :* Remise en espèces au bureau de Daloa.
   - *Choix C :* Avoir utilisable sur sa prochaine commande (ce qui reviendrait à autoriser un portefeuille/bon client malgré le CDC actuel).
5. **Qui supporte financièrement le remboursement en cas de faute du livreur ou du commerce ?** (La plateforme paie le client et débite-t-elle ensuite le portefeuille du livreur/commerce responsable, ou la plateforme prend-elle la perte à sa charge via une caisse litige ?)
6. **Concernant les plafonds de validation (CDC chap. 8.5 vs panneau Admin) :**
   - Confirmez-vous que le **Manager de Ville (`city_manager`)** peut valider jusqu'à **50 000 F CFA** (comme écrit au CDC 8.5 et dans l'API, contre 10 000 F actuellement dans les Paramètres Admin) ?
   - Confirmez-vous que le **Super Admin** (et le **DG National** ?) n'a **aucun plafond limitant** au-dessus de 50 000 F CFA (contrairement aux 50 000 F configurés dans l'écran Paramètres actuel) ?
   - Peut-on autoriser plusieurs remboursements partiels sur une même commande (jusqu'à concurrence du montant total payé), ou un **seul remboursement unique** par commande/colis ?


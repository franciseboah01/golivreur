# GoLivreur — Glossaire et FAQ

---

## Glossaire

| Terme | Définition |
|---|---|
| **GoLivreur** | La plateforme complète (backend + 3 apps + admin) |
| **GoShop** | Service d'achat et livraison depuis les commerces locaux |
| **GoColis** | Service d'expédition de colis entre particuliers |
| **GoLivreur Client** | Application mobile pour les clients (iOS + Android) |
| **GoLivreur Business** | Application mobile pour les commerces |
| **GoLivreur Driver** | Application mobile pour les livreurs |
| **City Context** | Middleware Laravel qui filtre automatiquement toutes les requêtes des admins locaux à leur ville assignée via `city_id` |
| **Admin Local** | Désigne collectivement City Manager, City Support et City Agents d'une ville |
| **Portefeuille virtuel (wallet)** | Compte interne à la plateforme pour les marchands et livreurs uniquement. Les clients n'ont pas de wallet |
| **Mode Pluie** | Majoration automatique des frais de livraison en cas de pluie détectée via OpenWeatherMap ou activée manuellement |
| **Mode Urgence** | Suspension de toutes nouvelles commandes et expéditions + bandeau rouge dans les 3 apps |
| **Code expéditeur** | Code à 4 chiffres hashé en base, affiché dans l'app expéditeur, saisi par le livreur au ramassage GoColis |
| **Code destinataire** | Code à 4 chiffres hashé en base, envoyé par SMS au destinataire, saisi par le livreur à la livraison GoColis |
| **Code de confirmation GoShop** | Code à 4 chiffres hashé en base, affiché dans l'app client, saisi par le livreur à la livraison |
| **Statut waiting** | Statut GoColis : aucun livreur disponible après 3 tentatives d'attribution, alerte Admin déclenchée |
| **Pénalité anti-filtrage** | 3 refus de course consécutifs par un livreur = mise hors ligne automatique pendant 15 minutes |
| **Heure de pointe** | 11h30–14h00 et 18h00–21h00 — tarifs majorés GoShop |
| **Hub2/CinetPay** | Agrégateur de paiement Mobile Money utilisé pour Orange Money (et MTN MoMo, Wave à 3 mois) |
| **HMAC** | Hash-based Message Authentication Code — signature cryptographique des webhooks Hub2/CinetPay |
| **Horizon** | Dashboard Laravel de supervision des queues Redis (jobs, workers, métriques) |
| **Reverb** | Serveur WebSocket officiel Laravel pour les communications temps réel (position GPS, statuts) |
| **Queue high** | File de traitement haute priorité Redis : confirmation paiements, webhooks Hub2/CinetPay |
| **Queue default** | File de traitement normale : notifications FCM, SMS OTP |
| **Queue low** | File de traitement basse priorité : mises à jour GPS, logs asynchrones |
| **Scramble** | Package Laravel de génération automatique de documentation OpenAPI 3.0 depuis le code |
| **Bruno** | Client API open source (alternative à Postman), fichiers versionnés dans Git |
| **ADR** | Architecture Decision Record — fiche de documentation d'une décision technique structurante |
| **DoD** | Definition of Done — critères qu'un sprint doit satisfaire pour être considéré terminé |
| **Greenfield** | Projet développé de zéro, sans migration de données existantes |

---

## FAQ — Clients

**Q : Puis-je commander dans plusieurs commerces en même temps ?**  
Non, une commande est associée à un seul commerce à la fois. Pour commander dans deux commerces différents, il faut passer deux commandes séparées.

**Q : Que se passe-t-il si le commerce ne répond pas ?**  
Le commerce a 45 secondes pour accepter ou refuser. Sans réponse, la commande est automatiquement annulée et le client en est notifié immédiatement. Aucun débit n'est effectué.

**Q : Mon paiement Orange Money a été débité mais je n'ai pas de confirmation de commande ?**  
Contactez immédiatement le City Support via WhatsApp Business. La table `webhook_logs` permet de retrouver la transaction exacte et de la rejouer manuellement en moins de 5 minutes.

**Q : Puis-je annuler une commande après qu'elle soit acceptée ?**  
Une commande acceptée par le commerce ne peut être annulée que par un Admin (City Support ou supérieur). Contactez le support via WhatsApp.

**Q : Le destinataire de mon colis GoColis a besoin de l'application ?**  
Non. Le destinataire reçoit son code de validation par SMS sur son numéro de téléphone. Il n'a pas besoin d'installer l'application.

---

## FAQ — Livreurs

**Q : Que se passe-t-il si je refuse 3 courses d'affilée ?**  
Vous êtes automatiquement mis hors ligne pendant 15 minutes. Cette pénalité anti-filtrage protège les commerces et clients contre les refus abusifs.

**Q : Que faire si le code de confirmation que me donne le client est incorrect ?**  
Après 3 erreurs de saisie, la course est bloquée et une alerte est envoyée au City Support. Ne partez pas — attendez l'appel du support qui débloquera manuellement après vérification vocale avec le client.

**Q : Je dois prendre une photo du colis GoColis. Quel niveau de qualité ?**  
La photo doit permettre d'identifier clairement le colis (taille, état, contenu visible si non emballé). Une photo floue ou trop sombre peut être refusée par le destinataire et générer un litige.

**Q : Comment est calculé mon gain sur une livraison GoShop ?**  
Vous recevez 80% des frais de livraison (la plateforme garde 20%). Exemple : livraison facturée 800 F CFA → vous recevez 640 F CFA crédités sur votre wallet.

**Q : Comment retirer mon argent du wallet ?**  
Le retrait wallet vers Mobile Money est géré par le City Finance de votre ville. Contactez le support pour initier un retrait.

---

## FAQ — Commerces

**Q : Ma commission de 15% est calculée sur quoi exactement ?**  
Sur le prix des produits uniquement, pas sur les frais de livraison. Exemple : produits à 4 000 F CFA → vous recevez 3 400 F CFA (4 000 - 15%).

**Q : Je peux modifier mes prix sans prévenir GoLivreur ?**  
Oui, vous gérez votre catalogue librement. Les nouvelles commandes prendront immédiatement le nouveau prix. Les commandes déjà passées conservent le prix initial (stocké dans `order_items.unit_price`).

**Q : Que se passe-t-il si je ferme mon application Business ?**  
Si vous avez une session active, les commandes entrantes déclenchent une notification push FCM sur votre téléphone même si l'app est en arrière-plan. Vous avez 45 secondes pour ouvrir l'app et répondre.

**Q : Puis-je refuser une commande que j'ai déjà acceptée ?**  
Non. Une fois acceptée, la commande ne peut être annulée que par un Admin. Vous pouvez signaler un problème (rupture de stock) via le chat WhatsApp Business dédié.

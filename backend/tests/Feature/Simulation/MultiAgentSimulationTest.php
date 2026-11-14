<?php

namespace Tests\Feature\Simulation;

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Parcel;
use App\Models\ParcelPhoto;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Simulation multi-agents de bout en bout pour GoLivreur (Daloa).
 *
 * Acteurs simulés :
 *  1. AdminAgent    : Francis EBOAH (Super Admin) - Supervision & configuration
 *  2. MerchantAgent : Tantie Adjoua (Commerce) - Acceptation 45s & préparation
 *  3. ClientAgent   : Koffi Jean-Marc (Client) - Commande repère Daloa & code 4 chiffres
 *  4. DriverAgent   : Ouattara Seydou (Livreur) - Prise en charge 30s & validation code
 */
class MultiAgentSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulation_complete_multi_agents_goshop_daloa(): void
    {
        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 1 : AGENT ADMIN — Configuration de la Ville de Daloa & Contrôle
        // ══════════════════════════════════════════════════════════════════════
        $daloa = City::create([
            'name' => 'Daloa',
            'is_active' => true,
            'center_lat' => 6.8770,
            'center_lng' => -6.4502,
        ]);

        // Scénario simulation Mobile Money : réactivation explicite de orange_money et wave pour ce test (D-PAIEMENT-1)
        app(SettingsService::class)->set('paiements', ['modes_actifs' => ['cash', 'orange_money', 'wave']], $daloa->id);

        $adminUser = User::create([
            'name' => 'Francis EBOAH (Admin)',
            'phone' => '0700000000',
            'email' => 'admin@golivreur.ci',
            'password' => Hash::make('SecretAdmin123!'),
            'role' => 'super_admin',
            'city_id' => null,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
        $adminToken = JWTAuth::fromUser($adminUser);

        // L'Admin vérifie que la ville est active et prête pour les opérations
        $this->assertTrue($daloa->is_active);
        $this->assertEquals('super_admin', $adminUser->role);

        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 2 : AGENT COMMERCE — Chez Adjoua s'active & met à jour son stock
        // ══════════════════════════════════════════════════════════════════════
        $merchantUser = User::create([
            'name' => 'Tantie Adjoua',
            'phone' => '0702010001',
            'role' => 'merchant',
            'city_id' => $daloa->id,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
        $merchantToken = JWTAuth::fromUser($merchantUser);

        $merchant = Merchant::create([
            'user_id' => $merchantUser->id,
            'city_id' => $daloa->id,
            'name' => 'Maquis Chez Tantie Adjoua',
            'category' => 'maquis',
            'address' => 'Quartier Commerce, Daloa (face grande mosquée)',
            'lat' => 6.8821,
            'lng' => -6.4478,
            'prep_time_minutes' => 20,
            'is_open' => true,
            'is_active' => true,
            'commission_rate' => 15.00,
            'average_rating' => 4.8,
        ]);

        $productAttieke = Product::create([
            'merchant_id' => $merchant->id,
            'name' => 'Attiéké + Poisson braisé',
            'price' => 2500,
            'category' => 'Plats Ivoiriens',
            'is_available' => true,
        ]);

        $productAlloco = Product::create([
            'merchant_id' => $merchant->id,
            'name' => 'Portion Alloco doré',
            'price' => 500,
            'category' => 'Accompagnements',
            'is_available' => true,
        ]);

        // Création du wallet du commerce
        $merchantWallet = Wallet::create([
            'owner_type' => 'merchant',
            'owner_id' => $merchant->id,
            'balance' => 0,
            'total_earned' => 0,
            'total_withdrawn' => 0,
        ]);

        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 3 : AGENT LIVREUR — Ouattara Seydou se met en ligne
        // ══════════════════════════════════════════════════════════════════════
        $driverUser = User::create([
            'name' => 'Ouattara Seydou (Moto Daloa)',
            'phone' => '0703000001',
            'role' => 'driver',
            'city_id' => $daloa->id,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);
        $driverToken = JWTAuth::fromUser($driverUser);

        $courier = Courier::create([
            'user_id' => $driverUser->id,
            'city_id' => $daloa->id,
            'vehicle_type' => 'moto',
            'vehicle_plate' => '4582-HZ-01',
            'is_online' => true,
            'is_active' => true,
            'current_lat' => 6.8810,
            'current_lng' => -6.4490,
            'average_rating' => 4.9,
            'total_ratings' => 42,
        ]);

        // Création du wallet du livreur
        $courierWallet = Wallet::create([
            'owner_type' => 'courier',
            'owner_id' => $courier->id,
            'balance' => 3500, // solde initial
            'total_earned' => 28000,
            'total_withdrawn' => 24500,
        ]);

        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 4 : AGENT CLIENT — Koffi passe commande GoShop
        // ══════════════════════════════════════════════════════════════════════
        $clientUser = User::create([
            'name' => 'Koffi Jean-Marc',
            'phone' => '0701000002',
            'role' => 'client',
            'city_id' => $daloa->id,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);

        // Le client consulte le catalogue et passe sa commande avec repère local Daloa
        $clientOrderResponse = $this->actingAs($clientUser, 'api')
            ->postJson('/api/v1/client/orders', [
                'merchant_id' => $merchant->id,
                'items' => [
                    ['product_id' => $productAttieke->id, 'quantity' => 2], // 5000 F CFA
                    ['product_id' => $productAlloco->id, 'quantity' => 1],  // 500 F CFA
                ],
                'delivery_address' => 'Quartier Lobia, en face de la pharmacie principale, Daloa',
                'delivery_lat' => 6.8800,
                'delivery_lng' => -6.4510,
                'payment_method' => 'wave',
                'distance_km' => 3.2,
            ]);

        $clientOrderResponse->assertCreated()
            ->assertJsonPath('succes', true);

        $orderId = $clientOrderResponse->json('donnees.id');
        $this->assertNotNull($orderId);

        $order = Order::findOrFail($orderId);
        $this->assertEquals(Order::STATUS_PENDING, $order->status);
        $this->assertEquals(5500, $order->products_total);
        $this->assertNotNull($order->confirmation_code); // Code de sécurité secret généré

        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 5 : AGENT COMMERCE — Réception & Acceptation sous 45s
        // ══════════════════════════════════════════════════════════════════════
        $acceptResponse = $this->actingAs($merchantUser, 'api')
            ->postJson("/api/v1/merchant/orders/{$order->id}/accept");

        $acceptResponse->assertOk()
            ->assertJsonPath('succes', true);

        $order->refresh();
        $this->assertEquals(Order::STATUS_ACCEPTED, $order->status);

        // Le commerce prépare et passe en prêt via l'API
        $readyResponse = $this->actingAs($merchantUser, 'api')
            ->postJson("/api/v1/merchant/orders/{$order->id}/ready");

        $readyResponse->assertOk()
            ->assertJsonPath('succes', true);

        $order->refresh();
        $this->assertEquals(Order::STATUS_READY, $order->status);

        // Assignation du livreur
        $order->update(['courier_id' => $courier->id]);

        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 6 : AGENT LIVREUR — Récupération de la commande
        // ══════════════════════════════════════════════════════════════════════
        $order->update([
            'status' => Order::STATUS_PICKED_UP,
        ]);
        $this->assertEquals(Order::STATUS_PICKED_UP, $order->status);

        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 7 : LIVRAISON — Validation par Code de Sécurité 4 Chiffres
        // ══════════════════════════════════════════════════════════════════════
        // On simule le code secret client "4829"
        $codeSecret = '4829';
        $order->update([
            'confirmation_code' => Hash::make($codeSecret),
            'courier_amount' => 800,  // Commission course livreur
            'merchant_amount' => 4675, // Plat net (5500 - 15% commission = 4675)
        ]);

        // Tentative erronée rejetée d'abord pour vérifier la sécurité
        $wrongCodeResponse = $this->actingAs($driverUser, 'api')
            ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", [
                'code' => '9999',
            ]);
        $wrongCodeResponse->assertStatus(422);

        // Saisie du code client valide "4829" par le livreur
        $deliveryResponse = $this->actingAs($driverUser, 'api')
            ->postJson("/api/v1/driver/orders/{$order->id}/confirm-delivery", [
                'code' => $codeSecret,
            ]);

        $deliveryResponse->assertOk()
            ->assertJsonPath('succes', true);

        $order->refresh();
        $this->assertEquals(Order::STATUS_DELIVERED, $order->status);

        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 8 : AGENT ADMIN — Vérification de l'équilibre financier & Wallets
        // ══════════════════════════════════════════════════════════════════════
        // Solde livreur : 3500 initial + 800 course = 4300 F CFA
        $courierWallet->refresh();
        $this->assertEquals(4300, $courierWallet->balance);

        // Solde commerce : 0 initial + 4675 net = 4675 F CFA
        $merchantWallet->refresh();
        $this->assertEquals(4675, $merchantWallet->balance);

        // Vérification des transactions enregistrées dans le grand livre
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $courierWallet->id,
            'type' => 'credit_delivery',
            'amount' => 800,
        ]);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $merchantWallet->id,
            'type' => 'credit_delivery',
            'amount' => 4675,
        ]);

        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 9 : POST-LIVRAISON — Le Client note le Livreur 5 Étoiles
        // ══════════════════════════════════════════════════════════════════════
        $ratingResponse = $this->actingAs($clientUser, 'api')
            ->postJson('/api/v1/client/ratings', [
                'courier_id' => $courier->id,
                'order_id' => $order->id,
                'rating' => 5,
                'comment' => 'Livraison ultra rapide au quartier Lobia, livreur très poli !',
            ]);

        $ratingResponse->assertCreated()
            ->assertJsonPath('succes', true);

        // Recalcul de la note moyenne livreur
        $courier->refresh();
        $this->assertEquals(43, $courier->total_ratings);
        $this->assertGreaterThanOrEqual(4.8, (float) $courier->average_rating);

        // Simulation réussie à 100%
        $this->assertTrue(true, 'Cycle complet multi-agents Daloa GoShop exécuté avec succès');
    }

    public function test_simulation_complete_multi_agents_gocolis_daloa(): void
    {
        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 1 : Configuration Daloa & Acteurs
        // ══════════════════════════════════════════════════════════════════════
        $daloa = City::create([
            'name' => 'Daloa',
            'is_active' => true,
            'center_lat' => 6.8770,
            'center_lng' => -6.4502,
        ]);

        // Scénario simulation Mobile Money : réactivation explicite de orange_money et wave pour ce test (D-PAIEMENT-1)
        app(SettingsService::class)->set('paiements', ['modes_actifs' => ['cash', 'orange_money', 'wave']], $daloa->id);

        $clientUser = User::create([
            'name' => 'Ama Kouassi (Expéditrice Daloa)',
            'phone' => '0701000001',
            'role' => 'client',
            'city_id' => $daloa->id,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);

        $driverUser = User::create([
            'name' => 'Coulibaly Issouf (Livreur Colis)',
            'phone' => '0703000002',
            'role' => 'driver',
            'city_id' => $daloa->id,
            'is_active' => true,
            'phone_verified_at' => now(),
        ]);

        $courier = Courier::create([
            'user_id' => $driverUser->id,
            'city_id' => $daloa->id,
            'vehicle_type' => 'moto',
            'vehicle_plate' => '3311-JA-01',
            'is_online' => true,
            'is_active' => true,
            'current_lat' => 6.8795,
            'current_lng' => -6.4505,
            'average_rating' => 4.85,
            'total_ratings' => 20,
        ]);

        $courierWallet = Wallet::create([
            'owner_type' => 'courier',
            'owner_id' => $courier->id,
            'balance' => 1500,
            'total_earned' => 12000,
            'total_withdrawn' => 10500,
        ]);

        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 2 : LE CLIENT CREE L'EXPEDITION GOCOLIS (Valeur > 20 000 F CFA)
        // ══════════════════════════════════════════════════════════════════════
        $parcelResponse = $this->actingAs($clientUser, 'api')
            ->postJson('/api/v1/client/parcels', [
                'recipient_name' => 'Koné Mariam',
                'recipient_phone' => '0709000001',
                'delivery_address' => 'Quartier Résidentiel, près du lycée moderne, Daloa',
                'delivery_lat' => 6.8800,
                'delivery_lng' => -6.4510,
                'pickup_lat' => 6.8821,
                'pickup_lng' => -6.4478,
                'size' => 'moyen',
                'weight_kg' => 2.5,
                'declared_value' => 30000, // Assurance 3% obligatoire
                'description' => 'Documents et colis textile scellé',
                'payment_method' => 'orange_money',
                'distance_km' => 3.5,
            ]);

        $parcelResponse->assertCreated()
            ->assertJsonPath('succes', true);

        $parcelId = $parcelResponse->json('donnees.id');
        $senderCodePlain = $parcelResponse->json('donnees.sender_code_plain');

        $this->assertNotNull($parcelId);
        $this->assertEquals(4, strlen($senderCodePlain));

        $parcel = Parcel::findOrFail($parcelId);
        $this->assertTrue($parcel->has_insurance);
        $this->assertEquals(900, $parcel->insurance_amount); // 3% de 30 000

        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 3 : LE LIVREUR ARRIVE CHEZ L'EXPEDITEUR & PREND LA PHOTO
        // ══════════════════════════════════════════════════════════════════════
        $parcel->update([
            'courier_id' => $courier->id,
            'status' => Parcel::STATUS_AT_SENDER,
        ]);

        // Le livreur prend la photo obligatoire du colis scellé
        ParcelPhoto::create([
            'parcel_id' => $parcel->id,
            'type' => 'pickup',
            'photo_url' => 'https://spaces.golivreur.ci/photos/colis/'.$parcel->id.'/pickup.jpg',
        ]);

        // Le livreur saisit le code expéditeur 4 chiffres
        $verifySenderResponse = $this->actingAs($driverUser, 'api')
            ->postJson("/api/v1/parcels/{$parcel->id}/verify-sender-code", [
                'code' => $senderCodePlain,
            ]);

        $verifySenderResponse->assertOk()
            ->assertJsonPath('succes', true);

        $parcel->refresh();
        $this->assertEquals(Parcel::STATUS_PICKED_UP, $parcel->status);

        // ══════════════════════════════════════════════════════════════════════
        // ETAPE 4 : LIVRAISON AU DESTINATAIRE AVEC CODE DE DECHARGE
        // ══════════════════════════════════════════════════════════════════════
        $codeDestinataire = '7429';
        $parcel->update([
            'status' => Parcel::STATUS_AT_RECIPIENT,
            'recipient_code' => Hash::make($codeDestinataire),
            'courier_amount' => 1200, // 75% des frais colis
        ]);

        // Photo de remise au destinataire
        ParcelPhoto::create([
            'parcel_id' => $parcel->id,
            'type' => 'delivery',
            'photo_url' => 'https://spaces.golivreur.ci/photos/colis/'.$parcel->id.'/delivery.jpg',
        ]);

        // Saisie du code destinataire par le livreur
        $verifyRecipientResponse = $this->actingAs($driverUser, 'api')
            ->postJson("/api/v1/parcels/{$parcel->id}/verify-recipient-code", [
                'code' => $codeDestinataire,
            ]);

        $verifyRecipientResponse->assertOk()
            ->assertJsonPath('succes', true);

        $parcel->refresh();
        $this->assertEquals(Parcel::STATUS_DELIVERED, $parcel->status);

        // Vérification du crédit instantané du wallet livreur
        $courierWallet->refresh();
        // 1500 initial + 1200 frais de livraison = 2700 F CFA
        $this->assertEquals(2700, $courierWallet->balance);

        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $courierWallet->id,
            'type' => 'credit_shipping',
            'amount' => 1200,
        ]);

        $this->assertTrue(true, 'Cycle complet multi-agents Daloa GoColis exécuté avec succès');
    }
}

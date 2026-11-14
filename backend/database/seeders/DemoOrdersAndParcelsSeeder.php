<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Courier;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Parcel;
use App\Models\Product;
use App\Models\Rating;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoOrdersAndParcelsSeeder extends Seeder
{
    public function run(): void
    {
        // On exclut le compte test 0701000099 pour qu'il garde un état vierge (0 commande, 0 colis)
        $clients = User::where('role', 'client')->where('phone', '!=', '0701000099')->get();
        $merchants = Merchant::all();
        $couriers = Courier::all();
        $city = City::first() ?? City::create([
            'name' => 'Daloa',
            'is_active' => true,
            'center_lat' => 6.8774,
            'center_lng' => -6.4502,
        ]);
        $products = Product::all();

        if ($clients->isEmpty() || $merchants->isEmpty() || $couriers->isEmpty()) {
            return;
        }

        // ==========================================
        // 1. COMMANDES GOSHOP (16 commandes réalistes)
        // ==========================================
        $orderTemplates = [
            // Aujourd'hui - Livrées
            [
                'days_ago' => 0,
                'status' => Order::STATUS_DELIVERED,
                'payment_method' => 'wave',
                'payment_status' => 'paid',
                'delivery_address' => 'Quartier Commerce, face Pharmacie des Étoiles, Daloa',
                'confirmation_code' => Hash::make('1234'),
                'locked' => false,
                'special' => 'Sonner au portail noir',
            ],
            [
                'days_ago' => 0,
                'status' => Order::STATUS_DELIVERED,
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'delivery_address' => 'Tazibouo 1, près du Carrefour Céleste, Daloa',
                'confirmation_code' => Hash::make('4321'),
                'locked' => false,
                'special' => 'Appeler dès arrivée',
            ],
            [
                'days_ago' => 0,
                'status' => Order::STATUS_DELIVERED,
                'payment_method' => 'orange_money',
                'payment_status' => 'paid',
                'delivery_address' => 'Quartier Kennedy, Villa 42, Daloa',
                'confirmation_code' => Hash::make('5678'),
                'locked' => false,
                'special' => null,
            ],
            // Aujourd'hui - En cours
            [
                'days_ago' => 0,
                'status' => Order::STATUS_IN_TRANSIT,
                'payment_method' => 'cash',
                'payment_status' => 'pending',
                'delivery_address' => 'Gbeuliville, Rue du Lycée Antoine Gauze, Daloa',
                'confirmation_code' => Hash::make('8899'),
                'locked' => false,
                'special' => 'Livrer au 1er étage',
            ],
            [
                'days_ago' => 0,
                'status' => Order::STATUS_READY,
                'payment_method' => 'wave',
                'payment_status' => 'paid',
                'delivery_address' => 'Lobia 2, non loin du Grand Marché, Daloa',
                'confirmation_code' => Hash::make('2233'),
                'locked' => false,
                'special' => 'Attention au chien dans la cour',
            ],
            [
                'days_ago' => 0,
                'status' => Order::STATUS_ACCEPTED,
                'payment_method' => 'mtn_momo',
                'payment_status' => 'paid',
                'delivery_address' => 'Quartier Marais, Daloa',
                'confirmation_code' => Hash::make('1144'),
                'locked' => false,
                'special' => null,
            ],
            [
                'days_ago' => 0,
                'status' => Order::STATUS_PENDING,
                'payment_method' => 'cash',
                'payment_status' => 'pending',
                'delivery_address' => 'Zone Résidentielle, Daloa',
                'confirmation_code' => Hash::make('9911'),
                'locked' => false,
                'special' => 'Prévoir de la monnaie sur 10 000 F CFA',
            ],
            // Alerte Code Bloqué (pour tester le déblocage support)
            [
                'days_ago' => 0,
                'status' => Order::STATUS_IN_TRANSIT,
                'payment_method' => 'cash',
                'payment_status' => 'pending',
                'delivery_address' => 'Quartier Soleil, Daloa',
                'confirmation_code' => Hash::make('7777'),
                'locked' => true,
                'attempts' => 3,
                'special' => 'URGENT - Client n\'a pas retrouvé son SMS initial',
            ],
            // Historique (J-1 à J-6)
            [
                'days_ago' => 1,
                'status' => Order::STATUS_DELIVERED,
                'payment_method' => 'wallet',
                'payment_status' => 'paid',
                'delivery_address' => 'Tazibouo Université, Cité Universitaire, Daloa',
                'confirmation_code' => Hash::make('1234'),
                'locked' => false,
                'special' => null,
            ],
            [
                'days_ago' => 2,
                'status' => Order::STATUS_DELIVERED,
                'payment_method' => 'wave',
                'payment_status' => 'paid',
                'delivery_address' => 'Quartier Commerce, Daloa',
                'confirmation_code' => Hash::make('1234'),
                'locked' => false,
                'special' => null,
            ],
            [
                'days_ago' => 2,
                'status' => Order::STATUS_CANCELLED,
                'payment_method' => 'cash',
                'payment_status' => 'failed',
                'delivery_address' => 'Kennedy 1, Daloa',
                'confirmation_code' => Hash::make('1234'),
                'locked' => false,
                'cancel_reason' => 'Rupture de stock chez le marchand',
                'special' => null,
            ],
            [
                'days_ago' => 3,
                'status' => Order::STATUS_DELIVERED,
                'payment_method' => 'orange_money',
                'payment_status' => 'paid',
                'delivery_address' => 'Gbeuliville, Daloa',
                'confirmation_code' => Hash::make('1234'),
                'locked' => false,
                'special' => null,
            ],
            [
                'days_ago' => 4,
                'status' => Order::STATUS_DELIVERED,
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'delivery_address' => 'Lobia, Daloa',
                'confirmation_code' => Hash::make('1234'),
                'locked' => false,
                'special' => null,
            ],
            [
                'days_ago' => 5,
                'status' => Order::STATUS_DELIVERED,
                'payment_method' => 'wave',
                'payment_status' => 'paid',
                'delivery_address' => 'Quartier Évêché, Daloa',
                'confirmation_code' => Hash::make('1234'),
                'locked' => false,
                'special' => null,
            ],
            [
                'days_ago' => 6,
                'status' => Order::STATUS_DELIVERED,
                'payment_method' => 'mtn_momo',
                'payment_status' => 'paid',
                'delivery_address' => 'Quartier Gare, Daloa',
                'confirmation_code' => Hash::make('1234'),
                'locked' => false,
                'special' => null,
            ],
            [
                'days_ago' => 6,
                'status' => Order::STATUS_DELIVERED,
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'delivery_address' => 'Quartier Commerce, Daloa',
                'confirmation_code' => Hash::make('1234'),
                'locked' => false,
                'special' => null,
            ],
        ];

        foreach ($orderTemplates as $index => $t) {
            $client = $clients[$index % $clients->count()];
            $merchant = $merchants[$index % $merchants->count()];
            $courier = ($t['status'] === Order::STATUS_PENDING) ? null : $couriers[$index % $couriers->count()];

            $productsTotal = 3500 + ($index * 750);
            $deliveryFee = 1000;
            $platformCommission = round($productsTotal * 0.15); // 15%
            $merchantAmount = $productsTotal - $platformCommission;
            $courierAmount = round($deliveryFee * 0.80); // 80%

            $createdAt = now()->subDays($t['days_ago'])->subHours(rand(1, 8));

            $order = Order::create([
                'client_id' => $client->id,
                'merchant_id' => $merchant->id,
                'courier_id' => $courier?->id,
                'city_id' => $city->id,
                'status' => $t['status'],
                'products_total' => $productsTotal,
                'delivery_fee' => $deliveryFee,
                'platform_commission' => $platformCommission,
                'merchant_amount' => $merchantAmount,
                'courier_amount' => $courierAmount,
                'rain_mode_applied' => false,
                'peak_hours_applied' => false,
                'payment_method' => $t['payment_method'],
                'payment_status' => $t['payment_status'],
                'payment_reference' => 'PAY-GOSHOP-'.rand(100000, 999999),
                'confirmation_code' => $t['confirmation_code'],
                'confirmation_attempts' => $t['attempts'] ?? 0,
                'confirmation_locked' => $t['locked'] ?? false,
                'delivery_address' => $t['delivery_address'],
                'delivery_lat' => 6.8780 + ($index * 0.001),
                'delivery_lng' => -6.4510 + ($index * 0.001),
                'pickup_lat' => $merchant->lat ?? 6.8770,
                'pickup_lng' => $merchant->lng ?? -6.4500,
                'special_instructions' => $t['special'] ?? null,
                'cancel_reason' => $t['cancel_reason'] ?? null,
                'delivered_at' => $t['status'] === Order::STATUS_DELIVERED ? $createdAt->copy()->addMinutes(45) : null,
                'cancelled_at' => $t['status'] === Order::STATUS_CANCELLED ? $createdAt->copy()->addMinutes(20) : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            // Articles de commande
            if ($products->isNotEmpty()) {
                $product = $products[$index % $products->count()];
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => rand(1, 3),
                    'unit_price' => $product->price ?? 2000,
                    'product_name_snapshot' => $product->name,
                ]);
            }

            // Évaluations réalistes pour commandes livrées
            if ($order->status === Order::STATUS_DELIVERED) {
                Rating::firstOrCreate(
                    [
                        'rater_id' => $client->id,
                        'order_id' => $order->id,
                        'type' => 'goshop_merchant',
                    ],
                    [
                        'rateable_id' => $merchant->id,
                        'rating' => rand(4, 5),
                        'comment' => 'Repas très bon, commande préparée rapidement.',
                    ]
                );

                if ($courier) {
                    Rating::firstOrCreate(
                        [
                            'rater_id' => $client->id,
                            'order_id' => $order->id,
                            'type' => 'goshop_courier',
                        ],
                        [
                            'rateable_id' => $courier->id,
                            'rating' => rand(4, 5),
                            'comment' => 'Livreur courtois, ponctuel et professionnel.',
                        ]
                    );
                }
            }
        }

        // ==========================================
        // 2. EXPÉDITIONS GOCOLIS (16 colis réalistes)
        // ==========================================
        $parcelTemplates = [
            // Aujourd'hui - Livrés
            [
                'days_ago' => 0,
                'status' => Parcel::STATUS_DELIVERED,
                'size' => Parcel::SIZE_SMALL,
                'declared_value' => 15000,
                'has_insurance' => false,
                'insurance_amount' => 0,
                'shipping_fee' => 1000,
                'recipient_name' => 'Kouamé Brigitte',
                'recipient_phone' => '0707112233',
                'delivery_address' => 'Quartier Lobia, face clinique médicale, Daloa',
                'pickup_address' => 'Gbeuliville Carrefour Gauze, Daloa',
                'description' => 'Enveloppe scellée avec documents administratifs',
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'locked' => false,
            ],
            [
                'days_ago' => 0,
                'status' => Parcel::STATUS_DELIVERED,
                'size' => Parcel::SIZE_MEDIUM,
                'declared_value' => 45000,
                'has_insurance' => true,
                'insurance_amount' => 1350,
                'shipping_fee' => 1500,
                'recipient_name' => 'Traoré Ousmane',
                'recipient_phone' => '0758445566',
                'delivery_address' => 'Quartier Kennedy, villa 12, Daloa',
                'pickup_address' => 'Commerce Grand Marché, Daloa',
                'description' => 'Carton de pièces détachées et vêtements',
                'payment_method' => 'wave',
                'payment_status' => 'paid',
                'locked' => false,
            ],
            // Aujourd'hui - En transit
            [
                'days_ago' => 0,
                'status' => Parcel::STATUS_IN_TRANSIT,
                'size' => Parcel::SIZE_LARGE,
                'declared_value' => 85000,
                'has_insurance' => true,
                'insurance_amount' => 2550,
                'shipping_fee' => 2500,
                'recipient_name' => 'Soro Bakary',
                'recipient_phone' => '0709887766',
                'delivery_address' => 'Tazibouo 2, Daloa',
                'pickup_address' => 'Quartier Commerce, Daloa',
                'description' => 'Appareil électroménager et câbles',
                'payment_method' => 'orange_money',
                'payment_status' => 'paid',
                'locked' => false,
            ],
            [
                'days_ago' => 0,
                'status' => Parcel::STATUS_ACCEPTED,
                'size' => Parcel::SIZE_SMALL,
                'declared_value' => 0,
                'has_insurance' => false,
                'insurance_amount' => 0,
                'shipping_fee' => 1000,
                'recipient_name' => 'Konan Estelle',
                'recipient_phone' => '0504123456',
                'delivery_address' => 'Quartier Marais, Daloa',
                'pickup_address' => 'Quartier Soleil, Daloa',
                'description' => 'Pochette de clés et badge d\'accès',
                'payment_method' => 'cash',
                'payment_status' => 'pending',
                'locked' => false,
            ],
            // En attente
            [
                'days_ago' => 0,
                'status' => Parcel::STATUS_PENDING,
                'size' => Parcel::SIZE_MEDIUM,
                'declared_value' => 25000,
                'has_insurance' => true,
                'insurance_amount' => 750,
                'shipping_fee' => 1500,
                'recipient_name' => 'Bamba Souleymane',
                'recipient_phone' => '0701998877',
                'delivery_address' => 'Quartier Kennedy, Daloa',
                'pickup_address' => 'Gbeuliville, Daloa',
                'description' => 'Colis de cosmétiques et parfums',
                'payment_method' => 'cash',
                'payment_status' => 'pending',
                'locked' => false,
            ],
            // Alerte Code Enlèvement Bloqué
            [
                'days_ago' => 0,
                'status' => Parcel::STATUS_ACCEPTED,
                'size' => Parcel::SIZE_MEDIUM,
                'declared_value' => 30000,
                'has_insurance' => true,
                'insurance_amount' => 900,
                'shipping_fee' => 1500,
                'recipient_name' => 'Gnahoré Didier',
                'recipient_phone' => '0708554433',
                'delivery_address' => 'Tazibouo Université, Daloa',
                'pickup_address' => 'Quartier Commerce, Daloa',
                'description' => 'Chaussures de sport et boîte scellée',
                'payment_method' => 'wave',
                'payment_status' => 'paid',
                'locked' => true,
            ],
            // Historique (J-1 à J-6)
            [
                'days_ago' => 1,
                'status' => Parcel::STATUS_DELIVERED,
                'size' => Parcel::SIZE_SMALL,
                'declared_value' => 10000,
                'has_insurance' => false,
                'insurance_amount' => 0,
                'shipping_fee' => 1000,
                'recipient_name' => 'Touré Moussa',
                'recipient_phone' => '0707556677',
                'delivery_address' => 'Quartier Évêché, Daloa',
                'pickup_address' => 'Quartier Commerce, Daloa',
                'description' => 'Dossier de candidature et attestations',
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'locked' => false,
            ],
            [
                'days_ago' => 2,
                'status' => Parcel::STATUS_DELIVERED,
                'size' => Parcel::SIZE_LARGE,
                'declared_value' => 60000,
                'has_insurance' => true,
                'insurance_amount' => 1800,
                'shipping_fee' => 2500,
                'recipient_name' => 'Koné Mariam',
                'recipient_phone' => '0747113355',
                'delivery_address' => 'Gbeuliville, Daloa',
                'pickup_address' => 'Tazibouo 1, Daloa',
                'description' => 'Carton de tissus pagnes kita',
                'payment_method' => 'wave',
                'payment_status' => 'paid',
                'locked' => false,
            ],
            [
                'days_ago' => 2,
                'status' => Parcel::STATUS_CANCELLED,
                'size' => Parcel::SIZE_SMALL,
                'declared_value' => 5000,
                'has_insurance' => false,
                'insurance_amount' => 0,
                'shipping_fee' => 1000,
                'recipient_name' => 'Diallo Awa',
                'recipient_phone' => '0505224466',
                'delivery_address' => 'Kennedy 2, Daloa',
                'pickup_address' => 'Lobia, Daloa',
                'description' => 'Colis annulé par l\'expéditeur',
                'payment_method' => 'cash',
                'payment_status' => 'failed',
                'locked' => false,
            ],
            [
                'days_ago' => 3,
                'status' => Parcel::STATUS_DELIVERED,
                'size' => Parcel::SIZE_MEDIUM,
                'declared_value' => 20000,
                'has_insurance' => false,
                'insurance_amount' => 0,
                'shipping_fee' => 1500,
                'recipient_name' => 'Koffi Serge',
                'recipient_phone' => '0703889900',
                'delivery_address' => 'Commerce, Daloa',
                'pickup_address' => 'Quartier Marais, Daloa',
                'description' => 'Colis de fournitures de bureau',
                'payment_method' => 'orange_money',
                'payment_status' => 'paid',
                'locked' => false,
            ],
            [
                'days_ago' => 4,
                'status' => Parcel::STATUS_DELIVERED,
                'size' => Parcel::SIZE_SMALL,
                'declared_value' => 8000,
                'has_insurance' => false,
                'insurance_amount' => 0,
                'shipping_fee' => 1000,
                'recipient_name' => 'Yao Bernadette',
                'recipient_phone' => '0707441122',
                'delivery_address' => 'Tazibouo 2, Daloa',
                'pickup_address' => 'Kennedy, Daloa',
                'description' => 'Pochette cadeaux et bijoux fantaisie',
                'payment_method' => 'wave',
                'payment_status' => 'paid',
                'locked' => false,
            ],
            [
                'days_ago' => 5,
                'status' => Parcel::STATUS_DELIVERED,
                'size' => Parcel::SIZE_LARGE,
                'declared_value' => 100000,
                'has_insurance' => true,
                'insurance_amount' => 3000,
                'shipping_fee' => 2500,
                'recipient_name' => 'Fofana Lassina',
                'recipient_phone' => '0709663311',
                'delivery_address' => 'Gbeuliville, Daloa',
                'pickup_address' => 'Commerce, Daloa',
                'description' => 'Matériel informatique neuf en carton sécurisé',
                'payment_method' => 'mtn_momo',
                'payment_status' => 'paid',
                'locked' => false,
            ],
            [
                'days_ago' => 6,
                'status' => Parcel::STATUS_DELIVERED,
                'size' => Parcel::SIZE_MEDIUM,
                'declared_value' => 35000,
                'has_insurance' => true,
                'insurance_amount' => 1050,
                'shipping_fee' => 1500,
                'recipient_name' => 'N\'Dri Patrick',
                'recipient_phone' => '0701552288',
                'delivery_address' => 'Quartier Lobia, Daloa',
                'pickup_address' => 'Gbeuliville, Daloa',
                'description' => 'Vêtements de cérémonie',
                'payment_method' => 'cash',
                'payment_status' => 'paid',
                'locked' => false,
            ],
            [
                'days_ago' => 6,
                'status' => Parcel::STATUS_DELIVERED,
                'size' => Parcel::SIZE_SMALL,
                'declared_value' => 12000,
                'has_insurance' => false,
                'insurance_amount' => 0,
                'shipping_fee' => 1000,
                'recipient_name' => 'Coulibaly Alassane',
                'recipient_phone' => '0708994411',
                'delivery_address' => 'Quartier Gare, Daloa',
                'pickup_address' => 'Commerce, Daloa',
                'description' => 'Documents bancaires et chéquiers',
                'payment_method' => 'wave',
                'payment_status' => 'paid',
                'locked' => false,
            ],
        ];

        foreach ($parcelTemplates as $index => $t) {
            $sender = $clients[$index % $clients->count()];
            $courier = ($t['status'] === Parcel::STATUS_PENDING) ? null : $couriers[$index % $couriers->count()];

            $shippingFee = $t['shipping_fee'];
            $platformCommission = round($shippingFee * 0.25); // 25%
            $courierAmount = $shippingFee - $platformCommission; // 75%

            $createdAt = now()->subDays($t['days_ago'])->subHours(rand(1, 8));

            $parcel = Parcel::create([
                'sender_id' => $sender->id,
                'courier_id' => $courier?->id,
                'city_id' => $city->id,
                'status' => $t['status'],
                'size' => $t['size'],
                'weight_kg' => $t['size'] === Parcel::SIZE_SMALL ? 0.5 : ($t['size'] === Parcel::SIZE_MEDIUM ? 2.5 : 8.0),
                'declared_value' => $t['declared_value'],
                'has_insurance' => $t['has_insurance'],
                'insurance_amount' => $t['insurance_amount'],
                'sender_code' => Hash::make('1234'),
                'sender_code_attempts' => ($t['locked'] ?? false) ? 3 : 0,
                'sender_code_locked' => $t['locked'] ?? false,
                'recipient_code' => Hash::make('5678'),
                'recipient_code_attempts' => 0,
                'recipient_code_locked' => false,
                'recipient_name' => $t['recipient_name'],
                'recipient_phone' => $t['recipient_phone'],
                'delivery_address' => $t['delivery_address'],
                'delivery_lat' => 6.8790 + ($index * 0.001),
                'delivery_lng' => -6.4520 + ($index * 0.001),
                'pickup_address' => $t['pickup_address'],
                'pickup_lat' => 6.8760,
                'pickup_lng' => -6.4490,
                'description' => $t['description'],
                'shipping_fee' => $shippingFee,
                'platform_commission' => $platformCommission,
                'courier_amount' => $courierAmount,
                'payment_method' => $t['payment_method'],
                'payment_status' => $t['payment_status'],
                'payment_reference' => 'PAY-GOCOLIS-'.rand(100000, 999999),
                'delivered_at' => $t['status'] === Parcel::STATUS_DELIVERED ? $createdAt->copy()->addMinutes(35) : null,
                'cancelled_at' => $t['status'] === Parcel::STATUS_CANCELLED ? $createdAt->copy()->addMinutes(15) : null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            // Évaluation du livreur pour expéditions livrées
            if ($t['status'] === Parcel::STATUS_DELIVERED && $courier) {
                Rating::firstOrCreate(
                    [
                        'rater_id' => $sender->id,
                        'parcel_id' => $parcel->id,
                        'type' => 'gocolis_courier',
                    ],
                    [
                        'rateable_id' => $courier->id,
                        'rating' => 5,
                        'comment' => 'Colis récupéré et remis au destinataire avec un grand professionnalisme !',
                    ]
                );
            }
        }

        // ==========================================
        // 3. NOTIFICATIONS IN-APP POUR CLIENTS
        // ==========================================
        $sampleNotifications = [
            [
                'type' => 'order_delivered',
                'title' => 'Commande livrée 🎉',
                'body' => 'Votre commande GoShop a été livrée à bon port. Bon appétit !',
                'data' => ['screen' => 'order_history'],
                'is_read' => true,
            ],
            [
                'type' => 'gocolis_delivered',
                'title' => 'Colis réceptionné 📦',
                'body' => 'Votre colis a été remis en mains propres au destinataire avec succès.',
                'data' => ['screen' => 'gocolis_history'],
                'is_read' => false,
            ],
            [
                'type' => 'promo',
                'title' => 'Bienvenue à Daloa 🛵',
                'body' => 'Profitez de la livraison offerte sur votre prochaine course avec le code BIENVENUE !',
                'data' => ['promo_code' => 'BIENVENUE'],
                'is_read' => false,
            ],
        ];

        foreach ($clients->take(3) as $client) {
            foreach ($sampleNotifications as $notifData) {
                Notification::create([
                    'user_id' => $client->id,
                    'type' => $notifData['type'],
                    'title' => $notifData['title'],
                    'body' => $notifData['body'],
                    'data' => $notifData['data'],
                    'is_read' => $notifData['is_read'],
                    'read_at' => $notifData['is_read'] ? now()->subHours(1) : null,
                    'created_at' => now()->subHours(rand(1, 12)),
                ]);
            }
        }
    }
}

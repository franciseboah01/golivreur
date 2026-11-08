<?php

namespace Database\Seeders;

use App\Models\{City, Courier, Merchant, Order, OrderItem, Product, User, Wallet};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\{DB, Hash};

/**
 * Seeder de démonstration pour Daloa.
 * Génère des données réalistes pour tester GoLivreur immédiatement après installation.
 *
 * Exécution : php artisan db:seed
 * Ou spécifiquement : php artisan db:seed --class=DaloaSeeder
 */
class DaloaSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Ville de Daloa ──────────────────────────────────────────────────
        $daloa = City::create([
            'name'       => 'Daloa',
            'is_active'  => true,
            'center_lat' => 6.8770,
            'center_lng' => -6.4502,
        ]);

        $this->command->info('✅ Ville Daloa créée');

        // ── 2. Super Admin (Francis EBOAH) ─────────────────────────────────────
        $superAdmin = User::create([
            'name'               => 'Francis EBOAH',
            'phone'              => '0700000000',
            'email'              => 'admin@golivreur.ci',
            'password'           => Hash::make('GoLivreur2026!'),
            'role'               => 'super_admin',
            'city_id'            => null,
            'is_active'          => true,
            'phone_verified_at'  => now(),
        ]);

        $this->command->info('✅ Super Admin créé — Email: admin@golivreur.ci / MDP: GoLivreur2026!');
        $this->command->warn('⚠️  Changez ce mot de passe immédiatement après la première connexion !');

        // ── 3. Équipe opérationnelle Daloa ─────────────────────────────────────
        $cityManager = User::create([
            'name'              => 'Kouadio Serge',
            'phone'             => '0700000001',
            'email'             => 'manager.daloa@golivreur.ci',
            'password'          => Hash::make('ManagerDaloa2026!'),
            'role'              => 'city_manager',
            'city_id'           => $daloa->id,
            'is_active'         => true,
            'phone_verified_at' => now(),
        ]);

        $citySupport = User::create([
            'name'              => 'Bamba Fatoumata',
            'phone'             => '0700000002',
            'email'             => 'support.daloa@golivreur.ci',
            'password'          => Hash::make('SupportDaloa2026!'),
            'role'              => 'city_support',
            'city_id'           => $daloa->id,
            'is_active'         => true,
            'phone_verified_at' => now(),
        ]);

        $agents = [
            ['name' => 'Traoré Mamadou', 'phone' => '0700000003'],
            ['name' => 'Koné Aminata',   'phone' => '0700000004'],
        ];

        foreach ($agents as $agentData) {
            User::create([
                ...$agentData,
                'role'              => 'city_agent',
                'city_id'           => $daloa->id,
                'is_active'         => true,
                'phone_verified_at' => now(),
            ]);
        }

        $this->command->info('✅ Équipe opérationnelle Daloa créée (Manager + Support + 2 Agents)');

        // ── 4. Clients GoLivreur ───────────────────────────────────────────────
        $clientsData = [
            ['name' => 'Ama Kouassi',       'phone' => '0701000001'],
            ['name' => 'Koffi Jean-Marc',   'phone' => '0701000002'],
            ['name' => 'Soro Mariam',       'phone' => '0701000003'],
            ['name' => 'Diallo Ibrahim',    'phone' => '0701000004'],
            ['name' => 'Yao Clarisse',      'phone' => '0701000005'],
            ['name' => 'N\'Guessan Paul',   'phone' => '0701000006'],
            ['name' => 'Touré Adama',       'phone' => '0701000007'],
            ['name' => 'Brou Evelyne',      'phone' => '0701000008'],
            ['name' => 'Coulibaly Moussa',  'phone' => '0701000009'],
            ['name' => 'Konaté Fatima',     'phone' => '0701000010'],
            ['name' => 'Nouveau Client Test (Vide)', 'phone' => '0701000099'], // Pour tester l'état vide (0 commande, 0 colis)
        ];

        $clients = collect($clientsData)->map(fn ($data) => User::create([
            ...$data,
            'role'              => 'client',
            'city_id'           => $daloa->id,
            'is_active'         => true,
            'phone_verified_at' => now(),
        ]));

        $this->command->info("✅ {$clients->count()} clients créés");

        // ── 5. Commerces partenaires Daloa ─────────────────────────────────────
        $commercesData = [
            [
                'name' => 'Maquis Chez Tantie Adjoua',
                'category' => 'maquis',
                'address' => 'Quartier Commerce, Daloa',
                'lat' => 6.8821, 'lng' => -6.4478,
                'prep_time_minutes' => 20,
                'produits' => [
                    ['name' => 'Poulet braisé (quart)', 'price' => 1500, 'category' => 'Viandes'],
                    ['name' => 'Attiéké + Poisson braisé', 'price' => 1000, 'category' => 'Plats'],
                    ['name' => 'Alloco + Œuf', 'price' => 500, 'category' => 'Accompagnements', 'is_available' => true],
                    ['name' => 'Garba thon rouge (Épuisé)', 'price' => 800, 'category' => 'Plats', 'is_available' => false], // Cas limite rupture
                    ['name' => 'Jus de bissap (500ml)', 'price' => 300, 'category' => 'Boissons', 'is_available' => true],
                ],
            ],
            [
                'name' => 'Restaurant Le Carrefour',
                'category' => 'restaurant',
                'address' => 'Avenue Houphouët-Boigny, Daloa',
                'lat' => 6.8795, 'lng' => -6.4521,
                'prep_time_minutes' => 25,
                'is_open' => true,
                'produits' => [
                    ['name' => 'Riz sauce graine', 'price' => 1200, 'category' => 'Plats'],
                    ['name' => 'Foutou banane + Soupe', 'price' => 1500, 'category' => 'Plats'],
                    ['name' => 'Menu du jour', 'price' => 1000, 'category' => 'Menus'],
                    ['name' => 'Eau minérale (1,5L)', 'price' => 400, 'category' => 'Boissons'],
                    ['name' => 'Coca-Cola (33cl)', 'price' => 500, 'category' => 'Boissons'],
                ],
            ],
            [
                'name' => 'Fast Food Speed Burger Daloa',
                'category' => 'fast_food',
                'address' => 'Rue du Commerce, Daloa',
                'lat' => 6.8809, 'lng' => -6.4495,
                'prep_time_minutes' => 15,
                'is_open' => true,
                'produits' => [
                    ['name' => 'Burger Simple', 'price' => 1500, 'category' => 'Burgers'],
                    ['name' => 'Burger Double', 'price' => 2000, 'category' => 'Burgers'],
                    ['name' => 'Shawarma Poulet', 'price' => 1800, 'category' => 'Shawarmas'],
                    ['name' => 'Frites (portion)', 'price' => 500, 'category' => 'Accompagnements'],
                    ['name' => 'Pizza Margherita (part)', 'price' => 1200, 'category' => 'Pizzas'],
                ],
            ],
            [
                'name' => 'Pharmacie Centrale Daloa',
                'category' => 'pharmacie',
                'address' => 'Place de la Mairie, Daloa',
                'lat' => 6.8774, 'lng' => -6.4509,
                'prep_time_minutes' => 10,
                'is_open' => true,
                'produits' => [
                    ['name' => 'Paracétamol 500mg (boîte 16)', 'price' => 500, 'category' => 'Médicaments'],
                    ['name' => 'Ibuprofène 400mg (boîte 10)', 'price' => 800, 'category' => 'Médicaments'],
                    ['name' => 'Masque chirurgical (boîte 50)', 'price' => 2000, 'category' => 'Protection'],
                    ['name' => 'Crème hydratante (200ml)', 'price' => 1500, 'category' => 'Beauté'],
                    ['name' => 'Vitamine C (boîte 30)', 'price' => 1200, 'category' => 'Compléments'],
                ],
            ],
            [
                'name' => 'Boutique Chez Mamadou High-Tech',
                'category' => 'high_tech',
                'address' => 'Marché Central, Daloa',
                'lat' => 6.8832, 'lng' => -6.4467,
                'prep_time_minutes' => 5,
                'is_open' => true,
                'produits' => [
                    ['name' => 'Chargeur iPhone (Lightning)', 'price' => 2500, 'category' => 'Chargeurs'],
                    ['name' => 'Chargeur Android (USB-C)', 'price' => 2000, 'category' => 'Chargeurs'],
                    ['name' => 'Écouteurs filaires', 'price' => 1500, 'category' => 'Audio'],
                    ['name' => 'Coque téléphone universelle', 'price' => 1000, 'category' => 'Accessoires'],
                    ['name' => 'Powerbank 10000mAh', 'price' => 8000, 'category' => 'Batteries'],
                ],
            ],
            [
                'name' => 'Boulangerie La Centrale Daloa',
                'category' => 'boulangerie',
                'address' => 'Avenue Houphouët-Boigny, face Gare UTB, Daloa',
                'lat' => 6.8785, 'lng' => -6.4515,
                'prep_time_minutes' => 15,
                'is_open' => false, // Commerce actuellement FERMÉ (cas limite test UI)
                'produits' => [
                    ['name' => 'Baguette tradition', 'price' => 200, 'category' => 'Pains', 'is_available' => true],
                    ['name' => 'Croissant pur beurre (Rupture)', 'price' => 350, 'category' => 'Viennoiseries', 'is_available' => false],
                    ['name' => 'Pain au chocolat', 'price' => 400, 'category' => 'Viennoiseries', 'is_available' => true],
                    ['name' => 'Gâteau vanille 6 parts', 'price' => 6500, 'category' => 'Pâtisseries', 'is_available' => true],
                ],
            ],
        ];

        $merchantUsers = collect();

        foreach ($commercesData as $index => $commerceData) {
            $phoneIndex = str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
            $userMerchant = User::create([
                'name'              => $commerceData['name'],
                'phone'             => "0702{$phoneIndex}0001",
                'role'              => 'merchant',
                'city_id'           => $daloa->id,
                'is_active'         => true,
                'phone_verified_at' => now(),
            ]);

            $merchant = Merchant::create([
                'user_id'           => $userMerchant->id,
                'city_id'           => $daloa->id,
                'name'              => $commerceData['name'],
                'category'          => $commerceData['category'],
                'address'           => $commerceData['address'],
                'lat'               => $commerceData['lat'],
                'lng'               => $commerceData['lng'],
                'prep_time_minutes' => $commerceData['prep_time_minutes'],
                'is_open'           => $commerceData['is_open'] ?? true,
                'is_active'         => true,
                'activated_at'      => now(),
                'activated_by'      => $citySupport->id,
                'commission_rate'   => 15.00,
                'average_rating'    => round(3.8 + (mt_rand(0, 14) / 10), 1),
            ]);

            // Créer le wallet du merchant
            Wallet::create([
                'owner_type'      => 'merchant',
                'owner_id'        => $merchant->id,
                'balance'         => mt_rand(5000, 50000),
                'total_earned'    => mt_rand(50000, 500000),
                'total_withdrawn' => mt_rand(10000, 100000),
            ]);

            // Créer les produits du commerce
            foreach ($commerceData['produits'] as $sortOrder => $produit) {
                Product::create([
                    'merchant_id'  => $merchant->id,
                    'name'         => $produit['name'],
                    'price'        => $produit['price'],
                    'category'     => $produit['category'],
                    'is_available' => $produit['is_available'] ?? true,
                    'sort_order'   => $sortOrder,
                ]);
            }

            $merchantUsers->push(['user' => $userMerchant, 'merchant' => $merchant]);
        }

        $this->command->info("✅ {$merchantUsers->count()} commerces créés avec leurs catalogues");

        // ── 6. Livreurs actifs Daloa ───────────────────────────────────────────
        $livreursData = [
            ['name' => 'Ouattara Seydou',   'phone' => '0703000001', 'lat' => 6.8815, 'lng' => -6.4488],
            ['name' => 'Coulibaly Issouf',  'phone' => '0703000002', 'lat' => 6.8800, 'lng' => -6.4510],
            ['name' => 'Bamba Moussa',       'phone' => '0703000003', 'lat' => 6.8790, 'lng' => -6.4525],
            ['name' => 'Doumbia Lacina',     'phone' => '0703000004', 'lat' => 6.8840, 'lng' => -6.4460],
            ['name' => 'Koné Drissa',        'phone' => '0703000005', 'lat' => 6.8760, 'lng' => -6.4545],
        ];

        foreach ($livreursData as $livreurData) {
            $userDriver = User::create([
                'name'              => $livreurData['name'],
                'phone'             => $livreurData['phone'],
                'role'              => 'driver',
                'city_id'           => $daloa->id,
                'is_active'         => true,
                'phone_verified_at' => now(),
            ]);

            $courier = Courier::create([
                'user_id'          => $userDriver->id,
                'city_id'          => $daloa->id,
                'is_online'        => true,
                'current_lat'      => $livreurData['lat'],
                'current_lng'      => $livreurData['lng'],
                'vehicle_type'     => 'moto',
                'is_active'        => true,
                'activated_at'     => now(),
                'activated_by'     => $citySupport->id,
                'average_rating'   => round(3.9 + (mt_rand(0, 9) / 10), 1),
                'total_ratings'    => mt_rand(10, 80),
            ]);

            // Wallet livreur
            Wallet::create([
                'owner_type'      => 'courier',
                'owner_id'        => $courier->id,
                'balance'         => mt_rand(2000, 20000),
                'total_earned'    => mt_rand(20000, 200000),
                'total_withdrawn' => mt_rand(5000, 50000),
            ]);
        }

        $countLivreurs = count($livreursData);
        $this->command->info("✅ {$countLivreurs} livreurs créés et en ligne");

        $this->command->newLine();
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('  🎉 GoLivreur Daloa — Base de données initialisée !');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('  Admin  : admin@golivreur.ci / GoLivreur2026!');
        $this->command->info('  API    : http://localhost:8000/api/v1/health');
        $this->command->info('  Docs   : http://localhost:8000/docs');
        $this->command->info('═══════════════════════════════════════════════════════');
    }
}

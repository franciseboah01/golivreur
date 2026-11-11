<?php

namespace Database\Seeders;

use App\Models\RoleHabilitation;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleHabilitationSeeder extends Seeder
{
    public function run(): void
    {
        $allKeys = RoleHabilitation::getAllOngletKeys();

        // 1. Super Administrateur (Tous les onglets)
        $superAdminRole = RoleHabilitation::updateOrCreate(
            ['slug' => 'super_admin'],
            [
                'name' => 'Super Administrateur',
                'description' => 'Accès complet et illimité à l\'ensemble des onglets, configurations et actions de la plateforme.',
                'onglets' => $allKeys,
                'is_system' => true,
            ]
        );

        // 2. Responsable Finance National
        $financeRole = RoleHabilitation::updateOrCreate(
            ['slug' => 'national_finance'],
            [
                'name' => 'Responsable Finance National',
                'description' => 'Supervision des flux financiers, transactions wallets, remboursements et journaux d\'audit.',
                'onglets' => ['dashboard', 'finances', 'refunds', 'activity_logs', 'webhook_logs'],
                'is_system' => true,
            ]
        );

        // 3. Admin Limité
        $limitedRole = RoleHabilitation::updateOrCreate(
            ['slug' => 'admin_limite'],
            [
                'name' => 'Administrateur Limité (Opérations terrain)',
                'description' => 'Accès restreint au suivi opérationnel direct sans accès aux données financières ni paramètres.',
                'onglets' => ['dashboard', 'orders', 'parcels', 'couriers'],
                'is_system' => false,
            ]
        );

        // 4. City Manager
        $cityManagerRole = RoleHabilitation::updateOrCreate(
            ['slug' => 'city_manager'],
            [
                'name' => 'City Manager (Gestionnaire de Ville)',
                'description' => 'Gestion des opérations GoShop, GoColis, partenaires, validations, litiges et gestionnaires locaux.',
                'onglets' => [
                    'dashboard', 'orders', 'parcels', 'merchants', 'couriers',
                    'clients', 'pending_validations', 'disputes', 'emergency_center',
                    'administrators', 'cities',
                ],
                'is_system' => true,
            ]
        );

        // 5. Support Opérations & Litiges
        $supportRole = RoleHabilitation::updateOrCreate(
            ['slug' => 'city_support'],
            [
                'name' => 'Support Opérations & Déblocages',
                'description' => 'Résolution des incidents de livraison, validation de preuves et déblocage de codes.',
                'onglets' => [
                    'dashboard', 'orders', 'parcels', 'merchants', 'couriers',
                    'clients', 'pending_validations', 'disputes', 'refunds',
                ],
                'is_system' => true,
            ]
        );

        // 6. Agent Terrain Ville
        $agentRole = RoleHabilitation::updateOrCreate(
            ['slug' => 'city_agent'],
            [
                'name' => 'Agent Terrain Ville',
                'description' => 'Vérification physique des commerces et livreurs candidats à l\'activation.',
                'onglets' => ['dashboard', 'merchants', 'couriers', 'pending_validations'],
                'is_system' => true,
            ]
        );

        // ─── Synchronisation des utilisateurs existants ───────────────────────────

        // Super Admin users
        User::whereIn('email', ['admin@golivreur.ci', 'superadmin@golivreur.ci'])
            ->orWhere('role', 'super_admin')
            ->update(['role_habilitation_id' => $superAdminRole->id]);

        // City Managers
        User::where('role', 'city_manager')
            ->update(['role_habilitation_id' => $cityManagerRole->id]);

        // Support
        User::where('role', 'city_support')
            ->orWhere('role', 'national_support')
            ->update(['role_habilitation_id' => $supportRole->id]);

        // Agents
        User::where('role', 'city_agent')
            ->update(['role_habilitation_id' => $agentRole->id]);

        // Any other admin users without role_habilitation_id
        User::whereIn('role', User::ADMIN_ROLES)
            ->whereNull('role_habilitation_id')
            ->update(['role_habilitation_id' => $limitedRole->id]);
    }
}

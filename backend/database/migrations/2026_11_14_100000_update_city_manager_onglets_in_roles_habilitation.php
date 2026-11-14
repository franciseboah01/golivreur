<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cityManager = DB::table('roles_habilitation')->where('slug', 'city_manager')->first();
        if ($cityManager) {
            $onglets = json_decode($cityManager->onglets ?? '[]', true) ?: [];
            $onglets = array_values(array_diff($onglets, ['emergency_center']));
            if (! in_array('refunds', $onglets, true)) {
                $onglets[] = 'refunds';
            }
            DB::table('roles_habilitation')->where('slug', 'city_manager')->update([
                'onglets' => json_encode($onglets),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $cityManager = DB::table('roles_habilitation')->where('slug', 'city_manager')->first();
        if ($cityManager) {
            $onglets = json_decode($cityManager->onglets ?? '[]', true) ?: [];
            if (! in_array('emergency_center', $onglets, true)) {
                $onglets[] = 'emergency_center';
            }
            $onglets = array_values(array_diff($onglets, ['refunds']));
            DB::table('roles_habilitation')->where('slug', 'city_manager')->update([
                'onglets' => json_encode($onglets),
                'updated_at' => now(),
            ]);
        }
    }
};

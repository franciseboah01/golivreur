<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ajoute les champs de profil modifiables depuis mobile & admin :
     * - `users` : `photo_path`, `default_address`, `language`, `notifications_enabled`
     * - `couriers` : `driver_license_number`
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'photo_path')) {
                $table->string('photo_path')->nullable()->after('avatar_url');
            }
            if (!Schema::hasColumn('users', 'default_address')) {
                $table->string('default_address')->nullable()->after('city_id');
            }
            if (!Schema::hasColumn('users', 'language')) {
                $table->string('language', 10)->default('fr')->after('default_address');
            }
            if (!Schema::hasColumn('users', 'notifications_enabled')) {
                $table->boolean('notifications_enabled')->default(true)->after('language');
            }
        });

        Schema::table('couriers', function (Blueprint $table) {
            if (!Schema::hasColumn('couriers', 'driver_license_number')) {
                $table->string('driver_license_number', 50)->nullable()->after('id_card_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $cols = array_filter([
                Schema::hasColumn('users', 'photo_path') ? 'photo_path' : null,
                Schema::hasColumn('users', 'default_address') ? 'default_address' : null,
                Schema::hasColumn('users', 'language') ? 'language' : null,
                Schema::hasColumn('users', 'notifications_enabled') ? 'notifications_enabled' : null,
            ]);
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::table('couriers', function (Blueprint $table) {
            if (Schema::hasColumn('couriers', 'driver_license_number')) {
                $table->dropColumn('driver_license_number');
            }
        });
    }
};

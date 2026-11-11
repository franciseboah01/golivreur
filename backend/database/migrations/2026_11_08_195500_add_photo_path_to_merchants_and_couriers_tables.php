<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (! Schema::hasColumn('merchants', 'photo_path')) {
                $table->string('photo_path')->nullable()->after('address');
            }
        });

        Schema::table('couriers', function (Blueprint $table) {
            if (! Schema::hasColumn('couriers', 'photo_path')) {
                $table->string('photo_path')->nullable()->after('photo_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (Schema::hasColumn('merchants', 'photo_path')) {
                $table->dropColumn('photo_path');
            }
        });

        Schema::table('couriers', function (Blueprint $table) {
            if (Schema::hasColumn('couriers', 'photo_path')) {
                $table->dropColumn('photo_path');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->text('confirmation_code_enc')->nullable()->after('confirmation_code');
        });

        Schema::table('parcels', function (Blueprint $table): void {
            $table->text('sender_code_enc')->nullable()->after('sender_code');
        });
    }

    public function down(): void
    {
        Schema::table('parcels', function (Blueprint $table): void {
            $table->dropColumn('sender_code_enc');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('confirmation_code_enc');
        });
    }
};

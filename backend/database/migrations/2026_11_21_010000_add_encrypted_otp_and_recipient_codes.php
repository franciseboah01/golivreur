<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('otp_codes', 'code_enc')) {
            Schema::table('otp_codes', function (Blueprint $table): void {
                $table->text('code_enc')->nullable()->after('code_hash');
            });
        }

        if (! Schema::hasColumn('parcels', 'recipient_code_enc')) {
            Schema::table('parcels', function (Blueprint $table): void {
                $table->text('recipient_code_enc')->nullable()->after('recipient_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('parcels', 'recipient_code_enc')) {
            Schema::table('parcels', function (Blueprint $table): void {
                $table->dropColumn('recipient_code_enc');
            });
        }

        if (Schema::hasColumn('otp_codes', 'code_enc')) {
            Schema::table('otp_codes', function (Blueprint $table): void {
                $table->dropColumn('code_enc');
            });
        }
    }
};

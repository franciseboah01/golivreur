<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parcels', function (Blueprint $table) {
            $table->boolean('sms_failed')->default(false)->index()->after('recipient_code_locked');
            $table->timestamp('sms_failed_at')->nullable()->after('sms_failed');
        });
    }

    public function down(): void
    {
        Schema::table('parcels', function (Blueprint $table) {
            $table->dropColumn(['sms_failed', 'sms_failed_at']);
        });
    }
};

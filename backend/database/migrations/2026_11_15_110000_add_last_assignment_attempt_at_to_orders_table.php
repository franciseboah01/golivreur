<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('orders', 'last_assignment_attempt_at')) {
                $table->timestamp('last_assignment_attempt_at')->nullable()->after('assignment_attempts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            if (Schema::hasColumn('orders', 'last_assignment_attempt_at')) {
                $table->dropColumn('last_assignment_attempt_at');
            }
        });
    }
};


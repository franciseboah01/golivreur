<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Évaluations clients.
 * Dépend de : users (rater_id), orders, parcels
 *
 * Types :
 *   goshop_merchant  : client note le commerce après livraison GoShop
 *   goshop_courier   : client note le livreur après livraison GoShop
 *   gocolis_courier  : expéditeur note le livreur après livraison GoColis
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rater_id')
                ->constrained('users')
                ->restrictOnDelete();
            $table->string('type', 30); // goshop_merchant | goshop_courier | gocolis_courier
            // ID de l'entité notée (merchant.id ou courier.id selon le type)
            $table->unsignedBigInteger('rateable_id');
            $table->unsignedTinyInteger('rating'); // 1 à 5
            $table->text('comment')->nullable();
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete();
            $table->foreignId('parcel_id')
                ->nullable()
                ->constrained('parcels')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'rateable_id']);
            $table->index('rater_id');
            // Un client ne peut noter qu'une fois par commande/expédition
            $table->unique(['rater_id', 'order_id', 'type']);
            $table->unique(['rater_id', 'parcel_id', 'type']);
        });

        // ─────────────────────────────────────────────────────────────────────

        /**
         * Notifications in-app — clients uniquement.
         * Les 30 dernières notifications sont affichées dans le centre de notifications.
         * Dépend de : users
         */
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            // Type : order_accepted | order_delivered | promo | system | gocolis_delivered
            $table->string('type', 60);
            $table->string('title', 255);
            $table->text('body');
            // Données contextuelles JSON (ex: {"order_id": 42, "route": "/orders/42"})
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_read']);
            $table->index(['user_id', 'created_at']);
        });

        // ─────────────────────────────────────────────────────────────────────

        /**
         * Logs des webhooks Hub2/CinetPay.
         * Aucune dépendance FK — table autonome pour la traçabilité.
         *
         * Chaque webhook est loggé AVANT tout traitement.
         * Permet le rejeu depuis Horizon en cas d'échec du job.
         * Un webhook avec signature_valid=false ne génère AUCUN traitement.
         */
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('source', 50)->default('hub2'); // hub2 | cinetpay
            $table->string('event_type', 100); // payment.success | payment.failed
            $table->json('payload'); // Payload complet reçu
            $table->string('signature_received', 500)->nullable();
            $table->boolean('signature_valid')->default(false);
            // Statut traitement : pending | processed | failed | rejected
            $table->string('status', 30)->default('pending');
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            // ID du job Horizon associé (pour le rejeu)
            $table->string('horizon_job_id', 100)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'status']);
            $table->index(['signature_valid', 'status']);
            $table->index('created_at');
        });

        // ─────────────────────────────────────────────────────────────────────

        /**
         * Sessions des administrateurs — IP tracking.
         * Dépend de : users
         *
         * Une nouvelle connexion admin depuis une IP différente génère :
         *   - Un log warning dans activity_logs
         *   - Un email de notification à l'admin concerné
         */
        Schema::create('admin_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('ip_address', 45);
            $table->string('device_info', 500)->nullable(); // User-Agent navigateur
            $table->string('session_token', 255)->unique();
            $table->timestamp('last_activity');
            $table->boolean('is_active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index('session_token');
        });

        // ─────────────────────────────────────────────────────────────────────

        /**
         * Journal d'audit — traçabilité totale des actions sensibles.
         * Dépend de : users (user_id nullable — actions système sans acteur humain)
         *
         * PAS de soft delete : audit immuable.
         * Les montants financiers sont visibles ICI mais pas dans les logs système.
         * Niveaux de sévérité : info | warning | critical
         */
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            // Action : order_created | refund_validated | admin_new_ip_login | invalid_webhook_signature ...
            $table->string('action', 100);
            // Entité concernée (ex: 'order', 'parcel', 'user', 'merchant')
            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            // Données contextuelles — montants financiers visibles uniquement ici
            $table->json('properties')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('severity', 20)->default('info'); // info | warning | critical
            $table->timestamps();

            $table->index(['action', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['severity', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('admin_sessions');
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('ratings');
    }
};

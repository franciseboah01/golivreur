<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\WebhookLog;
use App\Services\ActivityLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Reçoit et valide les webhooks Hub2/CinetPay (confirmation paiement Mobile Money).
 * Règle absolue : AUCUN traitement sans validation HMAC réussie.
 * Chaque webhook est loggé AVANT tout traitement.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    /**
     * Point d'entrée des webhooks Hub2/CinetPay.
     * POST /api/v1/webhooks/payment
     */
    public function paiement(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        [$source, $signatureRecue, $secret] = $this->resolveWebhookProvider($request);

        // 1. Logger le webhook IMMÉDIATEMENT (avant toute validation)
        $webhookLog = WebhookLog::create([
            'source' => $source,
            'event_type' => $request->input('event') ?? 'payment',
            'payload' => $request->all(),
            'signature_received' => $signatureRecue,
            'signature_valid' => false, // Sera mis à jour si valide
            'status' => 'pending',
        ]);

        // 2. Valider la signature HMAC avec le secret propre au fournisseur
        $signatureCalculee = $secret !== ''
            ? hash_hmac('sha256', $payload, $secret)
            : '';

        $signatureValide = $secret !== ''
            && $signatureRecue !== ''
            && hash_equals($signatureCalculee, $signatureRecue);

        if (! $signatureValide) {
            // Signature invalide → rejet total + alerte critique
            $webhookLog->update([
                'signature_valid' => false,
                'status' => 'rejected',
                'error_message' => 'Signature HMAC invalide.',
            ]);

            $this->activityLogService->log(
                userId: null,
                action: 'invalid_webhook_signature',
                entityType: 'webhook_log',
                entityId: $webhookLog->id,
                properties: [
                    'source' => $source,
                    'ip' => $request->ip(),
                    'expected' => substr($signatureCalculee, 0, 8).'...',
                    'received' => substr($signatureRecue, 0, 8).'...',
                ],
                severity: 'critical',
            );

            // Alerte email immédiate au Fondateur
            $this->envoyerAlerteSignatureInvalide($request, $webhookLog);

            return response()->json([
                'succes' => false,
                'message' => 'Signature invalide.',
            ], 401);
        }

        // 3. Signature valide → marquer et dispatcher le job haute priorité
        $webhookLog->update(['signature_valid' => true]);

        ProcessPaymentWebhook::dispatch($webhookLog->id, $request->all())
            ->onQueue('high');

        // 4. Répondre immédiatement à Hub2 (ne pas les faire attendre)
        return response()->json([
            'succes' => true,
            'message' => 'Webhook reçu.',
        ], 200);
    }

    /**
     * Rejoue manuellement un webhook depuis Horizon (admin uniquement).
     * POST /api/v1/admin/webhooks/{id}/replay
     */
    public function rejouer(int $webhookLogId): JsonResponse
    {
        $user = auth()->user();

        if (! $user || ! in_array($user->role, ['super_admin', 'national_tech'], true)) {
            return response()->json([
                'succes' => false,
                'message' => 'Action réservée au Super Admin ou Tech National.',
            ], 403);
        }

        $webhookLog = WebhookLog::findOrFail($webhookLogId);

        if (! $webhookLog->signature_valid) {
            return response()->json([
                'succes' => false,
                'message' => 'Ce webhook a une signature invalide — rejoue impossible.',
            ], 422);
        }

        if ($webhookLog->status === 'processed') {
            return response()->json([
                'succes' => false,
                'message' => 'Ce webhook a déjà été traité avec succès.',
            ], 409);
        }

        $webhookLog->increment('retry_count');
        $webhookLog->update(['status' => 'pending']);

        ProcessPaymentWebhook::dispatch($webhookLog->id, $webhookLog->payload)
            ->onQueue('high');

        $this->activityLogService->log(
            userId: auth()->id(),
            action: 'webhook_replayed',
            entityType: 'webhook_log',
            entityId: $webhookLog->id,
        );

        return response()->json([
            'succes' => true,
            'message' => "Webhook #{$webhookLogId} mis en queue pour rejeu.",
        ]);
    }

    // ─── Méthodes privées ─────────────────────────────────────────────────────

    private function envoyerAlerteSignatureInvalide(Request $request, WebhookLog $log): void
    {
        $emailAdmin = config('app.admin_alert_email', 'francis@golivreur.ci');

        $horizonUrl = rtrim(config('app.url', 'https://golivreur.ci'), '/').'/horizon';
        try {
            Mail::raw(
                "⚠️ ALERTE SÉCURITÉ — Webhook signature invalide\n\n"
                .'Date : '.now()->format('d/m/Y H:i:s')."\n"
                .'IP source : '.$request->ip()."\n"
                .'Source déclarée : '.($request->header('X-Hub2-Signature') ? 'Hub2' : 'CinetPay')."\n"
                ."Webhook Log ID : #{$log->id}\n\n"
                ."Action : Webhook rejeté automatiquement. Aucun traitement effectué.\n"
                ."Vérifiez dans le dashboard Horizon : {$horizonUrl}",
                fn ($mail) => $mail
                    ->to($emailAdmin)
                    ->subject('🚨 GoLivreur — Webhook signature invalide détecté'),
            );
        } catch (\Exception $e) {
            Log::critical('Impossible d\'envoyer l\'alerte webhook invalide', [
                'error' => $e->getMessage(),
                'webhook_log' => $log->id,
            ]);
        }
    }

    /**
     * Détermine le fournisseur, l'en-tête de signature et le secret associé.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function resolveWebhookProvider(Request $request): array
    {
        $hub2Sig = $request->header('X-Hub2-Signature');
        if ($hub2Sig !== null) {
            return [
                'hub2',
                (string) $hub2Sig,
                (string) config('services.hub2.webhook_secret', ''),
            ];
        }

        $cinetpaySig = $request->header('X-CinetPay-Signature') ?? $request->header('x-token');
        if ($cinetpaySig !== null) {
            return [
                'cinetpay',
                (string) $cinetpaySig,
                (string) config('services.cinetpay.secret_key', ''),
            ];
        }

        $fallbackSource = $request->has('cpm_site_id') ? 'cinetpay' : 'hub2';

        return [$fallbackSource, '', ''];
    }
}

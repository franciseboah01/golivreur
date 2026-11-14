<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\ActivityLogService;
use App\Services\WebhookReplayService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Reçoit et valide les webhooks Hub2/CinetPay (confirmation paiement Mobile Money).
 * Règle absolue : AUCUN traitement sans validation HMAC réussie.
 * Chaque webhook est loggé AVANT tout traitement.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $activityLogService,
        private readonly WebhookReplayService $webhookReplayService,
    ) {}

    /**
     * Point d'entrée des webhooks Hub2/CinetPay.
     * POST /api/v1/webhooks/payment
     */
    public function paiement(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $payloadData = $request->all();
        [$source, $signatureRecue, $secret] = $this->resolveWebhookProvider($request);
        $transactionId = $this->extractTransactionId($payloadData);

        // 1. Logger le webhook IMMÉDIATEMENT (avant toute validation)
        $webhookLog = WebhookLog::create([
            'source' => $source,
            'event_type' => $request->input('event') ?? 'payment',
            'transaction_id' => $transactionId,
            'payload' => $payloadData,
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

        // 3. Fenêtre anti-rejeu temporelle (horodatage <= 5 minutes)
        $rawTimestamp = $this->extractWebhookTimestamp($request, $payloadData);
        if ($rawTimestamp !== null && $rawTimestamp !== '') {
            $parsedTimestamp = $this->parseWebhookTimestamp($rawTimestamp);
            if (! $parsedTimestamp || abs(now()->getTimestamp() - $parsedTimestamp->getTimestamp()) > 300) {
                $webhookLog->update([
                    'signature_valid' => true,
                    'status' => 'rejected',
                    'error_message' => 'Horodatage invalide ou expiré (fenêtre anti-rejeu de 5 minutes dépassée).',
                ]);

                $this->activityLogService->log(
                    userId: null,
                    action: 'webhook_timestamp_rejected',
                    entityType: 'webhook_log',
                    entityId: $webhookLog->id,
                    properties: [
                        'source' => $source,
                        'transaction_id' => $transactionId,
                        'raw_timestamp' => $rawTimestamp,
                        'ip' => $request->ip(),
                    ],
                    severity: 'warning',
                );

                return response()->json([
                    'succes' => false,
                    'message' => 'Horodatage du webhook expiré (fenêtre anti-rejeu de 5 minutes dépassée).',
                ], 422);
            }
        }

        // 4. Fenêtre anti-rejeu unicité transaction_id (pré-vérification + contrainte unique PG)
        if ($transactionId !== null && $this->transactionDejaTraitee($transactionId, $webhookLog->id)) {
            return $this->rejeterRejeuTransaction($webhookLog, $source, $transactionId, $request);
        }

        try {
            $webhookLog->update(['signature_valid' => true]);
        } catch (UniqueConstraintViolationException) {
            return $this->rejeterRejeuTransaction($webhookLog, $source, (string) $transactionId, $request);
        }

        ProcessPaymentWebhook::dispatch($webhookLog->id, $payloadData)
            ->onQueue('high');

        // 5. Répondre immédiatement à Hub2/CinetPay (ne pas les faire attendre)
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
        /** @var User|null $user */
        $user = auth()->user();

        if (! WebhookReplayService::canReplay($user)) {
            return response()->json([
                'succes' => false,
                'message' => 'Action réservée au Super Admin ou Tech National.',
            ], 403);
        }

        $webhookLog = WebhookLog::findOrFail($webhookLogId);

        try {
            $this->webhookReplayService->replay($user, $webhookLog);
        } catch (HttpExceptionInterface $e) {
            return response()->json([
                'succes' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

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

    private function extractTransactionId(array $payloadData): ?string
    {
        $raw = $payloadData['transaction_id']
            ?? ($payloadData['data']['transaction_id'] ?? null)
            ?? ($payloadData['cpm_trans_id'] ?? null);

        if (! is_scalar($raw)) {
            return null;
        }

        $normalized = trim((string) $raw);

        return $normalized !== '' ? $normalized : null;
    }

    private function extractWebhookTimestamp(Request $request, array $payloadData): mixed
    {
        return $request->header('X-Hub2-Timestamp')
            ?? $request->header('X-Webhook-Timestamp')
            ?? $request->header('X-Timestamp')
            ?? $request->header('X-CinetPay-Timestamp')
            ?? ($payloadData['timestamp'] ?? null)
            ?? ($payloadData['created_at'] ?? null)
            ?? ($payloadData['createdAt'] ?? null)
            ?? ($payloadData['sent_at'] ?? null)
            ?? ($payloadData['cpm_trans_date'] ?? null)
            ?? ($payloadData['data']['timestamp'] ?? null)
            ?? ($payloadData['data']['created_at'] ?? null);
    }

    private function parseWebhookTimestamp(mixed $raw): ?Carbon
    {
        if (is_int($raw) || (is_string($raw) && ctype_digit(trim($raw)))) {
            $num = (int) trim((string) $raw);

            return $num > 9999999999
                ? Carbon::createFromTimestampMs($num)
                : Carbon::createFromTimestamp($num);
        }

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($raw));
        } catch (\Throwable) {
            return null;
        }
    }

    private function transactionDejaTraitee(string $transactionId, int $excludeWebhookLogId): bool
    {
        return WebhookLog::query()
            ->where('id', '!=', $excludeWebhookLogId)
            ->where('transaction_id', $transactionId)
            ->where('signature_valid', true)
            ->whereIn('status', ['pending', 'processed'])
            ->exists();
    }

    private function rejeterRejeuTransaction(
        WebhookLog $webhookLog,
        string $source,
        string $transactionId,
        Request $request,
    ): JsonResponse {
        $webhookLog->update([
            'signature_valid' => true,
            'status' => 'rejected',
            'error_message' => "Rejeu bloqué : transaction {$transactionId} déjà traitée.",
        ]);

        $this->activityLogService->log(
            userId: null,
            action: 'webhook_replay_blocked',
            entityType: 'webhook_log',
            entityId: $webhookLog->id,
            properties: [
                'source' => $source,
                'transaction_id' => $transactionId,
                'ip' => $request->ip(),
            ],
            severity: 'warning',
        );

        return response()->json([
            'succes' => false,
            'message' => 'Transaction déjà traitée (rejeu bloqué).',
        ], 409);
    }
}

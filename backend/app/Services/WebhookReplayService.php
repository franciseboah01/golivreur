<?php

namespace App\Services;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\User;
use App\Models\WebhookLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Service partagé pour le rejeu sécurisé des webhooks (API Admin & Filament).
 */
class WebhookReplayService
{
    public const ALLOWED_ROLES = ['super_admin', 'national_tech'];

    public function __construct(
        private readonly ActivityLogService $activityLogService,
    ) {}

    public static function canReplay(?User $user): bool
    {
        return $user !== null && in_array($user->role, self::ALLOWED_ROLES, true);
    }

    /**
     * Valide les droits, bloque les doublons/signatures invalides et dispatche ProcessPaymentWebhook.
     */
    public function replay(?User $actor, WebhookLog $webhookLog): void
    {
        if (! self::canReplay($actor)) {
            throw new HttpException(403, 'Action réservée au Super Admin ou Tech National.');
        }

        if (! $webhookLog->signature_valid) {
            throw new HttpException(422, 'Ce webhook a une signature invalide — rejeu impossible.');
        }

        if ($webhookLog->status === 'processed') {
            throw new HttpException(409, 'Ce webhook a déjà été traité avec succès.');
        }

        $txId = $webhookLog->transaction_id ?? $this->extractTransactionId($webhookLog->payload ?? []);

        if ($txId !== null && WebhookLog::query()
            ->where('id', '!=', $webhookLog->id)
            ->where('transaction_id', $txId)
            ->where('signature_valid', true)
            ->whereIn('status', ['pending', 'processed'])
            ->exists()) {
            throw new HttpException(409, 'Cette transaction a déjà été traitée par un autre webhook.');
        }

        try {
            $webhookLog->increment('retry_count');
            $webhookLog->update([
                'status' => 'pending',
                'error_message' => null,
                'transaction_id' => $webhookLog->transaction_id ?? $txId,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new HttpException(409, 'Cette transaction a déjà été traitée par un autre webhook.');
        }

        ProcessPaymentWebhook::dispatch($webhookLog->id, $webhookLog->payload ?? [])
            ->onQueue('high');

        $this->activityLogService->log(
            userId: $actor?->id,
            action: 'webhook_replayed',
            entityType: 'webhook_log',
            entityId: $webhookLog->id,
            properties: [
                'retry_count' => $webhookLog->fresh()?->retry_count,
                'transaction_id' => $txId,
            ],
        );
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
}


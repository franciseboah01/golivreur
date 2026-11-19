<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Log;

/**
 * Journalisation immuable (append-only) des opérations du Service Client et de Sécurité.
 *
 * - Enregistre systématiquement dans `activity_logs` (immuable) ET dans le canal fichier `support_audit`.
 * - Contient toujours : advisor_id, target_user_id, action, occurred_at, result (success|failure).
 * - Filtre strictement toute donnée sensible (mots de passe, PIN, tokens, OTP, secrets) avant écriture.
 */
class SupportAuditService
{
    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|pin|token|secret|otp|code_verifier|authorization|credential|hash)/i';

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        ?int $advisorId,
        ?int $targetUserId,
        string $action,
        string $result,
        array $context = [],
        ?string $ipAddress = null,
        string $severity = 'info',
    ): ActivityLog {
        $normalizedResult = strtolower($result) === 'success' ? 'success' : 'failure';
        $occurredAt = now()->toIso8601String();
        $safeContext = $this->sanitizeProperties($context);

        $payload = [
            'advisor_id' => $advisorId,
            'target_user_id' => $targetUserId,
            'action' => $action,
            'occurred_at' => $occurredAt,
            'result' => $normalizedResult,
            'context' => $safeContext,
        ];

        $logEntry = ActivityLog::create([
            'user_id' => $advisorId,
            'action' => $action,
            'entity_type' => 'user',
            'entity_id' => $targetUserId,
            'properties' => $payload,
            'ip_address' => $ipAddress ?? request()->ip(),
            'severity' => $normalizedResult === 'failure' && $severity === 'info' ? 'warning' : $severity,
        ]);

        try {
            Log::channel('support_audit')->info('SUPPORT_AUDIT', [
                'audit_id' => $logEntry->id,
                'advisor_id' => $advisorId,
                'target_user_id' => $targetUserId,
                'action' => $action,
                'occurred_at' => $occurredAt,
                'result' => $normalizedResult,
                'ip_address' => $logEntry->ip_address,
                'context' => $safeContext,
            ]);
        } catch (\Throwable) {
            // Ne jamais interrompre le flux si le système de fichiers est temporairement indisponible
        }

        return $logEntry;
    }

    /**
     * Supprime ou masque récursivement toute clé susceptible de contenir un secret.
     *
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    public function sanitizeProperties(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeProperties($value);
            } elseif (is_scalar($value) || $value === null) {
                $sanitized[$key] = $value;
            } else {
                $sanitized[$key] = (string) $value;
            }
        }

        return $sanitized;
    }
}

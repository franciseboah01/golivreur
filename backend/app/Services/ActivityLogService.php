<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Log;

/**
 * Enregistre toutes les actions sensibles dans activity_logs.
 * Les montants financiers sont visibles ici mais PAS dans les logs système.
 * Sévérités : info | warning | critical
 */
class ActivityLogService
{
    public function log(
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $properties = [],
        string $severity = 'info',
    ): ActivityLog {
        return ActivityLog::create([
            'user_id'     => $userId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'properties'  => $properties ?: null,
            'ip_address'  => request()->ip(),
            'severity'    => $severity,
        ]);
    }

    /**
     * Version statique pour les Jobs (sans injection de dépendance).
     */
    public static function logStatic(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        array $properties = [],
        string $severity = 'info',
    ): void {
        try {
            ActivityLog::create([
                'user_id'     => null,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'properties'  => $properties ?: null,
                'ip_address'  => null,
                'severity'    => $severity,
            ]);
        } catch (\Exception $e) {
            Log::error("ActivityLogService::logStatic échoué : {$e->getMessage()}");
        }
    }
}

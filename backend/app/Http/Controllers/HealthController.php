<?php

namespace App\Http\Controllers;

use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function show(Request $request, HealthCheckService $healthCheckService): JsonResponse
    {
        $report = $healthCheckService->evaluate();
        $httpStatus = $report['status'] === 'ok' ? 200 : 503;

        $expectedToken = trim((string) config('services.health.token', ''));
        $providedToken = trim((string) (
            $request->header('HEALTH_TOKEN')
            ?? $request->header('X-Health-Token')
            ?? ''
        ));

        $authorized = $expectedToken !== ''
            && $providedToken !== ''
            && hash_equals($expectedToken, $providedToken);

        if (! $authorized) {
            return response()->json([
                'status' => $report['status'],
            ], $httpStatus);
        }

        return response()->json($report, $httpStatus);
    }
}

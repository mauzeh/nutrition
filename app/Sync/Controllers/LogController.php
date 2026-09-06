<?php

namespace App\Sync\Controllers;

use App\Models\LiftLog;
use App\Sync\Actions\DeleteSyncLogAction;
use App\Sync\Actions\StoreSyncLogAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogController
{
    public function __construct(
        private StoreSyncLogAction $storeSyncLogAction,
        private DeleteSyncLogAction $deleteSyncLogAction,
    ) {}

    /**
     * Store a synced completion log.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'exercise_name' => 'required|string',
            'canonical_name' => 'nullable|string',
            'date' => 'required|date',
            'log_type' => 'required|string',
            'weight_unit' => 'required|string',
            'sets' => 'required|array|max:100',
            'track' => 'nullable|string|max:20',
            'block_index' => 'nullable|integer|min:0|max:65535',
            'movement_index' => 'nullable|integer|min:0|max:65535',
            'note' => 'nullable|string',
            'idempotency_key' => 'nullable|string',
        ]);

        $idempotencyKey = $request->header('X-Idempotency-Key') ?? $request->input('idempotency_key');
        if ($idempotencyKey) {
            $validated['idempotency_key'] = $idempotencyKey;
        }

        $deviceId = $request->attributes->get('device_id');
        $liftLog = $this->storeSyncLogAction->execute($request->user(), $validated, $deviceId);

        return response()->json([
            'status' => 'ok',
            'log_id' => $liftLog->id,
            'updated_at' => $liftLog->updated_at->toIso8601String(),
        ]);
    }

    /**
     * Destroy a synced completion log.
     */
    public function destroy(Request $request, LiftLog $liftLog): JsonResponse
    {
        $this->deleteSyncLogAction->execute($request->user(), $liftLog);

        return response()->json([
            'status' => 'ok',
        ]);
    }

    /**
     * Store multiple synced completion logs in a single request.
     * Accepts up to 500 logs per batch. Each log is processed independently —
     * individual failures don't roll back others.
     */
    public function batch(Request $request): JsonResponse
    {
        $request->validate([
            'logs' => 'required|array|max:500',
            'logs.*.exercise_name' => 'required|string',
            'logs.*.canonical_name' => 'nullable|string',
            'logs.*.date' => 'required|date',
            'logs.*.log_type' => 'required|string',
            'logs.*.weight_unit' => 'required|string',
            'logs.*.sets' => 'required|array|max:100',
            'logs.*.track' => 'nullable|string|max:20',
            'logs.*.block_index' => 'nullable|integer|min:0|max:65535',
            'logs.*.movement_index' => 'nullable|integer|min:0|max:65535',
            'logs.*.note' => 'nullable|string',
            'logs.*.idempotency_key' => 'nullable|string',
        ]);

        $deviceId = $request->attributes->get('device_id');
        $user = $request->user();
        $results = [];

        foreach ($request->input('logs') as $index => $logData) {
            try {
                $liftLog = \Illuminate\Support\Facades\DB::transaction(function () use ($user, $logData, $deviceId) {
                    return $this->storeSyncLogAction->execute($user, $logData, $deviceId);
                });
                $results[] = [
                    'index' => $index,
                    'status' => 'ok',
                    'log_id' => $liftLog->id,
                    'updated_at' => $liftLog->updated_at->toIso8601String(),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'index' => $index,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'status' => 'ok',
            'results' => $results,
        ]);
    }
}

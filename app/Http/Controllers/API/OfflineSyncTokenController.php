<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OfflineSyncTokenController extends AppBaseController
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => ['required', 'uuid'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);
        $storeId = $this->requireCurrentStoreId();
        $tokenName = $this->tokenName($storeId, $validated['device_id']);
        $expiresAt = now()->addHours(max(1, (int) config('saas.offline_lease_hours', 12)));

        $request->user()->tokens()->where('name', $tokenName)->delete();
        $token = $request->user()->createToken(
            $tokenName,
            ['offline-sales:sync', 'offline-customers:sync', "store:{$storeId}"],
            $expiresAt
        );

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token->plainTextToken,
                'expires_at' => $expiresAt->toIso8601String(),
                'store_id' => $storeId,
                'device_id' => $validated['device_id'],
                'device_name' => $validated['device_name'] ?? null,
                'version' => 2,
            ],
        ], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate(['device_id' => ['required', 'uuid']]);
        $storeId = $this->requireCurrentStoreId();
        $request->user()->tokens()
            ->where('name', $this->tokenName($storeId, $validated['device_id']))
            ->delete();

        return response()->json(['success' => true]);
    }

    private function tokenName(int $storeId, string $deviceId): string
    {
        return "offline-sync:{$storeId}:{$deviceId}";
    }
}

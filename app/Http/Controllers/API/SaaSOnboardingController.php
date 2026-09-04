<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Http\Requests\SaaS\CreateOrganizationRequest;
use App\Services\SaaS\OnboardingService;
use App\Services\SaaS\EntitlementService;
use Illuminate\Http\JsonResponse;

class SaaSOnboardingController extends AppBaseController
{
    public function store(
        CreateOrganizationRequest $request,
        OnboardingService $onboarding,
        EntitlementService $entitlements
    ): JsonResponse {
        if (! config('saas.self_registration_enabled')) {
            return $this->sendError('El registro de nuevas organizaciones no está disponible.', 403);
        }

        $result = $onboarding->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Tu cuenta EcuaPos fue creada correctamente.',
            'data' => [
                'organization' => $result['organization']->only(['id', 'name', 'slug']),
                'store' => $result['store']->only(['id', 'name', 'slug']),
                'warehouse' => $result['warehouse']->only(['id', 'name']),
                'user' => $result['user']->only(['id', 'first_name', 'last_name', 'email']),
                'subscription' => $entitlements->summary($result['organization']->id),
            ],
        ], 201);
    }
}

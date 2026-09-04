<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AppBaseController;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class OrganizationAPIController extends AppBaseController
{
    public function mine(): JsonResponse
    {
        $organizations = Auth::user()->organizations()
            ->where('organizations.is_active', true)
            ->wherePivot('status', Organization::STATUS_ACTIVE)
            ->withCount('stores')
            ->orderBy('organizations.name')
            ->get(['organizations.id', 'organizations.name', 'organizations.slug', 'organizations.is_active'])
            ->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'is_active' => $organization->is_active,
                'role' => $organization->pivot->role,
                'stores_count' => $organization->stores_count,
                'is_current' => $organization->id === currentOrganizationId(),
            ]);

        return $this->sendResponse($organizations, 'Organizaciones obtenidas correctamente.');
    }

    public function current(): JsonResponse
    {
        $organization = Organization::query()
            ->withCount(['stores', 'users'])
            ->findOrFail($this->requireCurrentOrganizationId());

        return $this->sendResponse([
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'is_active' => $organization->is_active,
            'stores_count' => $organization->stores_count,
            'users_count' => $organization->users_count,
        ], 'Organización activa obtenida correctamente.');
    }
}

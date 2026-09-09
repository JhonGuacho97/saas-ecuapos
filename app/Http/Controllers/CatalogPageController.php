<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\SaaS\EntitlementService;
use Illuminate\Contracts\View\View;

class CatalogPageController extends Controller
{
    public function __invoke(Store $store, EntitlementService $entitlements): View
    {
        abort_unless(
            $store->is_active
                && $store->organization?->is_active
                && $store->organization_id
                && $entitlements->organizationCanWrite((int) $store->organization_id)
                && $store->catalogSetting?->is_enabled,
            404
        );

        return view('catalog', compact('store'));
    }
}

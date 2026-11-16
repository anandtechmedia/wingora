<?php

namespace App\Http\Controllers\Api\V1;

use App\Branding\Branding;
use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    public function show(): JsonResponse
    {
        $tenant = Tenancy::current();
        Gate::authorize('view', $tenant);

        return response()->json(['data' => $this->present($tenant)]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = Tenancy::current();
        Gate::authorize('update', $tenant);
        $tenant->update($request->validate(['name' => ['required', 'string', 'max:160']]));

        return response()->json(['data' => $this->present($tenant)]);
    }

    public function updateBranding(Request $request): JsonResponse
    {
        $tenant = Tenancy::current();
        Gate::authorize('update', $tenant);
        $data = $request->validate(Branding::rules());
        $tenant->forceFill(['brand_name' => filled($data['brand_name'] ?? null) ? trim($data['brand_name']) : null, 'brand_color' => isset($data['brand_color']) ? strtoupper($data['brand_color']) : null])->save();

        return response()->json(['data' => $this->present($tenant)]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $tenant = Tenancy::current();
        Gate::authorize('update', $tenant);
        $request->validate(['logo' => Branding::logoRule()]);
        Branding::storeLogo($tenant, $request->file('logo'));

        return response()->json(['data' => $this->present($tenant)]);
    }

    public function deleteLogo(): JsonResponse
    {
        $tenant = Tenancy::current();
        Gate::authorize('update', $tenant);
        Branding::deleteLogo($tenant);

        return response()->json(['data' => $this->present($tenant)]);
    }

    public function updateSecurity(Request $request): JsonResponse
    {
        $tenant = Tenancy::current();
        Gate::authorize('updateSecurity', $tenant);
        $data = $request->validate(['mfa_required' => ['required', 'boolean']]);

        $tenant->forceFill(['mfa_required' => $data['mfa_required']])->save();
        SecurityEvent::record('organization.mfa_policy_changed', 'warning', $request->user(), $data, audit: false);

        return response()->json(['data' => $this->present($tenant)]);
    }

    private function present($tenant): array
    {
        return [
            'id' => $tenant->public_id,
            'name' => $tenant->name,
            'country_code' => $tenant->country_code,
            'status' => $tenant->status,
            'mfa_required' => $tenant->mfa_required,
            'slug' => $tenant->slug,
            'branding' => Branding::present($tenant),
        ];
    }
}

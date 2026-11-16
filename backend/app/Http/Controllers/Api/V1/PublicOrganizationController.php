<?php

namespace App\Http\Controllers\Api\V1;

use App\Branding\Branding;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** What an organization's sign-in page needs before anyone signs in: its name, branding and sign-in options. */
class PublicOrganizationController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $tenant = $this->find($slug);
        $sso = $tenant->ssoConnection;

        return response()->json(['data' => [
            'slug' => $tenant->slug,
            'name' => $tenant->name,
            'branding' => Branding::present($tenant),
            'sso' => ['enabled' => (bool) $sso?->enabled, 'enforced' => (bool) ($sso?->enabled && $sso->enforced)],
        ]]);
    }

    public function logo(string $slug): Response
    {
        $tenant = $this->find($slug);
        abort_unless($tenant->logo_path && in_array($tenant->logo_mime, ['image/png', 'image/jpeg', 'image/webp'], true), 404);

        return Storage::disk(config('security.files.disk'))->response($tenant->logo_path, null, [
            'Content-Type' => $tenant->logo_mime,
            'Content-Disposition' => 'inline',
        ]);
    }

    private function find(string $slug): Tenant
    {
        $tenant = Tenant::query()->where('slug', $slug)->first();
        abort_unless($tenant && $tenant->status !== 'deleted', 404);

        return $tenant;
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Auth\SsoController;
use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\SsoConnection;
use App\Security\Sso\OidcClient;
use App\Security\Sso\SsoError;
use App\Support\Tenancy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** The organization's OIDC connection. Restricted to the organization security permission; the secret is write-only. */
class OrganizationSsoController extends Controller
{
    public function show(): JsonResponse
    {
        Gate::authorize('updateSecurity', Tenancy::current());

        return response()->json(['data' => $this->present(SsoConnection::query()->first())]);
    }

    public function update(Request $request, OidcClient $oidc): JsonResponse
    {
        $tenant = Tenancy::current();
        Gate::authorize('updateSecurity', $tenant);
        $connection = SsoConnection::query()->first();

        $data = $request->validate([
            'issuer' => ['required', 'string', 'max:255', 'url:https'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => [$connection ? 'nullable' : 'required', 'string', 'max:2000'],
            'allowed_domains' => ['required', 'array', 'min:1', 'max:20'],
            'allowed_domains.*' => ['required', 'string', 'max:253', 'regex:/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', 'distinct:ignore_case'],
            'enabled' => ['required', 'boolean'],
            'enforced' => ['required', 'boolean', 'declined_if:enabled,false'],
            'version' => [$connection ? 'required' : 'nullable', 'integer'],
        ]);

        if ($data['enabled']) {
            try {
                $oidc->discover($data['issuer'], fresh: true);
            } catch (SsoError) {
                throw ValidationException::withMessages(['issuer' => __('Wingora could not read this provider’s OpenID configuration. Check the issuer URL.')]);
            }
        }

        $stale = fn () => abort(409, __('Someone else changed these settings. Reload and try again.'));

        try {
            $connection = DB::transaction(fn () => $this->save($data, $stale));
        } catch (UniqueConstraintViolationException) {
            $stale();
        }

        SecurityEvent::record('organization.sso_changed', 'warning', $request->user(), ['enabled' => $data['enabled'], 'enforced' => $data['enforced'], 'issuer' => $data['issuer']]);

        return response()->json(['data' => $this->present($connection->fresh())]);
    }

    /** Re-reads the row under a lock so two saves of the same version cannot both succeed. */
    private function save(array $data, callable $stale): SsoConnection
    {
        $connection = SsoConnection::query()->lockForUpdate()->first();
        if ($connection ? (int) ($data['version'] ?? 0) !== $connection->version : ! filled($data['client_secret'] ?? null)) {
            $stale();
        }
        $connection ??= new SsoConnection;
        $connection->forceFill([
            'issuer' => $data['issuer'],
            'client_id' => $data['client_id'],
            'allowed_domains' => array_values(array_map('strtolower', $data['allowed_domains'])),
            'enabled' => $data['enabled'],
            'enforced' => $data['enforced'],
        ] + (filled($data['client_secret'] ?? null) ? ['client_secret' => $data['client_secret']] : []));
        $connection->version = $connection->exists ? $connection->version + 1 : 1;
        $connection->save();

        return $connection;
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('updateSecurity', Tenancy::current());
        SsoConnection::query()->first()?->delete();
        SecurityEvent::record('organization.sso_removed', 'warning', $request->user());

        return response()->json(['data' => $this->present(null)]);
    }

    private function present(?SsoConnection $c): array
    {
        return [
            'redirect_uri' => SsoController::callbackUrl(),
            'connection' => $c ? [
                'issuer' => $c->issuer,
                'client_id' => $c->client_id,
                'has_client_secret' => filled($c->getRawOriginal('client_secret')),
                'allowed_domains' => $c->allowed_domains,
                'enabled' => $c->enabled,
                'enforced' => $c->enforced,
                'version' => $c->version,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ] : null,
        ];
    }
}

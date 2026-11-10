<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Security\LoginService;
use App\Security\RoleCatalog;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Platform staff manage organizations, but never read organization HR data. */
class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Tenant::class);
        $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);

        return response()->json(Tenant::query()->orderBy('name')->paginate((int) $request->input('per_page', 25))
            ->through(fn (Tenant $t) => $this->present($t)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', Tenant::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', 'unique:tenants,slug'],
            'country_code' => ['required', 'string', Rule::in(config('security.countries'))],
            'owner_name' => ['required', 'string', 'max:160'],
            'owner_email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        ]);

        $tenant = DB::transaction(function () use ($data) {
            $tenant = Tenant::create(['name' => $data['name'], 'slug' => $data['slug'], 'country_code' => $data['country_code']]);
            RoleCatalog::syncTenantRoles($tenant);

            Tenancy::run($tenant, function () use ($data) {
                $owner = User::create(['name' => $data['owner_name'], 'email' => Str::lower($data['owner_email']), 'password' => Str::password(40)]);
                $owner->roles()->attach(Role::query()->where('key', RoleCatalog::ORGANIZATION_OWNER)->value('id'), ['created_at' => now()]);
            });

            return $tenant;
        });

        Password::sendResetLink(['email' => Str::lower($data['owner_email'])]);
        SecurityEvent::record('platform.tenant_created', 'warning', request()->user(), ['tenant' => $tenant->public_id], $tenant->id);

        return response()->json(['data' => $this->present($tenant)], 201);
    }

    public function updateStatus(Request $request, Tenant $tenant, LoginService $logins): JsonResponse
    {
        Gate::authorize('changeStatus', $tenant);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])]]);

        $old = $tenant->status;
        $tenant->forceFill(['status' => $data['status']])->save();

        if ($data['status'] === 'suspended') {
            User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->each(fn (User $u) => $logins->revokeEverything($u));
        }

        AuditLog::record('platform.tenant_status_changed', $tenant, ['status' => $old], ['status' => $data['status']]);
        SecurityEvent::record('platform.tenant_status_changed', 'warning', $request->user(), ['tenant' => $tenant->public_id, 'status' => $data['status']], $tenant->id);

        return response()->json(['data' => $this->present($tenant)]);
    }

    private function present(Tenant $tenant): array
    {
        return [
            'id' => $tenant->public_id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'country_code' => $tenant->country_code,
            'status' => $tenant->status,
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Api\V1\Concerns\ServesAuditLogs;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Security\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Platform-level audit entries only (tenant_id null). Platform staff never read tenant audit trails here. */
class AuditLogController extends Controller
{
    use ServesAuditLogs;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::PlatformAuditView->value);

        return $this->search($request, $this->platformQuery());
    }

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize(Permission::PlatformAuditView->value);

        return $this->summarize($request, $this->platformQuery());
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize(Permission::PlatformAuditExport->value);

        return $this->exportEntries($request, $this->platformQuery(), null);
    }

    public function integrity(): JsonResponse
    {
        Gate::authorize(Permission::PlatformAuditView->value);

        return $this->verifyChains(null);
    }

    private function platformQuery(): Builder
    {
        return AuditLog::query()->withoutGlobalScopes()->whereNull('tenant_id');
    }
}

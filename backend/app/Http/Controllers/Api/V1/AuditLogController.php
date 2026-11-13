<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ServesAuditLogs;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Security\Permission;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Tenant audit reports. Queries run through the tenant global scope, so only the caller's tenant is visible. */
class AuditLogController extends Controller
{
    use ServesAuditLogs;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AuditView->value);

        return $this->search($request, AuditLog::query());
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        Gate::authorize(Permission::AuditView->value);

        return response()->json(['data' => $this->presentEntry($auditLog->load('actor'), evidence: true)]);
    }

    public function summary(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AuditView->value);

        return $this->summarize($request, AuditLog::query());
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize(Permission::AuditExport->value);

        return $this->exportEntries($request, AuditLog::query(), Tenancy::current());
    }

    public function integrity(): JsonResponse
    {
        Gate::authorize(Permission::AuditView->value);

        return $this->verifyChains(Tenancy::id());
    }
}

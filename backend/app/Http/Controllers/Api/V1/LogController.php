<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SecurityEvent;
use App\Security\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LogController extends Controller
{
    public function audit(Request $request): JsonResponse
    {
        Gate::authorize(Permission::AuditView->value);

        return $this->paginate($request, AuditLog::query(), ['id', 'event', 'subject_type', 'old_values', 'new_values', 'ip_address', 'request_id', 'created_at']);
    }

    public function security(Request $request): JsonResponse
    {
        Gate::authorize(Permission::SecurityEventsView->value);

        return $this->paginate($request, SecurityEvent::query(), ['id', 'type', 'severity', 'ip_address', 'request_id', 'metadata', 'created_at']);
    }

    private function paginate(Request $request, $query, array $columns): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);

        return response()->json($query->latest('id')->paginate((int) ($data['per_page'] ?? 50), $columns));
    }
}

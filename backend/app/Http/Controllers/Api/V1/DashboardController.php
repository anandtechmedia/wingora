<?php

namespace App\Http\Controllers\Api\V1;

use App\Dashboard\DashboardService;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTenantReferences;
use App\Http\Controllers\Controller;
use App\Models\LegalEntity;
use App\Security\EmployeeAccess;
use App\Security\Permission as P;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    use ResolvesTenantReferences;

    public const MAX_RANGE_MONTHS = 24;

    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        Gate::authorize(P::OrganizationView->value);
        $data = $request->validate([
            'country' => ['sometimes', 'nullable', 'string', 'size:2', 'exists:countries,code'],
            'legal_entity' => ['sometimes', 'nullable', 'string', 'max:26'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $user = $request->user();
        $today = CarbonImmutable::today($user->tenant?->timezone ?? config('app.timezone'));
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : $today;
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->subMonths(11)->startOfMonth();
        if ($from->startOfMonth()->diffInMonths($to->startOfMonth()) >= self::MAX_RANGE_MONTHS) {
            throw ValidationException::withMessages(['from' => __('The date range may not exceed :months months.', ['months' => self::MAX_RANGE_MONTHS])]);
        }

        $entity = isset($data['legal_entity']) ? $this->owned(LegalEntity::class, $data['legal_entity'], 'legal_entity') : null;
        $country = isset($data['country']) ? strtoupper($data['country']) : null;
        $entityIds = match (true) {
            $entity !== null && $country !== null && $entity->country_code !== $country => [],
            $entity !== null => [$entity->id],
            $country !== null => LegalEntity::query()->where('country_code', $country)->pluck('id')->all(),
            default => null,
        };

        return response()->json(['data' => [
            'scope' => $user->hasPermission(P::EmployeesViewAll) ? 'organization' : (EmployeeAccess::managedDepartmentIds($user) !== [] ? 'departments' : 'none'),
            'filters' => ['country' => $country, 'legal_entity' => $entity?->public_id, 'from' => $from->toDateString(), 'to' => $to->toDateString()],
            'generated_at' => now()->toIso8601String(),
            'widgets' => $dashboard->widgets($user, ['legal_entity_ids' => $entityIds, 'from' => $from, 'to' => $to, 'today' => $today]),
        ]]);
    }
}

<?php

namespace App\Dashboard;

use App\Models\Employee;
use App\Models\User;
use App\Security\EmployeeAccess;
use App\Security\Permission as P;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aggregated organization metrics. Every figure is computed in the database over the employees the
 * user may see (EmployeeAccess), so the API never ships employee lists for the client to count.
 */
final class DashboardService
{
    public const UPCOMING_DAYS = 30;

    public const UPCOMING_LIMIT = 8;

    /** Widgets whose source modules do not exist yet; reported as unavailable instead of invented. */
    public const PENDING_MODULES = [
        'pending_leave_approvals' => 'leave',
        'todays_attendance' => 'attendance',
        'absentees' => 'attendance',
        'pending_onboarding_tasks' => 'onboarding',
        'contract_expirations' => 'contracts',
        'recruitment_pipeline' => 'recruitment',
    ];

    /**
     * @param  array{legal_entity_ids: list<int>|null, from: CarbonImmutable, to: CarbonImmutable, today: CarbonImmutable}  $filters
     * @return array<string, array<string, mixed>>
     */
    public function widgets(User $user, array $filters): array
    {
        if (! $user->hasPermission(P::EmployeesViewAll) && ! $user->hasPermission(P::EmployeesViewDepartment)) {
            return [];
        }

        $base = fn (): Builder => EmployeeAccess::scopeEmployees(Employee::query(), $user)
            ->when($filters['legal_entity_ids'] !== null, fn (Builder $q) => $q->whereIn('legal_entity_id', $filters['legal_entity_ids']));

        $today = $filters['today'];
        $counts = $base()->toBase()->selectRaw(
            'COUNT(*) AS total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN hire_date >= ? AND hire_date < ? THEN 1 ELSE 0 END) AS joiners',
            ['active', $filters['from']->toDateString(), $filters['to']->addDay()->toDateString()],
        )->first();

        $widgets = [
            'total_employees' => ['state' => 'ok', 'value' => (int) $counts->total],
            'active_employees' => ['state' => 'ok', 'value' => (int) $counts->active],
            'new_joiners' => ['state' => 'ok', 'value' => (int) ($counts->joiners ?? 0)],
            'upcoming_birthdays' => ['state' => 'ok', 'items' => $this->upcoming($base(), 'birthday_md', $today)],
            'upcoming_anniversaries' => ['state' => 'ok', 'items' => $this->upcoming($base()->where('hire_date', '<', $today->startOfYear()->toDateString()), 'hire_md', $today, true)],
            'workforce_trends' => ['state' => 'ok', 'series' => $this->trends($base(), $filters['from'], $filters['to'])],
        ];

        // Module placeholders are organization-wide concepts, shown only to users with organization-wide access.
        if ($user->hasPermission(P::EmployeesViewAll)) {
            foreach (self::PENDING_MODULES as $key => $module) {
                $widgets[$key] = ['state' => 'unavailable', 'module' => $module];
            }
        }

        return $widgets;
    }

    /** @return list<array<string, mixed>> name and MM-DD only; never a year or age */
    private function upcoming(Builder $query, string $column, CarbonImmutable $today, bool $withYears = false): array
    {
        $days = [];
        for ($i = 0; $i < self::UPCOMING_DAYS; $i++) {
            $days[] = $today->addDays($i)->format('m-d');
        }

        $rows = $query->where('status', 'active')->whereIn($column, $days)
            ->get(['id', 'public_id', 'first_name', 'last_name', 'hire_date', $column]);

        return $rows
            ->sortBy(fn (Employee $e) => array_search($e->{$column}, $days, true))
            ->take(self::UPCOMING_LIMIT)
            ->map(function (Employee $e) use ($column, $days, $today, $withYears) {
                $offset = (int) array_search($e->{$column}, $days, true);
                $item = ['employee_id' => $e->public_id, 'name' => $e->fullName(), 'date' => $today->addDays($offset)->toDateString(), 'in_days' => $offset];
                if ($withYears) {
                    $item['years'] = $today->addDays($offset)->year - $e->hire_date->year;
                }

                return $item;
            })->values()->all();
    }

    /** @return list<array{month: string, headcount: int, hires: int, exits: int}> one conditional-aggregate query */
    private function trends(Builder $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $months = [];
        for ($m = $from->startOfMonth(); $m <= $to; $m = $m->addMonth()) {
            $months[] = $m;
        }

        $select = [];
        $bindings = [];
        foreach ($months as $i => $start) {
            // Half-open [first, next) bounds: date columns may be stored with a time part (SQLite).
            $first = $start->toDateString();
            $next = $start->addMonth()->toDateString();
            $select[] = "SUM(CASE WHEN (hire_date IS NULL OR hire_date < ?) AND (termination_date IS NULL OR termination_date >= ?) THEN 1 ELSE 0 END) AS h{$i}";
            $select[] = "SUM(CASE WHEN hire_date >= ? AND hire_date < ? THEN 1 ELSE 0 END) AS j{$i}";
            $select[] = "SUM(CASE WHEN termination_date >= ? AND termination_date < ? THEN 1 ELSE 0 END) AS x{$i}";
            array_push($bindings, $next, $next, $first, $next, $first, $next);
        }

        $row = (array) $query->toBase()->selectRaw(implode(', ', $select), $bindings)->first();

        return array_map(fn (CarbonImmutable $start, int $i) => [
            'month' => $start->format('Y-m'),
            'headcount' => (int) ($row["h{$i}"] ?? 0),
            'hires' => (int) ($row["j{$i}"] ?? 0),
            'exits' => (int) ($row["x{$i}"] ?? 0),
        ], $months, array_keys($months));
    }
}

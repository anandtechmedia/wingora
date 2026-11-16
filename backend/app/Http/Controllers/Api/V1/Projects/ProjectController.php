<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Projects, their members and the hours report. Creating and editing projects needs projects.manage. */
class ProjectController extends Controller
{
    use Concerns;

    public const STATUSES = ['planned', 'active', 'on_hold', 'completed', 'cancelled'];

    public function index(Request $request): JsonResponse
    {
        $this->viewer($request);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $query = Project::query()->with('manager')->withCount(['members', 'tasks']);
        if (! $this->canManage($request)) {
            $me = $this->me($request) ?? 0;
            $query->where(fn ($w) => $w->where('manager_employee_id', $me)->orWhereHas('members', fn ($m) => $m->where('employee_id', $me)));
        }
        if ($term = $data['q'] ?? null) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($w) => $w->where('code', 'like', $like)->orWhere('name', 'like', $like)->orWhere('client', 'like', $like));
        }
        $query->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
        $page = $query->orderBy('name')->orderBy('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($p) => $this->present($p, $request))->values(), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->viewProject($request, $project);

        return response()->json(['data' => $this->detail($project, $request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->manager($request);
        $data = $this->validated($request, true);
        $this->checkDates($data['starts_on'] ?? null, $data['ends_on'] ?? null);
        $this->uniqueCode($data['code']);
        $manager = isset($data['manager_id']) ? $this->employee($data['manager_id'], 'manager_id') : null;
        $project = DB::transaction(function () use ($data, $manager, $request) {
            $p = (new Project)->forceFill([
                'code' => $data['code'], 'name' => $data['name'], 'client' => $data['client'] ?? null, 'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'planned', 'starts_on' => $data['starts_on'] ?? null, 'ends_on' => $data['ends_on'] ?? null,
                'budget_hours' => $data['budget_hours'] ?? null, 'manager_employee_id' => $manager?->id, 'created_by' => $request->user()->id,
            ]);
            $p->save();
            if ($manager) {
                (new ProjectMember)->forceFill(['project_id' => $p->id, 'employee_id' => $manager->id])->save();
            }

            return $p;
        });

        return response()->json(['data' => $this->detail($project, $request)], 201);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->manager($request);
        $data = $this->validated($request, false) + $request->validate(['version' => ['required', 'integer']]);
        $manager = ! empty($data['manager_id']) ? $this->employee($data['manager_id'], 'manager_id') : null;
        $project = DB::transaction(function () use ($project, $data, $manager) {
            $p = Project::query()->lockForUpdate()->findOrFail($project->id);
            $this->checkVersion($p, $data['version']);
            $starts = array_key_exists('starts_on', $data) ? $data['starts_on'] : $p->starts_on?->toDateString();
            $ends = array_key_exists('ends_on', $data) ? $data['ends_on'] : $p->ends_on?->toDateString();
            $this->checkDates($starts, $ends);
            if (isset($data['code']) && $data['code'] !== $p->code) {
                $this->uniqueCode($data['code']);
            }
            foreach (['code', 'name', 'client', 'description', 'status', 'starts_on', 'ends_on', 'budget_hours'] as $field) {
                if (array_key_exists($field, $data)) {
                    $p->{$field} = $data[$field];
                }
            }
            if (array_key_exists('manager_id', $data)) {
                $p->manager_employee_id = $manager?->id;
                if ($manager) {
                    $this->ensureMember($p->id, $manager->id);
                }
            }
            $p->version = $p->version + 1;
            $p->save();

            return $p;
        });

        return response()->json(['data' => $this->detail($project, $request)]);
    }

    public function addMember(Request $request, Project $project): JsonResponse
    {
        $this->manageProject($request, $project);
        $data = $request->validate(['employee_id' => ['required', 'string', 'max:26']]);
        $employee = $this->employee($data['employee_id'], 'employee_id');
        $this->ensureMember($project->id, $employee->id);

        return response()->json(['data' => $this->detail($project, $request)]);
    }

    public function removeMember(Request $request, Project $project, string $employee): JsonResponse
    {
        $this->manageProject($request, $project);
        $e = Employee::query()->where('public_id', $employee)->firstOrFail();
        abort_if((int) $project->manager_employee_id === $e->id, 422, __('Change the project manager before removing them.'));
        abort_if(ProjectTask::query()->where('project_id', $project->id)->where('assignee_employee_id', $e->id)->where('status', '!=', 'done')->exists(), 422, __('Reassign this person\'s open tasks first.'));
        ProjectMember::query()->where('project_id', $project->id)->where('employee_id', $e->id)->first()?->delete();

        return response()->json(['data' => $this->detail($project, $request)]);
    }

    /** Hours logged on submitted and approved timesheets, by person, task and week, against the budget. */
    public function report(Request $request, Project $project): JsonResponse
    {
        $this->manageProject($request, $project);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $base = fn (string $status) => TimesheetEntry::query()->where('project_id', $project->id)
            ->whereIn('timesheet_id', Timesheet::query()->where('status', $status)->select('id'))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('work_date', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('work_date', '<=', $d));
        $sum = fn (string $status, string $column) => $base($status)->groupBy($column)->selectRaw("{$column} as k, sum(minutes) as m")->pluck('m', 'k')->map(fn ($m) => (int) $m);
        $merge = function (string $column) use ($sum) {
            $approved = $sum('approved', $column);
            $pending = $sum('submitted', $column);

            return collect($approved->keys())->merge($pending->keys())->unique()->values()
                ->map(fn ($k) => ['key' => $k, 'approved_minutes' => $approved[$k] ?? 0, 'pending_minutes' => $pending[$k] ?? 0]);
        };
        $byEmployee = $merge('employee_id');
        $byTask = $merge('project_task_id');
        $people = Employee::query()->whereIn('id', $byEmployee->pluck('key'))->get()->keyBy('id');
        $tasks = ProjectTask::query()->whereIn('id', $byTask->pluck('key')->filter())->get()->keyBy('id');
        $weeks = [];
        foreach ($merge('work_date') as $row) {
            $week = Carbon::parse($row['key'])->startOfWeek(Carbon::MONDAY)->toDateString();
            $weeks[$week] ??= ['week_start' => $week, 'approved_minutes' => 0, 'pending_minutes' => 0];
            $weeks[$week]['approved_minutes'] += $row['approved_minutes'];
            $weeks[$week]['pending_minutes'] += $row['pending_minutes'];
        }
        ksort($weeks);
        $approved = $byEmployee->sum('approved_minutes');
        $budget = $project->budget_hours !== null ? (int) round((float) $project->budget_hours * 60) : null;

        return response()->json(['data' => [
            'totals' => [
                'approved_minutes' => $approved, 'pending_minutes' => $byEmployee->sum('pending_minutes'), 'budget_minutes' => $budget,
                'budget_used_percent' => $budget ? round($approved / $budget * 100, 1) : null,
            ],
            'by_employee' => $byEmployee->map(fn ($r) => ['employee' => $this->person($people[$r['key']] ?? null)] + array_slice($r, 1))
                ->sortByDesc('approved_minutes')->values(),
            'by_task' => $byTask->map(fn ($r) => ['task' => isset($tasks[$r['key']]) ? ['id' => $tasks[$r['key']]->public_id, 'title' => $tasks[$r['key']]->title] : null] + array_slice($r, 1))
                ->sortByDesc('approved_minutes')->values(),
            'by_week' => array_values($weeks),
        ]]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'name' => [$required, 'string', 'max:120'],
            'client' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date'],
            'budget_hours' => ['nullable', 'numeric', 'between:0,9999999.99'],
            'manager_id' => ['nullable', 'string', 'max:26'],
        ]);
    }

    private function ensureMember(int $projectId, int $employeeId): void
    {
        if (! ProjectMember::query()->where('project_id', $projectId)->where('employee_id', $employeeId)->exists()) {
            (new ProjectMember)->forceFill(['project_id' => $projectId, 'employee_id' => $employeeId])->save();
        }
    }

    private function checkDates(?string $starts, ?string $ends): void
    {
        if ($starts && $ends && Carbon::parse($ends)->lt(Carbon::parse($starts))) {
            throw ValidationException::withMessages(['ends_on' => __('The end date must be on or after the start date.')]);
        }
    }

    private function uniqueCode(string $code): void
    {
        if (Project::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => __('Another project already uses this code.')]);
        }
    }

    private function employee(string $publicId, string $field): Employee
    {
        return Employee::query()->where('public_id', $publicId)->firstOr(fn () => throw ValidationException::withMessages([$field => __('Choose an employee in this organization.')]));
    }

    private function present(Project $p, Request $request): array
    {
        return [
            'id' => $p->public_id, 'code' => $p->code, 'name' => $p->name, 'client' => $p->client, 'description' => $p->description, 'status' => $p->status,
            'starts_on' => $p->starts_on?->toDateString(), 'ends_on' => $p->ends_on?->toDateString(), 'budget_hours' => $p->budget_hours,
            'manager' => $this->person($p->manager), 'members_count' => (int) ($p->members_count ?? 0), 'tasks_count' => (int) ($p->tasks_count ?? 0),
            'can_manage' => $this->canManage($request) || $this->leads($p, $this->me($request)), 'version' => (int) $p->version,
        ];
    }

    private function detail(Project $project, Request $request): array
    {
        $project = Project::query()->with(['manager', 'members.employee'])->withCount(['members', 'tasks'])->findOrFail($project->id);

        return $this->present($project, $request) + [
            'members' => $project->members->map(fn ($m) => $this->person($m->employee))->filter()->sortBy('name')->values(),
        ];
    }
}

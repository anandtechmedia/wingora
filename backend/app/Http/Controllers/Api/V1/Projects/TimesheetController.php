<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Security\Permission as P;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Weekly timesheets: draft → submitted → approved | rejected (rejected sheets can be edited and resubmitted).
 * Approvers need timesheets.approve and see their direct reports, or everyone with projects.manage; never themselves.
 */
class TimesheetController extends Controller
{
    use Concerns;

    public function index(Request $request): JsonResponse
    {
        $this->viewer($request);
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']]);
        $page = Timesheet::query()->where('employee_id', $this->me($request) ?? 0)->orderByDesc('week_start')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($t) => $this->summary($t))->values(), 'meta' => $this->meta($page)]);
    }

    /** The signed-in employee's sheet for a week (blank if not started) and the projects they can log time to. */
    public function mine(Request $request): JsonResponse
    {
        $this->viewer($request);
        $me = $this->employeeId($request);
        $week = $this->week($request->validate(['week' => ['nullable', 'date_format:Y-m-d']])['week'] ?? now()->toDateString(), false);
        $sheet = Timesheet::query()->with(['entries.project', 'entries.task', 'decider'])->where('employee_id', $me)->whereDate('week_start', $week)->first();
        $projects = Project::query()->where('status', 'active')
            ->where(fn ($w) => $w->where('manager_employee_id', $me)->orWhereHas('members', fn ($m) => $m->where('employee_id', $me)))
            ->with(['tasks' => fn ($q) => $q->where('status', '!=', 'done')->orderBy('title')])->orderBy('name')->limit(100)->get();

        return response()->json(['data' => [
            'timesheet' => $sheet ? $this->detail($sheet) : ['id' => null, 'week_start' => $week->toDateString(), 'status' => 'draft', 'total_minutes' => 0, 'version' => 0, 'entries' => []],
            'projects' => $projects->map(fn ($p) => ['id' => $p->public_id, 'code' => $p->code, 'name' => $p->name,
                'tasks' => $p->tasks->map(fn ($t) => ['id' => $t->public_id, 'title' => $t->title])->values()])->values(),
        ]]);
    }

    public function save(Request $request, string $week): JsonResponse
    {
        $this->viewer($request);
        $me = $this->employeeId($request);
        $week = $this->week($week, true);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:0'],
            'entries' => ['present', 'array', 'max:200'],
            'entries.*.project_id' => ['required', 'string', 'max:26'],
            'entries.*.task_id' => ['nullable', 'string', 'max:26'],
            'entries.*.work_date' => ['required', 'date_format:Y-m-d'],
            'entries.*.minutes' => ['required', 'integer', 'between:1,1440'],
            'entries.*.note' => ['nullable', 'string', 'max:500'],
        ]);
        $rows = $this->resolveEntries($data['entries'], $week, $me);

        try {
            $sheet = DB::transaction(function () use ($me, $week, $data, $rows) {
                $t = Timesheet::query()->where('employee_id', $me)->whereDate('week_start', $week)->lockForUpdate()->first();
                if ($t) {
                    $this->checkVersion($t, $data['version']);
                    abort_unless(in_array($t->status, ['draft', 'rejected'], true), 422, __('Submitted and approved timesheets cannot be changed.'));
                } else {
                    abort_unless((int) $data['version'] === 0, 409, __('This record was changed by someone else. Reload and try again.'));
                    $t = (new Timesheet)->forceFill(['employee_id' => $me, 'week_start' => $week, 'status' => 'draft', 'version' => 0]);
                }
                $t->forceFill(['status' => 'draft', 'total_minutes' => array_sum(array_column($rows, 'minutes')), 'version' => $t->version + 1])->save();
                TimesheetEntry::query()->where('timesheet_id', $t->id)->delete();
                TimesheetEntry::withoutEvents(function () use ($t, $rows, $me) {
                    foreach ($rows as $row) {
                        (new TimesheetEntry)->forceFill($row + ['public_id' => (string) Str::ulid(), 'tenant_id' => $t->tenant_id, 'timesheet_id' => $t->id, 'employee_id' => $me])->save();
                    }
                });

                return $t;
            });
        } catch (UniqueConstraintViolationException) {
            abort(409, __('This record was changed by someone else. Reload and try again.'));
        }

        return response()->json(['data' => $this->detail($sheet->fresh())]);
    }

    public function submit(Request $request, Timesheet $timesheet): JsonResponse
    {
        $this->viewer($request);
        abort_unless((int) $timesheet->employee_id === $this->me($request), 404);
        $data = $request->validate(['version' => ['required', 'integer']]);
        $sheet = DB::transaction(function () use ($timesheet, $data) {
            $t = Timesheet::query()->lockForUpdate()->findOrFail($timesheet->id);
            $this->checkVersion($t, $data['version']);
            abort_unless(in_array($t->status, ['draft', 'rejected'], true), 422, __('This timesheet has already been submitted.'));
            abort_if($t->total_minutes === 0, 422, __('Add time before submitting.'));
            $t->forceFill(['status' => 'submitted', 'submitted_at' => now(), 'decided_by' => null, 'decided_at' => null, 'decision_note' => null, 'version' => $t->version + 1])->save();

            return $t;
        });

        return response()->json(['data' => $this->detail($sheet)]);
    }

    public function approvals(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::TimesheetsApprove), 403);
        $data = $request->validate(['status' => ['nullable', Rule::in(['submitted', 'approved', 'rejected'])], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $me = $this->me($request) ?? 0;
        $page = Timesheet::query()->with('employee')->where('status', $data['status'] ?? 'submitted')->where('employee_id', '!=', $me)
            ->when(! $this->canManage($request), fn ($q) => $q->whereIn('employee_id', Employee::query()->where('manager_id', $me)->select('id')))
            ->orderBy('week_start')->orderBy('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($t) => $this->summary($t, true))->values(), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, Timesheet $timesheet): JsonResponse
    {
        $own = $this->me($request) !== null && (int) $timesheet->employee_id === $this->me($request);
        abort_unless(($own && ($request->user()->hasPermission(P::ProjectsView) || $this->canManage($request))) || $this->canApprove($request, $timesheet), 404);

        return response()->json(['data' => $this->detail($timesheet)]);
    }

    public function approve(Request $request, Timesheet $timesheet): JsonResponse
    {
        return $this->decide($request, $timesheet, 'approved');
    }

    public function reject(Request $request, Timesheet $timesheet): JsonResponse
    {
        return $this->decide($request, $timesheet, 'rejected');
    }

    private function decide(Request $request, Timesheet $timesheet, string $status): JsonResponse
    {
        abort_unless($this->canApprove($request, $timesheet), 403);
        $data = $request->validate(['version' => ['required', 'integer'], 'note' => [$status === 'rejected' ? 'required' : 'nullable', 'string', 'max:1000']]);
        $sheet = DB::transaction(function () use ($timesheet, $data, $status, $request) {
            $t = Timesheet::query()->lockForUpdate()->findOrFail($timesheet->id);
            $this->checkVersion($t, $data['version']);
            abort_unless($t->status === 'submitted', 422, __('Only submitted timesheets can be approved or rejected.'));
            $t->forceFill(['status' => $status, 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null, 'version' => $t->version + 1])->save();

            return $t;
        });

        return response()->json(['data' => $this->detail($sheet)]);
    }

    private function canApprove(Request $request, Timesheet $t): bool
    {
        $me = $this->me($request);

        if (! $request->user()->hasPermission(P::TimesheetsApprove) || ($me !== null && (int) $t->employee_id === $me)) {
            return false;
        }

        return $this->canManage($request) || ($me !== null && Employee::query()->whereKey($t->employee_id)->where('manager_id', $me)->exists());
    }

    private function employeeId(Request $request): int
    {
        return $this->me($request) ?? abort(422, __('You need an employee profile to keep a timesheet.'));
    }

    private function week(string $date, bool $mustBeMonday): Carbon
    {
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? Carbon::createFromFormat('!Y-m-d', $date) : false;
        if (! $day || $day->toDateString() !== $date) {
            throw ValidationException::withMessages(['week' => __('Enter a valid date.')]);
        }
        if ($mustBeMonday && ! $day->isMonday()) {
            throw ValidationException::withMessages(['week' => __('Weeks start on Monday.')]);
        }

        return $day->startOfWeek(Carbon::MONDAY);
    }

    /** Checks every entry belongs to an active project the employee is on, inside the week, within 24 hours a day. */
    private function resolveEntries(array $entries, Carbon $week, int $me): array
    {
        $projects = Project::query()->whereIn('public_id', array_unique(array_column($entries, 'project_id')))->get()->keyBy('public_id');
        $memberOf = ProjectMember::query()->where('employee_id', $me)->whereIn('project_id', $projects->pluck('id'))->pluck('project_id')->all();
        $tasks = ProjectTask::query()->whereIn('public_id', array_filter(array_column($entries, 'task_id')))->get()->keyBy('public_id');
        $end = $week->copy()->addDays(6);
        $rows = [];
        $perDay = [];
        foreach ($entries as $i => $e) {
            $project = $projects[$e['project_id']] ?? null;
            if (! $project || $project->status !== 'active' || ! (in_array($project->id, $memberOf, true) || (int) $project->manager_employee_id === $me)) {
                throw ValidationException::withMessages(["entries.{$i}.project_id" => __('Log time only to active projects you are a member of.')]);
            }
            $task = isset($e['task_id']) ? ($tasks[$e['task_id']] ?? null) : null;
            if (isset($e['task_id']) && (! $task || $task->project_id !== $project->id)) {
                throw ValidationException::withMessages(["entries.{$i}.task_id" => __('Choose a task from this project.')]);
            }
            $day = Carbon::parse($e['work_date']);
            if ($day->lt($week) || $day->gt($end)) {
                throw ValidationException::withMessages(["entries.{$i}.work_date" => __('The date must fall in this week.')]);
            }
            $perDay[$e['work_date']] = ($perDay[$e['work_date']] ?? 0) + $e['minutes'];
            if ($perDay[$e['work_date']] > 1440) {
                throw ValidationException::withMessages(["entries.{$i}.minutes" => __('A day cannot have more than 24 hours.')]);
            }
            $rows[] = ['project_id' => $project->id, 'project_task_id' => $task?->id, 'work_date' => $e['work_date'], 'minutes' => $e['minutes'], 'note' => $e['note'] ?? null];
        }

        return $rows;
    }

    private function summary(Timesheet $t, bool $withEmployee = false): array
    {
        return [
            'id' => $t->public_id, 'week_start' => $t->week_start->toDateString(), 'status' => $t->status, 'total_minutes' => $t->total_minutes,
            'submitted_at' => $t->submitted_at?->toIso8601String(), 'decided_at' => $t->decided_at?->toIso8601String(), 'decision_note' => $t->decision_note, 'version' => (int) $t->version,
        ] + ($withEmployee ? ['employee' => $this->person($t->employee)] : []);
    }

    private function detail(Timesheet $t): array
    {
        $t->loadMissing(['employee', 'decider', 'entries.project', 'entries.task']);

        return $this->summary($t, true) + [
            'decided_by' => $t->decider?->name,
            'entries' => $t->entries->sortBy(['work_date', 'id'])->map(fn ($e) => [
                'project' => ['id' => $e->project->public_id, 'code' => $e->project->code, 'name' => $e->project->name],
                'task' => $e->task ? ['id' => $e->task->public_id, 'title' => $e->task->title] : null,
                'work_date' => $e->work_date->toDateString(), 'minutes' => $e->minutes, 'note' => $e->note,
            ])->values(),
        ];
    }
}

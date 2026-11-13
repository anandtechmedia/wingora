<?php

namespace App\Http\Controllers\Api\V1\Performance;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PerformanceMeeting;
use App\Performance\PerformanceAccess;
use App\Security\Permission as P;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** One-to-ones between a manager and a report. Both see shared notes; private notes are only ever shown to the manager. */
class MeetingController extends Controller
{
    use Concerns;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(PerformanceAccess::any($user), 403);
        $data = $request->validate([
            'scope' => ['sometimes', 'in:mine,team,all'],
            'employee_id' => ['sometimes', 'string', 'max:26'],
            'status' => ['sometimes', Rule::in(PerformanceMeeting::STATUSES)],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $me = $user->employee?->id ?? 0;
        $query = $this->visibleQuery($user)->with(['employee', 'manager'])
            ->when(($data['scope'] ?? 'all') === 'mine', fn ($q) => $q->where(fn ($w) => $w->where('employee_id', $me)->orWhere('manager_employee_id', $me)))
            ->when(($data['scope'] ?? 'all') === 'team', fn ($q) => $q->where('employee_id', '!=', $me))
            ->when($data['employee_id'] ?? null, fn ($q, $id) => $q->whereIn('employee_id', Employee::query()->where('public_id', $id)->select('id')))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('scheduled_at', '>=', $d))
            ->when($data['to'] ?? null, fn ($q, $d) => $q->where('scheduled_at', '<', CarbonImmutable::parse($d)->addDay()));
        $page = $query->orderByRaw("status = 'scheduled' desc")->orderByDesc('scheduled_at')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($m) => $this->present($m, $request, false))->values(), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, PerformanceMeeting $meeting): JsonResponse
    {
        $this->visible($request, $meeting);

        return response()->json(['data' => $this->present($meeting, $request, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(PerformanceAccess::any($user), 403);
        $data = $this->validated($request, true);
        $employee = $this->visibleEmployee($request, $data['employee_id']);
        $me = $user->employee;
        if (PerformanceAccess::isSelf($user, $employee)) {
            abort_unless($employee->manager_id, 422, __('You need a reporting manager to schedule a one-to-one.'));
            $managerId = $employee->manager_id;
        } else {
            abort_unless(PerformanceAccess::canManage($user, $employee), 403);
            $managerId = $user->hasPermission(P::PerformanceManage) && $employee->manager_id && $employee->manager_id !== $me?->id ? $employee->manager_id : $me?->id;
            abort_unless($managerId, 409, __('Your account is not linked to an employee record.'));
        }
        $m = new PerformanceMeeting;
        $m->forceFill(['employee_id' => $employee->id, 'manager_employee_id' => $managerId, 'status' => 'scheduled', 'created_by' => $user->id]);
        $this->fill($m, $data, true);
        $m->save();

        return response()->json(['data' => $this->present($m->fresh(), $request, true)], 201);
    }

    public function update(Request $request, PerformanceMeeting $meeting): JsonResponse
    {
        $this->visible($request, $meeting);
        $data = $this->validated($request, false);
        $isManager = $this->isManagerParty($request, $meeting);
        abort_if(array_key_exists('private_notes', $data) && ! $isManager, 403, __('Only the manager can keep private notes.'));
        DB::transaction(function () use ($meeting, $data, $isManager) {
            $m = PerformanceMeeting::query()->lockForUpdate()->findOrFail($meeting->id);
            $this->checkVersion($m, (int) $data['version']);
            abort_if($m->status === 'cancelled' && ($data['status'] ?? null) !== 'scheduled', 422, __('This one-to-one was cancelled.'));
            $this->fill($m, $data, $isManager);
            $m->version = $m->version + 1;
            $m->save();
        });

        return response()->json(['data' => $this->present($meeting->fresh(), $request, true)]);
    }

    private function visibleQuery($user): Builder
    {
        $me = $user->employee?->id ?? 0;

        return PerformanceMeeting::query()->where(fn ($q) => $q->where('manager_employee_id', $me)
            ->orWhereIn('employee_id', PerformanceAccess::employees($user)->select('employees.id')));
    }

    private function visible(Request $request, PerformanceMeeting $meeting): void
    {
        abort_unless(PerformanceAccess::any($request->user()) && $this->visibleQuery($request->user())->whereKey($meeting->id)->exists(), 404);
    }

    private function isManagerParty(Request $request, PerformanceMeeting $m): bool
    {
        return $request->user()->employee !== null && $request->user()->employee->id === $m->manager_employee_id;
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'version' => [$creating ? 'prohibited' : 'required', 'integer'],
            'employee_id' => [$creating ? 'required' : 'prohibited', 'string', 'max:26'],
            'scheduled_at' => [$creating ? 'required' : 'sometimes', 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'between:5,480'],
            'agenda' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'shared_notes' => [$creating ? 'prohibited' : 'sometimes', 'nullable', 'string', 'max:20000'],
            'private_notes' => [$creating ? 'prohibited' : 'sometimes', 'nullable', 'string', 'max:20000'],
            'status' => [$creating ? 'prohibited' : 'sometimes', Rule::in(PerformanceMeeting::STATUSES)],
            'action_items' => ['sometimes', 'array', 'max:30'],
            'action_items.*.text' => ['required', 'string', 'max:300'],
            'action_items.*.owner' => ['required', Rule::in(['employee', 'manager'])],
            'action_items.*.done' => ['sometimes', 'boolean'],
        ]);
    }

    private function fill(PerformanceMeeting $m, array $data, bool $isManager): void
    {
        foreach (['scheduled_at', 'duration_minutes', 'agenda', 'shared_notes', 'status'] as $f) {
            if (array_key_exists($f, $data)) {
                $m->{$f} = $data[$f];
            }
        }
        if ($isManager && array_key_exists('private_notes', $data)) {
            $m->private_notes = $data['private_notes'];
        }
        if (array_key_exists('action_items', $data)) {
            $m->action_items = array_map(fn ($a) => ['text' => $a['text'], 'owner' => $a['owner'], 'done' => (bool) ($a['done'] ?? false)], array_values($data['action_items']));
        }
    }

    private function present(PerformanceMeeting $m, Request $request, bool $detail): array
    {
        $isManager = $this->isManagerParty($request, $m);
        $row = ['id' => $m->public_id, 'status' => $m->status, 'scheduled_at' => $m->scheduled_at?->toIso8601String(), 'duration_minutes' => $m->duration_minutes,
            'employee' => $this->person($m->employee), 'manager' => $this->person($m->manager),
            'open_actions' => collect($m->action_items ?? [])->where('done', false)->count(), 'is_manager' => $isManager, 'version' => $m->version];

        return $detail ? $row + ['agenda' => $m->agenda, 'shared_notes' => $m->shared_notes, 'private_notes' => $isManager ? $m->private_notes : null,
            'action_items' => $m->action_items ?? []] : $row;
    }
}

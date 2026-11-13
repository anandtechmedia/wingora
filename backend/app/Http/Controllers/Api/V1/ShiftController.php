<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\WorkSchedule;
use App\Security\Permission as P;
use App\Time\Approvers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Shifts, weekly work schedules and effective-dated schedule assignments. Writes need attendance.configure. */
class ShiftController extends Controller
{
    use ResolvesTimeEmployees;

    private const TIME = ['regex:/^([01]\d|2[0-3]):[0-5]\d$/'];

    public function shifts(Request $request): JsonResponse
    {
        $this->canRead($request);

        return response()->json(['data' => Shift::query()->orderBy('name')->get()->map($this->shift(...))]);
    }

    public function storeShift(Request $request): JsonResponse
    {
        $this->canWrite($request);
        $shift = new Shift($this->shiftRules($request));
        $shift->save();

        return response()->json(['data' => $this->shift($shift)], 201);
    }

    public function updateShift(Request $request, Shift $shift): JsonResponse
    {
        $this->canWrite($request);
        $shift->update($this->shiftRules($request, $shift));

        return response()->json(['data' => $this->shift($shift)]);
    }

    public function schedules(Request $request): JsonResponse
    {
        $this->canRead($request);
        $shifts = Shift::query()->get()->keyBy('id');

        return response()->json(['data' => WorkSchedule::query()->orderBy('name')->get()->map(fn ($s) => $this->schedule($s, $shifts))]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $this->canWrite($request);

        return response()->json(['data' => $this->saveSchedule($request, new WorkSchedule)], 201);
    }

    public function updateSchedule(Request $request, WorkSchedule $workSchedule): JsonResponse
    {
        $this->canWrite($request);

        return response()->json(['data' => $this->saveSchedule($request, $workSchedule)]);
    }

    public function assignments(Request $request, Employee $employee): JsonResponse
    {
        abort_unless(Approvers::canView($request->user(), $employee, P::AttendanceView, P::AttendanceApprove, P::AttendanceManage), 404);
        $rows = ShiftAssignment::query()->with('schedule')->where('employee_id', $employee->id)->orderByDesc('effective_from')->get();

        return response()->json(['data' => $rows->map($this->assignment(...))]);
    }

    /** A new assignment closes the open one the day before it starts; overlapping closed ranges are rejected. */
    public function assign(Request $request, Employee $employee): JsonResponse
    {
        $this->canWrite($request);
        Gate::authorize('view', $employee);
        $data = $request->validate([
            'work_schedule_id' => ['required', 'string', 'max:26'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
        ]);
        $schedule = WorkSchedule::query()->where('public_id', $data['work_schedule_id'])->where('status', 'active')->first()
            ?? throw ValidationException::withMessages(['work_schedule_id' => __('Unknown or inactive work schedule.')]);

        $assignment = DB::transaction(function () use ($employee, $schedule, $data, $request) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $from = $data['effective_from'];
            $to = $data['effective_to'] ?? null;
            $open = ShiftAssignment::query()->where('employee_id', $employee->id)->whereNull('effective_to')->where('effective_from', '<', $from)->first();
            $open?->forceFill(['effective_to' => date('Y-m-d', strtotime($from.' -1 day'))])->save();
            $overlap = ShiftAssignment::query()->where('employee_id', $employee->id)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from))
                ->when($to, fn ($q) => $q->where('effective_from', '<=', $to))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['effective_from' => __('This overlaps an existing schedule assignment.')]);
            }
            $a = new ShiftAssignment(['effective_from' => $from, 'effective_to' => $to]);
            $a->forceFill(['employee_id' => $employee->id, 'work_schedule_id' => $schedule->id, 'created_by' => $request->user()->id])->save();

            return $a->setRelation('schedule', $schedule);
        });

        return response()->json(['data' => $this->assignment($assignment)], 201);
    }

    private function saveSchedule(Request $request, WorkSchedule $schedule): array
    {
        $data = $request->validate([
            'name' => [$schedule->exists ? 'sometimes' : 'required', 'string', 'max:120'],
            'days' => [$schedule->exists ? 'sometimes' : 'required', 'array:1,2,3,4,5,6,7'],
            'days.*' => ['nullable', 'string', 'max:26'],
            'is_default' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
        if (array_key_exists('days', $data)) {
            $ids = array_filter($data['days']);
            $shifts = Shift::query()->whereIn('public_id', $ids)->where('status', 'active')->pluck('id', 'public_id');
            if ($shifts->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['days' => __('Every working day needs an active shift from this organization.')]);
            }
            $schedule->days = collect(range(1, 7))->mapWithKeys(fn ($d) => [(string) $d => isset($data['days'][$d]) && $data['days'][$d] ? $shifts[$data['days'][$d]] : null])->all();
        }
        DB::transaction(function () use ($schedule, $data) {
            if ($data['is_default'] ?? false) {
                WorkSchedule::query()->where('is_default', true)->whereKeyNot($schedule->id ?? 0)->get()->each(fn ($s) => $s->forceFill(['is_default' => false])->save());
            }
            $schedule->fill(collect($data)->only(['name', 'is_default', 'status'])->all())->save();
        });

        return $this->schedule($schedule, Shift::query()->get()->keyBy('id'));
    }

    private function shiftRules(Request $request, ?Shift $existing = null): array
    {
        $req = $existing ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$req, 'string', 'max:120'],
            'code' => [$req, 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('shifts')->where('tenant_id', $request->user()->tenant_id)->ignore($existing?->id)],
            'start_time' => [$req, ...self::TIME],
            'end_time' => [$req, ...self::TIME, 'different:start_time'],
            'break_minutes' => ['sometimes', 'integer', 'between:0,240'],
            'late_grace_minutes' => ['sometimes', 'integer', 'between:0,240'],
            'early_grace_minutes' => ['sometimes', 'integer', 'between:0,240'],
            'overtime_after_minutes' => ['sometimes', 'nullable', 'integer', 'between:0,1440'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);
    }

    private function canRead(Request $request): void
    {
        $u = $request->user();
        abort_unless($u->hasPermission(P::AttendanceConfigure) || $u->hasPermission(P::AttendanceManage) || $u->hasPermission(P::AttendanceApprove), 403);
    }

    private function canWrite(Request $request): void
    {
        abort_unless($request->user()->hasPermission(P::AttendanceConfigure), 403);
    }

    private function shift(Shift $s): array
    {
        return ['id' => $s->public_id, 'name' => $s->name, 'code' => $s->code, 'start_time' => $s->start_time, 'end_time' => $s->end_time,
            'overnight' => $s->end_time <= $s->start_time, 'break_minutes' => $s->break_minutes, 'late_grace_minutes' => $s->late_grace_minutes,
            'early_grace_minutes' => $s->early_grace_minutes, 'overtime_after_minutes' => $s->overtime_after_minutes, 'status' => $s->status];
    }

    private function schedule(WorkSchedule $s, $shifts): array
    {
        return ['id' => $s->public_id, 'name' => $s->name, 'is_default' => $s->is_default, 'status' => $s->status,
            'days' => collect(range(1, 7))->mapWithKeys(fn ($d) => [(string) $d => ($id = $s->shiftIdFor($d)) && isset($shifts[$id]) ? ['id' => $shifts[$id]->public_id, 'name' => $shifts[$id]->name] : null])->all()];
    }

    private function assignment(ShiftAssignment $a): array
    {
        return ['id' => $a->public_id, 'schedule' => ['id' => $a->schedule->public_id, 'name' => $a->schedule->name],
            'effective_from' => $a->effective_from->toDateString(), 'effective_to' => $a->effective_to?->toDateString()];
    }
}

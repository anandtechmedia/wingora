<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditEvents;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Security\Permission as P;
use App\Support\Csv;
use App\Support\Tenancy;
use App\Time\Approvers;
use App\Time\AttendanceService;
use App\Time\HolidayService;
use App\Time\ScheduleResolver;
use App\Time\TimeZones;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Clock in/out, attendance records, HR corrections and the attendance report. */
class AttendanceController extends Controller
{
    use ResolvesTimeEmployees;

    public function me(Request $request, ScheduleResolver $schedules): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::AttendanceView), 403);
        $employee = $this->self($request);
        $zone = TimeZones::for($employee);
        $today = TimeZones::today($employee);
        $shift = $schedules->shiftFor($employee, $today);
        $record = AttendanceRecord::query()->where('employee_id', $employee->id)
            ->where(fn ($q) => $q->where('open_employee_id', $employee->id)->orWhere('work_date', $today))->orderByDesc('clock_in_at')->first();

        return response()->json(['data' => [
            'employee' => $this->person($employee), 'timezone' => $zone, 'today' => $today,
            'shift' => $shift ? ['name' => $shift->name, 'start_time' => $shift->start_time, 'end_time' => $shift->end_time] : null,
            'record' => $record ? $this->present($record) : null,
            'can_clock' => $request->user()->hasPermission(P::AttendanceClock),
        ]]);
    }

    public function clockIn(Request $request, AttendanceService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::AttendanceClock), 403);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);
        $employee = $this->self($request);
        abort_unless(in_array($employee->status, ['active', 'on_leave'], true), 409, __('Only active employees can clock in.'));

        return response()->json(['data' => $this->present($service->clockIn($employee, $data['note'] ?? null))], 201);
    }

    public function clockOut(Request $request, AttendanceService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::AttendanceClock), 403);

        return response()->json(['data' => $this->present($service->clockOut($this->self($request)))]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $this->range($request, ['employee' => ['sometimes', 'string', 'max:26'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $user = $request->user();
        abort_unless($this->canSeeAnyone($user, P::AttendanceView, P::AttendanceApprove, P::AttendanceManage), 403);
        $visible = isset($data['employee'])
            ? [$this->target($request, $data['employee'], P::AttendanceView, P::AttendanceApprove, P::AttendanceManage)->id]
            : Approvers::visibleEmployees($user, P::AttendanceView, P::AttendanceApprove, P::AttendanceManage)->select('employees.id');

        $page = AttendanceRecord::query()->with(['employee', 'shift'])->whereIn('employee_id', $visible)
            ->where('work_date', '>=', $data['from'])->where('work_date', '<', CarbonImmutable::parse($data['to'])->addDay()->toDateString())
            ->orderByDesc('work_date')->orderBy('employee_id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map($this->present(...)), 'meta' => $this->meta($page)]);
    }

    /** HR manual correction (create or replace a day's record). Requires attendance.manage; never on one's own record. */
    public function correct(Request $request, AttendanceService $service): JsonResponse
    {
        $data = $request->validate([
            'employee' => ['required', 'string', 'max:26'],
            'work_date' => ['required', 'date_format:Y-m-d'],
            'clock_in' => ['required', 'date_format:Y-m-d H:i'],
            'clock_out' => ['nullable', 'date_format:Y-m-d H:i'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $employee = $this->target($request, $data['employee'], P::AttendanceView, P::AttendanceApprove, P::AttendanceManage);
        abort_unless(Approvers::canDecide($request->user(), $employee, P::AttendanceManage, P::AttendanceManage), 403);
        $zone = TimeZones::for($employee);
        $record = $service->correct($employee, $data['work_date'], TimeZones::parseLocal($data['clock_in'], $zone, 'clock_in'),
            isset($data['clock_out']) ? TimeZones::parseLocal($data['clock_out'], $zone, 'clock_out') : null, $data['reason'], $request->user());

        return response()->json(['data' => $this->present($record->load('employee'))]);
    }

    public function report(Request $request, ScheduleResolver $schedules, HolidayService $holidays): JsonResponse|StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::AttendanceApprove) || $user->hasPermission(P::AttendanceManage), 403);
        $data = $this->range($request, [
            'department' => ['sometimes', 'string', 'max:26'], 'legal_entity' => ['sometimes', 'string', 'max:26'], 'search' => ['sometimes', 'string', 'max:100'],
            'format' => ['sometimes', 'in:json,csv'], 'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $employees = Approvers::visibleEmployees($user, P::AttendanceView, P::AttendanceApprove, P::AttendanceManage)->with(['workLocation', 'department'])
            ->when($data['department'] ?? null, fn ($q, $id) => $q->whereHas('department', fn ($d) => $d->where('public_id', $id)))
            ->when($data['legal_entity'] ?? null, fn ($q, $id) => $q->whereHas('legalEntity', fn ($d) => $d->where('public_id', $id)))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")->orWhere('employee_number', 'like', "%{$s}%")))
            ->orderBy('last_name')->orderBy('id');

        $build = function ($list) use ($data, $schedules, $holidays) {
            $ids = $list->pluck('id');
            $schedules->preload($list, $data['from'], $data['to']);
            $end = CarbonImmutable::parse($data['to'])->addDay()->toDateString();
            $records = AttendanceRecord::query()->whereIn('employee_id', $ids)->where('work_date', '>=', $data['from'])->where('work_date', '<', $end)->get()->groupBy('employee_id');
            $leave = LeaveRequest::query()->whereIn('employee_id', $ids)->where('status', 'approved')->where('start_date', '<=', $data['to'])->where('end_date', '>=', $data['from'])->get()->groupBy('employee_id');

            return $list->map(function (Employee $e) use ($records, $leave, $data, $schedules, $holidays) {
                $rows = $records->get($e->id, collect());
                $present = $rows->map(fn ($r) => $r->work_date->toDateString())->flip();
                $off = $holidays->daysOff($e->workLocation, $data['from'], $data['to']);
                $today = TimeZones::today($e);
                $absent = 0;
                foreach (CarbonPeriod::create($data['from'], min($data['to'], $today)) as $day) {
                    $d = $day->toDateString();
                    if ($d < $today && ! isset($present[$d]) && ! isset($off[$d]) && $schedules->isWorkingDay($e, $d)
                        && ! $leave->get($e->id, collect())->contains(fn ($l) => $l->start_date->toDateString() <= $d && $l->end_date->toDateString() >= $d)) {
                        $absent++;
                    }
                }

                return ['employee' => $this->person($e), 'department' => $e->department?->name, 'days_present' => $rows->count(), 'days_absent' => $absent,
                    'late_count' => $rows->where('late_minutes', '>', 0)->count(), 'late_minutes' => $rows->sum('late_minutes'),
                    'early_count' => $rows->where('early_minutes', '>', 0)->count(), 'early_minutes' => $rows->sum('early_minutes'),
                    'worked_minutes' => $rows->sum('worked_minutes'), 'overtime_minutes' => $rows->sum('overtime_minutes')];
            })->values();
        };

        if (($data['format'] ?? 'json') === 'csv') {
            AuditLog::record(AuditEvents::DATA_EXPORTED, metadata: ['export' => 'attendance_report', 'from' => $data['from'], 'to' => $data['to']]);
            $tenant = Tenancy::current();

            return response()->streamDownload(function () use ($employees, $build, $tenant) {
                Tenancy::run($tenant, function () use ($employees, $build) {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['employee_number', 'name', 'department', 'days_present', 'days_absent', 'late_count', 'late_minutes', 'early_count', 'early_minutes', 'worked_minutes', 'overtime_minutes']);
                    $employees->chunk(200, function ($chunk) use ($out, $build) {
                        foreach ($build($chunk) as $r) {
                            fputcsv($out, array_map(Csv::cell(...), [$r['employee']['employee_number'], $r['employee']['name'], $r['department'], $r['days_present'], $r['days_absent'],
                                $r['late_count'], $r['late_minutes'], $r['early_count'], $r['early_minutes'], $r['worked_minutes'], $r['overtime_minutes']]));
                        }
                    });
                    fclose($out);
                });
            }, "attendance-{$data['from']}-{$data['to']}.csv", ['Content-Type' => 'text/csv']);
        }

        $page = $employees->paginate(25);

        return response()->json(['data' => $build($page->getCollection()), 'meta' => $this->meta($page)]);
    }

    /** from/to default to the last 30 days; at most 92 days. */
    private function range(Request $request, array $rules): array
    {
        $data = $request->validate($rules + ['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $data['to'] ??= now()->toDateString();
        $data['from'] ??= CarbonImmutable::parse($data['to'])->subDays(29)->toDateString();
        abort_if($data['from'] > $data['to'], 422, __('The start date must be before the end date.'));
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 92) {
            throw ValidationException::withMessages(['to' => __('The range may be at most 92 days.')]);
        }

        return $data;
    }

    public function present(AttendanceRecord $r): array
    {
        $local = fn (?CarbonImmutable $t) => $t?->setTimezone($r->timezone)->format('Y-m-d H:i');

        return [
            'id' => $r->public_id, 'employee' => $this->person($r->employee), 'work_date' => $r->work_date->toDateString(), 'timezone' => $r->timezone,
            'clock_in_at' => $r->clock_in_at->toIso8601String(), 'clock_out_at' => $r->clock_out_at?->toIso8601String(),
            'clock_in_local' => $local($r->clock_in_at), 'clock_out_local' => $local($r->clock_out_at),
            'shift' => $r->shift ? ['name' => $r->shift->name, 'start_local' => $local($r->scheduled_start_at), 'end_local' => $local($r->scheduled_end_at)] : null,
            'worked_minutes' => $r->worked_minutes, 'late_minutes' => $r->late_minutes, 'early_minutes' => $r->early_minutes, 'overtime_minutes' => $r->overtime_minutes,
            'source' => $r->source, 'note' => $r->note, 'open' => $r->clock_out_at === null,
        ];
    }
}

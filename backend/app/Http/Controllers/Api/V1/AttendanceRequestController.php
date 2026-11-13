<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRequest;
use App\Security\Permission as P;
use App\Time\Approvers;
use App\Time\AttendanceService;
use App\Time\TimeZones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Employee attendance correction requests and their manager/HR decisions. */
class AttendanceRequestController extends Controller
{
    use ResolvesTimeEmployees;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canSeeAnyone($user, P::AttendanceView, P::AttendanceApprove, P::AttendanceManage), 403);
        $data = $request->validate(['status' => ['sometimes', Rule::in(AttendanceRequest::STATUSES)], 'scope' => ['sometimes', 'in:mine,approvals,all'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $scope = $data['scope'] ?? 'all';
        $query = AttendanceRequest::query()->with(['employee', 'decider:id,name']);
        if ($scope === 'mine') {
            $query->where('employee_id', $this->self($request)->id);
        } else {
            $query->whereIn('employee_id', Approvers::visibleEmployees($user, $scope === 'approvals' ? P::AttendanceManage : P::AttendanceView, P::AttendanceApprove, P::AttendanceManage)->select('employees.id'));
            if ($scope === 'approvals') {
                $query->where('status', 'pending')->when($user->employee, fn ($q, $me) => $q->where('employee_id', '!=', $me->id));
            }
        }
        $page = $query->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($r) => $this->present($r, $user)), 'meta' => $this->meta($page)]);
    }

    public function store(Request $request, AttendanceService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::AttendanceClock), 403);
        $data = $request->validate([
            'work_date' => ['required', 'date_format:Y-m-d'],
            'clock_in' => ['required', 'date_format:Y-m-d H:i'],
            'clock_out' => ['nullable', 'date_format:Y-m-d H:i'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $employee = $this->self($request);
        $zone = TimeZones::for($employee);
        $item = $service->submitRequest($employee, $data['work_date'], TimeZones::parseLocal($data['clock_in'], $zone, 'clock_in'),
            isset($data['clock_out']) ? TimeZones::parseLocal($data['clock_out'], $zone, 'clock_out') : null, $data['reason'], $request->user());

        return response()->json(['data' => $this->present($item->load('employee'), $request->user())], 201);
    }

    public function approve(Request $request, AttendanceRequest $attendanceRequest, AttendanceService $service): JsonResponse
    {
        return $this->decide($request, $attendanceRequest, $service, true);
    }

    public function reject(Request $request, AttendanceRequest $attendanceRequest, AttendanceService $service): JsonResponse
    {
        return $this->decide($request, $attendanceRequest, $service, false);
    }

    public function cancel(Request $request, AttendanceRequest $attendanceRequest, AttendanceService $service): JsonResponse
    {
        $this->visible($request, $attendanceRequest);

        return response()->json(['data' => $this->present($service->cancelRequest($attendanceRequest, $request->user())->load('employee'), $request->user())]);
    }

    private function decide(Request $request, AttendanceRequest $item, AttendanceService $service, bool $approve): JsonResponse
    {
        $this->visible($request, $item);
        $note = $request->validate(['note' => [$approve ? 'nullable' : 'required', 'string', 'max:1000']])['note'] ?? null;

        return response()->json(['data' => $this->present($service->decide($item, $request->user(), $approve, $note)->load(['employee', 'decider:id,name']), $request->user())]);
    }

    private function visible(Request $request, AttendanceRequest $item): void
    {
        abort_unless(Approvers::canView($request->user(), $item->employee, P::AttendanceView, P::AttendanceApprove, P::AttendanceManage), 404);
    }

    private function present(AttendanceRequest $r, $user): array
    {
        $zone = TimeZones::for($r->employee);

        return [
            'id' => $r->public_id, 'employee' => $this->person($r->employee), 'work_date' => $r->work_date->toDateString(), 'timezone' => $zone,
            'clock_in_local' => $r->clock_in_at->setTimezone($zone)->format('Y-m-d H:i'), 'clock_out_local' => $r->clock_out_at?->setTimezone($zone)->format('Y-m-d H:i'),
            'reason' => $r->reason, 'status' => $r->status, 'decided_by' => $r->decider?->name, 'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_note' => $r->decision_note, 'created_at' => $r->created_at?->toIso8601String(),
            'can_decide' => $r->status === 'pending' && Approvers::canDecide($user, $r->employee, P::AttendanceApprove, P::AttendanceManage),
            'can_cancel' => $r->status === 'pending' && ($user->employee?->id === $r->employee_id || $user->hasPermission(P::AttendanceManage)),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\LeaveLedgerEntry;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\StoredFile;
use App\Security\Permission as P;
use App\Time\Approvers;
use App\Time\HolidayService;
use App\Time\LeaveService;
use App\Time\TimeZones;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Balances, ledger history, adjustments, leave requests with approval workflow, and the employee's holidays. */
class LeaveController extends Controller
{
    use ResolvesTimeEmployees;

    public function balances(Request $request, LeaveService $service): JsonResponse
    {
        $data = $request->validate(['employee' => ['sometimes', 'string', 'max:26'], 'year' => ['sometimes', 'integer', 'between:2000,2100']]);
        $employee = $this->target($request, $data['employee'] ?? null, P::LeaveView, P::LeaveApprove, P::LeaveManage)->loadMissing('legalEntity');
        $year = (int) ($data['year'] ?? substr(TimeZones::today($employee), 0, 4));
        $types = LeaveType::query()->where('status', 'active')->orderBy('name')->get()->filter(fn ($t) => $service->appliesTo($t, $employee));

        return response()->json(['data' => $types->map(fn ($t) => ['leave_type' => $this->type($t), 'year' => $year] + $service->balance($employee, $t, $year))->values(),
            'meta' => ['employee' => $this->person($employee), 'year' => $year]]);
    }

    public function ledger(Request $request): JsonResponse
    {
        $data = $request->validate(['employee' => ['sometimes', 'string', 'max:26'], 'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'leave_type' => ['sometimes', 'string', 'max:26'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $employee = $this->target($request, $data['employee'] ?? null, P::LeaveView, P::LeaveApprove, P::LeaveManage);
        $page = LeaveLedgerEntry::query()->with(['leaveType', 'request', 'author:id,name'])->where('employee_id', $employee->id)
            ->when($data['year'] ?? null, fn ($q, $y) => $q->where('period_year', $y))
            ->when($data['leave_type'] ?? null, fn ($q, $t) => $q->whereHas('leaveType', fn ($q) => $q->where('public_id', $t)))
            ->orderByDesc('effective_on')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn (LeaveLedgerEntry $e) => [
            'id' => $e->public_id, 'leave_type' => $this->type($e->leaveType), 'year' => $e->period_year, 'entry_type' => $e->entry_type, 'amount' => $e->amount,
            'effective_on' => $e->effective_on->toDateString(), 'expires_on' => $e->expires_on?->toDateString(), 'request' => $e->request?->public_id,
            'note' => $e->note, 'author' => $e->author?->name, 'created_at' => $e->created_at?->toIso8601String(),
        ]), 'meta' => $this->meta($page)]);
    }

    public function adjust(Request $request, LeaveService $service): JsonResponse
    {
        $data = $request->validate(['employee' => ['required', 'string', 'max:26'], 'leave_type' => ['required', 'string', 'max:26'],
            'year' => ['required', 'integer', 'between:2000,2100'], 'amount' => ['required', 'numeric', 'between:-366,366', 'not_in:0', 'multiple_of:0.25'],
            'note' => ['required', 'string', 'min:5', 'max:500']]);
        $employee = $this->target($request, $data['employee'], P::LeaveView, P::LeaveApprove, P::LeaveManage);
        abort_unless(Approvers::canDecide($request->user(), $employee, P::LeaveManage, P::LeaveManage), 403);
        $type = $this->findType($data['leave_type'], 'leave_type');
        $entry = $service->adjust($employee, $type, (int) $data['year'], (float) $data['amount'], $data['note'], $request->user());

        return response()->json(['data' => ['id' => $entry->public_id, 'amount' => $entry->amount]], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canSeeAnyone($user, P::LeaveView, P::LeaveApprove, P::LeaveManage), 403);
        $data = $request->validate(['status' => ['sometimes', Rule::in(LeaveRequest::STATUSES)], 'scope' => ['sometimes', 'in:mine,approvals,all'],
            'employee' => ['sometimes', 'string', 'max:26'], 'from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $scope = $data['scope'] ?? 'all';
        $query = LeaveRequest::query()->with(['employee', 'leaveType', 'approvals.decider:id,name']);
        if ($scope === 'mine') {
            $query->where('employee_id', $this->self($request)->id);
        } elseif (isset($data['employee'])) {
            $query->where('employee_id', $this->target($request, $data['employee'], P::LeaveView, P::LeaveApprove, P::LeaveManage)->id);
        } else {
            $query->whereIn('employee_id', Approvers::visibleEmployees($user, $scope === 'approvals' ? P::LeaveManage : P::LeaveView, P::LeaveApprove, P::LeaveManage)->select('employees.id'));
        }
        if ($scope === 'approvals') {
            $query->where('status', 'pending')->when($user->employee, fn ($q, $me) => $q->where('employee_id', '!=', $me->id))
                ->when(! $user->hasPermission(P::LeaveManage), fn ($q) => $q->where('current_step', 1));
        }
        $page = $query->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['from'] ?? null, fn ($q, $d) => $q->where('end_date', '>=', $d))->when($data['to'] ?? null, fn ($q, $d) => $q->where('start_date', '<=', $d))
            ->orderByDesc('start_date')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($r) => $this->present($r, $user)), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $this->visible($request, $leaveRequest);

        return response()->json(['data' => $this->present($leaveRequest->load(['employee', 'leaveType', 'approvals.decider:id,name']), $request->user())]);
    }

    /** Preview the working days a request would use, without creating it. */
    public function preview(Request $request, LeaveService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::LeaveApply), 403);
        $data = $request->validate(['start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before:'.date('Y-m-d', strtotime($request->input('start_date').' +1 year'))],
            'half_day' => ['nullable', Rule::in(LeaveRequest::HALF_DAYS)]]);

        return response()->json(['data' => ['days' => $service->countDays($this->self($request), $data['start_date'], $data['end_date'], $data['half_day'] ?? null)]]);
    }

    public function store(Request $request, LeaveService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::LeaveApply), 403);
        $data = $request->validate([
            'leave_type' => ['required', 'string', 'max:26'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'half_day' => ['nullable', Rule::in(LeaveRequest::HALF_DAYS)],
            'reason' => ['nullable', 'string', 'max:1000'],
            'document_id' => ['nullable', 'string', 'max:26'],
        ]);
        $employee = $this->self($request)->loadMissing('legalEntity');
        $type = $this->findType($data['leave_type'], 'leave_type');
        if (! empty($data['document_id'])) {
            $data['document_id'] = StoredFile::query()->where('public_id', $data['document_id'])->where('employee_id', $employee->id)->value('id')
                ?? throw ValidationException::withMessages(['document_id' => __('Unknown document.')]);
        }
        $item = $service->apply($employee, $type, $data, $request->user());

        return response()->json(['data' => $this->present($item->load(['employee', 'leaveType', 'approvals']), $request->user())], 201);
    }

    public function approve(Request $request, LeaveRequest $leaveRequest, LeaveService $service): JsonResponse
    {
        return $this->decide($request, $leaveRequest, $service, true);
    }

    public function reject(Request $request, LeaveRequest $leaveRequest, LeaveService $service): JsonResponse
    {
        return $this->decide($request, $leaveRequest, $service, false);
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest, LeaveService $service): JsonResponse
    {
        $this->visible($request, $leaveRequest);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:1000']])['reason'] ?? null;

        return response()->json(['data' => $this->present($service->cancel($leaveRequest, $request->user(), $reason)->load(['employee', 'leaveType', 'approvals.decider:id,name']), $request->user())]);
    }

    public function holidays(Request $request, HolidayService $holidays): JsonResponse
    {
        $data = $request->validate(['employee' => ['sometimes', 'string', 'max:26'], 'year' => ['required', 'integer', 'between:2000,2100']]);
        $employee = $this->target($request, $data['employee'] ?? null, P::LeaveView, P::LeaveApprove, P::LeaveManage);
        $location = $employee->workLocation;

        return response()->json(['data' => $location ? $holidays->forLocation($location, "{$data['year']}-01-01", "{$data['year']}-12-31") : [],
            'meta' => ['work_location' => $location?->name, 'timezone' => TimeZones::for($employee)]]);
    }

    private function decide(Request $request, LeaveRequest $item, LeaveService $service, bool $approve): JsonResponse
    {
        $this->visible($request, $item);
        $note = $request->validate(['note' => [$approve ? 'nullable' : 'required', 'string', 'max:1000']])['note'] ?? null;

        return response()->json(['data' => $this->present($service->decide($item, $request->user(), $approve, $note)->load(['employee', 'leaveType', 'approvals.decider:id,name']), $request->user())]);
    }

    private function visible(Request $request, LeaveRequest $item): void
    {
        abort_unless(Approvers::canView($request->user(), $item->employee, P::LeaveView, P::LeaveApprove, P::LeaveManage), 404);
    }

    private function findType(string $publicId, string $field): LeaveType
    {
        return LeaveType::query()->where('public_id', $publicId)->first() ?? throw ValidationException::withMessages([$field => __('Unknown leave type.')]);
    }

    private function type(LeaveType $t): array
    {
        return ['id' => $t->public_id, 'name' => $t->name, 'code' => $t->code, 'is_paid' => $t->is_paid];
    }

    private function present(LeaveRequest $r, $user): array
    {
        $own = $user->employee?->id === $r->employee_id;
        $hr = $user->hasPermission(P::LeaveManage);
        $canDecide = $r->status === 'pending' && ($r->current_step === 1
            ? Approvers::canDecide($user, $r->employee, P::LeaveApprove, P::LeaveManage)
            : $hr && ! $own && ! $r->approvals->contains('decided_by', $user->id));

        return [
            'id' => $r->public_id, 'employee' => $this->person($r->employee), 'leave_type' => $this->type($r->leaveType),
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(), 'half_day' => $r->half_day, 'days' => $r->days,
            'reason' => $r->reason, 'status' => $r->status, 'current_step' => $r->current_step, 'approval_steps' => $r->leaveType->approval_steps,
            'cancellation_reason' => $r->cancellation_reason, 'created_at' => $r->created_at?->toIso8601String(),
            'approvals' => $r->approvals->map(fn ($a) => ['step' => $a->step, 'decision' => $a->decision, 'by' => $a->decider?->name, 'note' => $a->note, 'at' => $a->decided_at->toIso8601String()])->values(),
            'can_decide' => $canDecide,
            'can_cancel' => in_array($r->status, ['pending', 'approved'], true) && ($hr || ($own && ($r->status === 'pending' || $r->start_date->toDateString() > TimeZones::today($r->employee)))),
        ];
    }
}

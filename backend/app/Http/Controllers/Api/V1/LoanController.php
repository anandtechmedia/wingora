<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditEvents;
use App\Http\Controllers\Api\V1\Concerns\PresentsPayroll;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Loan;
use App\Payroll\Money;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Employee loans/advances repaid through approved runs. Payroll staff manage them; employees see their own. */
class LoanController extends Controller
{
    use PresentsPayroll, ResolvesTimeEmployees;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['scope' => ['sometimes', 'in:mine,all'], 'status' => ['sometimes', 'in:active,closed,cancelled'], 'employee' => ['sometimes', 'string', 'max:26'],
            'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $mine = ($data['scope'] ?? 'all') === 'mine' || ! $user->hasPermission(P::PayrollView);
        abort_unless($mine ? $user->hasPermission(P::PayslipsViewSelf) : true, 403);
        $page = Loan::query()->with('employee')->withSum('repayments', 'amount_minor')
            ->when($mine, fn ($q) => $q->where('employee_id', $this->self($request)->id))
            ->when(! $mine && isset($data['employee']), fn ($q) => $q->whereHas('employee', fn ($q) => $q->where('public_id', $data['employee'])))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($l) => $this->present($l)), 'meta' => $this->meta($page)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PayrollManage), 403);
        $data = $request->validate(['employee' => ['required', 'string', 'max:26'], 'principal' => ['required', 'string', 'max:20'], 'installment' => ['required', 'string', 'max:20'],
            'first_deduction_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        $employee = Employee::query()->where('public_id', $data['employee'])->with('legalEntity')->first() ?? throw ValidationException::withMessages(['employee' => __('Unknown employee.')]);
        $currency = $employee->legalEntity?->currency_code ?? throw ValidationException::withMessages(['employee' => __('Assign the employee to a legal entity first.')]);
        try {
            $principal = Money::parse($data['principal'], $currency);
            $installment = Money::parse($data['installment'], $currency);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['principal' => $e->getMessage()]);
        }
        if ($principal <= 0 || $installment <= 0 || $installment > $principal) {
            throw ValidationException::withMessages(['installment' => __('The instalment must be positive and no more than the principal.')]);
        }
        $loan = new Loan(['principal_minor' => $principal, 'installment_minor' => $installment, 'first_deduction_on' => $data['first_deduction_on'], 'reason' => $data['reason']]);
        $loan->forceFill(['tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id, 'currency' => $currency, 'created_by' => $request->user()->id])->saveQuietly();
        AuditLog::record(AuditEvents::LOAN_CREATED, $loan, new: ['principal' => '[REDACTED]', 'installment' => '[REDACTED]', 'first_deduction_on' => $loan->first_deduction_on?->toDateString()], metadata: ['employee' => $employee->public_id]);

        return response()->json(['data' => $this->present($loan->loadSum('repayments', 'amount_minor')->load('employee'))], 201);
    }

    public function cancel(Request $request, Loan $loan): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PayrollManage), 403);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        abort_unless($loan->status === 'active', 409, __('Only active loans can be cancelled.'));
        $loan->forceFill(['status' => 'cancelled'])->saveQuietly();
        AuditLog::record(AuditEvents::LOAN_CANCELLED, $loan, metadata: ['reason' => $data['reason']]);

        return response()->json(['data' => $this->present($loan->loadSum('repayments', 'amount_minor')->load('employee'))]);
    }

    private function present(Loan $l): array
    {
        $repaid = (int) $l->repayments_sum_amount_minor;

        return ['id' => $l->public_id, 'employee' => $this->person($l->employee), 'currency' => $l->currency, 'principal' => $this->money($l->principal_minor, $l->currency),
            'installment' => $this->money($l->installment_minor, $l->currency), 'repaid' => $this->money($repaid, $l->currency),
            'outstanding' => $this->money($l->principal_minor - $repaid, $l->currency), 'first_deduction_on' => $l->first_deduction_on?->toDateString(), 'status' => $l->status, 'reason' => $l->reason];
    }
}

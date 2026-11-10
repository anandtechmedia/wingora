<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditEvents;
use App\Http\Controllers\Api\V1\Concerns\PresentsPayroll;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CompensationRecord;
use App\Models\Employee;
use App\Models\EmployeeCompensation;
use App\Models\SalaryStructure;
use App\Payroll\Money;
use App\Payroll\PayrollCalculator;
use App\Security\Permission as P;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Effective-dated compensation history. Restricted to payroll.view (read) and payroll.manage (write). */
class CompensationController extends Controller
{
    use PresentsPayroll, ResolvesTimeEmployees;

    /** Employees with their compensation effective on `date` (default today). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PayrollView), 403);
        $data = $request->validate(['search' => ['sometimes', 'string', 'max:100'], 'legal_entity' => ['sometimes', 'string', 'max:26'],
            'date' => ['sometimes', 'date_format:Y-m-d'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $date = $data['date'] ?? now()->toDateString();
        $page = Employee::query()->with(['legalEntity:id,public_id,name,currency_code',
            'compensationRecords' => fn ($q) => $q->overlapping($date, $date)->with('structure:id,public_id,name,code')])
            ->when($data['legal_entity'] ?? null, fn ($q, $e) => $q->whereHas('legalEntity', fn ($q) => $q->where('public_id', $e)))
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%")->orWhere('employee_number', 'like', "%{$s}%")))
            ->orderBy('last_name')->orderBy('first_name')->orderBy('id')->paginate((int) ($data['per_page'] ?? 25));
        AuditLog::record('payroll.compensation_listed', metadata: ['date' => $date, 'rows' => $page->count()]);

        return response()->json(['data' => $page->getCollection()->map(fn (Employee $e) => [
            'employee' => $this->person($e), 'legal_entity' => $e->legalEntity?->name,
            'current' => ($r = $e->compensationRecords->first()) ? $this->present($r) : null,
        ]), 'meta' => $this->meta($page) + ['date' => $date]]);
    }

    public function history(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PayrollView), 403);
        $records = $employee->compensationRecords()->with(['structure:id,public_id,name,code', 'creator:id,name'])->orderByDesc('effective_from')->get();
        AuditLog::record('payroll.compensation_viewed', $employee, metadata: ['records' => $records->count()]);

        return response()->json(['data' => $records->map(fn ($r) => $this->present($r)), 'meta' => ['employee' => $this->person($employee)]]);
    }

    /**
     * Adds a record effective from a date. The previous open record is closed the day before. Records cannot be
     * inserted before an existing later record; correct history by adding a new record with a reason.
     */
    public function store(Request $request, Employee $employee): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PayrollManage), 403);
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20'], 'basis' => ['required', Rule::in(CompensationRecord::BASES)],
            'pay_frequency' => ['required', Rule::in(CompensationRecord::FREQUENCIES)], 'effective_from' => ['required', 'date_format:Y-m-d'],
            'salary_structure' => ['nullable', 'string', 'max:26'], 'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $entity = $employee->legalEntity ?? throw ValidationException::withMessages(['employee' => __('Assign the employee to a legal entity first.')]);
        $currency = $entity->currency_code;
        try {
            $amount = Money::parse($data['amount'], $currency);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be positive.')]);
        }
        $structureId = null;
        if (! empty($data['salary_structure'])) {
            $structure = SalaryStructure::query()->where('public_id', $data['salary_structure'])->where('legal_entity_id', $entity->id)->where('status', 'active')->first();
            $structureId = $structure?->id ?? throw ValidationException::withMessages(['salary_structure' => __('Choose an active structure of the employee\'s legal entity.')]);
        }

        $record = DB::transaction(function () use ($employee, $entity, $currency, $amount, $data, $structureId, $request) {
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            if ($employee->compensationRecords()->where('effective_from', '>=', $data['effective_from'])->exists()) {
                throw ValidationException::withMessages(['effective_from' => __('A record already starts on or after this date.')]);
            }
            $previous = $employee->compensationRecords()->whereNull('effective_to')->first();
            $old = null;
            if ($previous) {
                $old = ['amount' => '[REDACTED]', 'basis' => $previous->basis, 'effective_from' => $previous->effective_from?->toDateString()];
                $previous->forceFill(['effective_to' => CarbonImmutable::parse($data['effective_from'])->subDay()->toDateString()])->saveQuietly();
            }
            $record = new CompensationRecord(['amount_minor' => $amount, 'basis' => $data['basis'], 'pay_frequency' => $data['pay_frequency'],
                'effective_from' => $data['effective_from'], 'reason' => $data['reason']]);
            $record->forceFill(['tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id, 'legal_entity_id' => $entity->id, 'currency' => $currency, 'salary_structure_id' => $structureId,
                'created_by' => $request->user()->id])->saveQuietly();
            AuditLog::record(AuditEvents::COMPENSATION_CHANGED, $employee, $old ?? [], ['amount' => '[REDACTED]', 'basis' => $record->basis, 'effective_from' => $record->effective_from?->toDateString(),
                'pay_frequency' => $record->pay_frequency], ['reason' => $data['reason'], 'currency' => $currency]);
            $this->syncLegacy($employee, $record);

            return $record;
        });

        return response()->json(['data' => $this->present($record->load(['structure', 'creator']))], 201);
    }

    /** Keeps the original single-row `employee_compensations` (annual-equivalent base salary) in step for existing readers. */
    private function syncLegacy(Employee $employee, CompensationRecord $record): void
    {
        if ($record->effective_from?->toDateString() > now()->toDateString()) {
            return;
        }
        $annual = match ($record->basis) {
            'annual' => $record->amount_minor,
            'monthly' => $record->amount_minor * 12,
            'period' => $record->amount_minor * PayrollCalculator::PERIODS_PER_YEAR[$record->pay_frequency],
        };
        $legacy = EmployeeCompensation::query()->where('employee_id', $employee->id)->first() ?? new EmployeeCompensation;
        $legacy->forceFill(['tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id, 'currency' => $record->currency, 'base_salary' => Money::toDecimal($annual, $record->currency),
            'pay_frequency' => $record->pay_frequency, 'effective_date' => $record->effective_from?->toDateString()])->saveQuietly();
    }

    private function present(CompensationRecord $r): array
    {
        return ['id' => $r->public_id, 'amount' => $this->money($r->amount_minor, $r->currency), 'basis' => $r->basis, 'pay_frequency' => $r->pay_frequency,
            'effective_from' => $r->effective_from?->toDateString(), 'effective_to' => $r->effective_to?->toDateString(), 'reason' => $r->reason, 'source' => 'manual',
            'salary_structure' => $r->structure ? ['id' => $r->structure->public_id, 'name' => $r->structure->name, 'code' => $r->structure->code] : null,
            'created_by' => $r->creator?->name, 'created_at' => $r->created_at?->toIso8601String()];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditEvents;
use App\Http\Controllers\Api\V1\Concerns\PresentsPayroll;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Reimbursement;
use App\Models\StoredFile;
use App\Models\User;
use App\Payroll\Money;
use App\Security\Permission as P;
use App\Time\Approvers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expense reimbursements: an employee submits (with an optional receipt uploaded through /files), their manager or
 * payroll decides, and approved items are paid as a calculated earning in the next run of the same currency.
 */
class ReimbursementController extends Controller
{
    use PresentsPayroll, ResolvesTimeEmployees;

    private const VIEW = [P::ReimbursementsSubmit, P::ReimbursementsApprove, P::PayrollManage];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->canSeeAnyone($user, ...self::VIEW) || $user->hasPermission(P::PayrollView), 403);
        $data = $request->validate(['scope' => ['sometimes', 'in:mine,approvals,all'], 'status' => ['sometimes', Rule::in(Reimbursement::STATUSES)],
            'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $scope = $data['scope'] ?? 'mine';
        $query = Reimbursement::query()->with(['employee', 'receipt:id,public_id,original_name']);
        if ($scope === 'mine') {
            $query->where('employee_id', $this->self($request)->id);
        } elseif ($user->hasPermission(P::PayrollView) && $scope === 'all') {
            // Payroll staff see every reimbursement.
        } else {
            $query->whereIn('employee_id', Approvers::visibleEmployees($user, P::PayrollManage, P::ReimbursementsApprove, P::PayrollManage)->select('employees.id'));
        }
        if ($scope === 'approvals') {
            $query->where('status', 'pending')->when($user->employee, fn ($q, $me) => $q->where('employee_id', '!=', $me->id));
        }
        $page = $query->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderByDesc('expense_date')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($r) => $this->present($r, $user)), 'meta' => $this->meta($page)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::ReimbursementsSubmit), 403);
        $employee = $this->self($request)->loadMissing('legalEntity');
        $data = $request->validate(['amount' => ['required', 'string', 'max:20'], 'category' => ['required', Rule::in(Reimbursement::CATEGORIES)],
            'description' => ['required', 'string', 'min:3', 'max:1000'], 'expense_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:'.now()->subYear()->toDateString()],
            'receipt_id' => ['nullable', 'string', 'max:26']]);
        $currency = $employee->legalEntity?->currency_code ?? throw ValidationException::withMessages(['employee' => __('Your employee record has no legal entity.')]);
        try {
            $amount = Money::parse($data['amount'], $currency);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be positive.')]);
        }
        $receipt = null;
        if (! empty($data['receipt_id'])) {
            $receipt = StoredFile::query()->where('public_id', $data['receipt_id'])->where('uploaded_by', $request->user()->id)->first()
                ?? throw ValidationException::withMessages(['receipt_id' => __('Unknown receipt.')]);
        }
        $item = DB::transaction(function () use ($employee, $currency, $amount, $data, $receipt, $request) {
            // Receipts move into the private payroll category; approvers reach them only through this resource.
            $receipt?->forceFill(['category' => 'payroll', 'employee_id' => $employee->id])->save();
            $item = new Reimbursement(['category' => $data['category'], 'description' => $data['description'], 'expense_date' => $data['expense_date']]);
            $item->forceFill(['tenant_id' => $employee->tenant_id, 'employee_id' => $employee->id, 'currency' => $currency, 'amount_minor' => $amount, 'receipt_file_id' => $receipt?->id,
                'submitted_by' => $request->user()->id, 'status' => 'pending'])->saveQuietly();
            AuditLog::record(AuditEvents::REIMBURSEMENT_SUBMITTED, $item, metadata: ['category' => $item->category, 'currency' => $currency, 'receipt' => $receipt !== null]);

            return $item;
        });

        return response()->json(['data' => $this->present($item->load(['employee', 'receipt']), $request->user())], 201);
    }

    public function approve(Request $request, Reimbursement $reimbursement): JsonResponse
    {
        return $this->decide($request, $reimbursement, true);
    }

    public function reject(Request $request, Reimbursement $reimbursement): JsonResponse
    {
        return $this->decide($request, $reimbursement, false);
    }

    public function cancel(Request $request, Reimbursement $reimbursement): JsonResponse
    {
        abort_unless($request->user()->employee?->id === $reimbursement->employee_id, 404);
        $item = DB::transaction(function () use ($reimbursement) {
            $locked = Reimbursement::query()->whereKey($reimbursement->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending', 409, __('Only pending reimbursements can be cancelled.'));
            $locked->forceFill(['status' => 'cancelled'])->saveQuietly();
            AuditLog::record(AuditEvents::REIMBURSEMENT_DECIDED, $locked, metadata: ['decision' => 'cancelled']);

            return $locked;
        });

        return response()->json(['data' => $this->present($item->load(['employee', 'receipt']), $request->user())]);
    }

    public function receipt(Request $request, Reimbursement $reimbursement): StreamedResponse
    {
        $this->visible($request->user(), $reimbursement);
        $file = $reimbursement->receipt;
        abort_unless($file, 404);
        AuditLog::record('file.downloaded', $file, metadata: ['file_category' => $file->category, 'via' => 'reimbursement', 'mime_type' => $file->mime_type]);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function decide(Request $request, Reimbursement $reimbursement, bool $approve): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['note' => [$approve ? 'nullable' : 'required', 'string', 'min:3', 'max:1000']]);
        $this->visible($user, $reimbursement);
        abort_unless(Approvers::canDecide($user, $reimbursement->employee, P::ReimbursementsApprove, P::PayrollManage), 403, __('You cannot decide this reimbursement.'));
        $item = DB::transaction(function () use ($reimbursement, $user, $approve, $data) {
            $locked = Reimbursement::query()->whereKey($reimbursement->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'pending', 409, __('This reimbursement has already been decided.'));
            $locked->forceFill(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null])->saveQuietly();
            AuditLog::record(AuditEvents::REIMBURSEMENT_DECIDED, $locked, metadata: ['decision' => $locked->status, 'note' => $data['note'] ?? null]);

            return $locked;
        });

        return response()->json(['data' => $this->present($item->load(['employee', 'receipt']), $user)]);
    }

    private function visible(User $user, Reimbursement $r): void
    {
        $r->loadMissing('employee');
        abort_unless($user->hasPermission(P::PayrollView) && (int) $r->tenant_id === (int) $user->tenant_id
            || Approvers::canView($user, $r->employee, P::ReimbursementsSubmit, P::ReimbursementsApprove, P::PayrollManage), 404);
    }

    private function present(Reimbursement $r, User $user): array
    {
        return ['id' => $r->public_id, 'employee' => $this->person($r->employee), 'amount' => $this->money($r->amount_minor, $r->currency), 'category' => $r->category,
            'description' => $r->description, 'expense_date' => $r->expense_date?->toDateString(), 'status' => $r->status, 'decision_note' => $r->decision_note,
            'decided_at' => $r->decided_at?->toIso8601String(), 'has_receipt' => $r->receipt_file_id !== null,
            'can_decide' => $r->status === 'pending' && Approvers::canDecide($user, $r->employee, P::ReimbursementsApprove, P::PayrollManage),
            'can_cancel' => $r->status === 'pending' && $user->employee?->id === $r->employee_id];
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditEvents;
use App\Http\Controllers\Api\V1\Concerns\PresentsPayroll;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PayrollLine;
use App\Models\PayrollPeriod;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Payroll\Money;
use App\Payroll\PayrollWorkflow;
use App\Payroll\Providers\ProviderRegistry;
use App\Support\Csv;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

class PayrollRunController extends Controller
{
    use PresentsPayroll, ResolvesTimeEmployees;

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', PayrollRun::class);
        $data = $request->validate(['status' => ['sometimes', Rule::in(PayrollRun::STATUSES)], 'legal_entity' => ['sometimes', 'string', 'max:26'],
            'awaiting_me' => ['sometimes', 'boolean'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = PayrollRun::query()->with(['period.calendar', 'legalEntity:id,public_id,name,country_code,currency_code'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['legal_entity'] ?? null, fn ($q, $e) => $q->whereHas('legalEntity', fn ($q) => $q->where('public_id', $e)))
            ->when($request->boolean('awaiting_me'), fn ($q) => $q->where('status', 'in_review'))
            ->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));
        $user = $request->user();
        $items = $page->getCollection()->map(fn ($r) => $this->presentRun($r, $user));
        if ($request->boolean('awaiting_me')) {
            $items = $items->filter(fn ($r) => $r['can']['approve'])->values();
        }

        return response()->json(['data' => $items, 'meta' => $this->meta($page)]);
    }

    public function store(Request $request, PayrollWorkflow $workflow): JsonResponse
    {
        Gate::authorize('manage', PayrollRun::class);
        $data = $request->validate(['period' => ['required', 'string', 'max:26'], 'type' => ['required', 'in:regular,off_cycle'], 'notes' => ['nullable', 'string', 'max:1000']]);
        $period = PayrollPeriod::query()->where('public_id', $data['period'])->first() ?? throw ValidationException::withMessages(['period' => __('Unknown period.')]);
        abort_unless($period->calendar->status === 'active', 422, __('This pay calendar is inactive.'));
        $run = $workflow->create($period, $data['type'], $data['notes'] ?? null, $request->user());

        return response()->json(['data' => $this->presentRun($run, $request->user())], 201);
    }

    public function show(Request $request, PayrollRun $run): JsonResponse
    {
        Gate::authorize('view', $run);
        $run->load(['approvals.decider:id,name', 'preparer:id,name', 'submitter:id,name']);
        $issues = $run->payslips()->whereNotNull('issues')->count();
        $bySource = $run->lines()->toBase()->selectRaw('source, kind, count(*) as n, sum(amount_minor) as total')->groupBy('source', 'kind')->get();

        return response()->json(['data' => $this->presentRun($run, $request->user()) + [
            'prepared_by' => $run->preparer?->name, 'submitted_by' => $run->submitter?->name, 'payslips_with_issues' => $issues,
            'totals_by_source' => $bySource->map(fn ($r) => ['source' => $r->source, 'kind' => $r->kind, 'lines' => (int) $r->n, 'total' => $this->money((int) $r->total, $run->currency)])->values(),
            'approvals' => $run->approvals->map(fn ($a) => ['review_cycle' => $a->review_cycle, 'level' => $a->level, 'decision' => $a->decision, 'by' => $a->decider?->name,
                'note' => $a->note, 'decided_at' => $a->decided_at->toIso8601String()])->values(),
        ]]);
    }

    public function payslips(Request $request, PayrollRun $run): JsonResponse
    {
        Gate::authorize('view', $run);
        $data = $request->validate(['search' => ['sometimes', 'string', 'max:100'], 'issues' => ['sometimes', 'boolean'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = $run->payslips()->with('lines')
            ->when($data['search'] ?? null, fn ($q, $s) => $q->whereHas('employee', fn ($q) => $q->where(fn ($q) => $q->where('first_name', 'like', "%{$s}%")
                ->orWhere('last_name', 'like', "%{$s}%")->orWhere('employee_number', 'like', "%{$s}%"))))
            ->when($request->boolean('issues'), fn ($q) => $q->whereNotNull('issues'))
            ->orderBy('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($s) => $this->presentPayslip($s, true)), 'meta' => $this->meta($page)]);
    }

    public function prepare(Request $request, PayrollRun $run, PayrollWorkflow $workflow): JsonResponse
    {
        Gate::authorize('manage', $run);

        return response()->json(['data' => $this->presentRun($workflow->prepare($run, $request->user()), $request->user())]);
    }

    public function storeLine(Request $request, PayrollRun $run, Payslip $payslip, PayrollWorkflow $workflow): JsonResponse
    {
        Gate::authorize('manage', $run);
        abort_unless($payslip->payroll_run_id === $run->id, 404);
        $data = $request->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', 'not_in:BASE,LOAN,REIMB'], 'name' => ['required', 'string', 'max:160'],
            'kind' => ['required', 'in:earning,deduction'], 'amount' => ['required', 'string', 'max:20'], 'reason' => ['required', 'string', 'min:3', 'max:500']]);
        try {
            $minor = Money::parse($data['amount'], $run->currency);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        if ($minor <= 0) {
            throw ValidationException::withMessages(['amount' => __('The amount must be positive; choose earning or deduction.')]);
        }
        $line = $workflow->addManualLine($payslip, ['amount' => $minor] + $data, $request->user());

        return response()->json(['data' => $this->presentLine($line, $run->currency)], 201);
    }

    public function destroyLine(Request $request, PayrollRun $run, PayrollLine $line, PayrollWorkflow $workflow): JsonResponse
    {
        Gate::authorize('manage', $run);
        abort_unless($line->payroll_run_id === $run->id, 404);
        $workflow->removeLine($line);

        return response()->json(null, 204);
    }

    public function importProvider(Request $request, PayrollRun $run, PayrollWorkflow $workflow, ProviderRegistry $registry): JsonResponse
    {
        Gate::authorize('manage', $run);
        $request->validate(['file' => ['required', File::types(['csv', 'txt'])->max(5120)], 'provider' => ['sometimes', Rule::in(array_keys($registry->all()))]]);
        $provider = $registry->get($request->input('provider', $run->period->calendar->provider));
        $count = $workflow->importProviderResults($run, $provider, (string) file_get_contents($request->file('file')->getRealPath()), $request->user());

        return response()->json(['data' => ['lines' => $count, 'run' => $this->presentRun($run->refresh(), $request->user())]]);
    }

    public function submit(Request $request, PayrollRun $run, PayrollWorkflow $workflow): JsonResponse
    {
        Gate::authorize('manage', $run);

        return response()->json(['data' => $this->presentRun($workflow->submit($run, $request->user()), $request->user())]);
    }

    public function approve(Request $request, PayrollRun $run, PayrollWorkflow $workflow): JsonResponse
    {
        Gate::authorize('view', $run);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return response()->json(['data' => $this->presentRun($workflow->decide($run, $request->user(), true, $data['note'] ?? null), $request->user())]);
    }

    public function reject(Request $request, PayrollRun $run, PayrollWorkflow $workflow): JsonResponse
    {
        Gate::authorize('view', $run);
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:1000']]);

        return response()->json(['data' => $this->presentRun($workflow->decide($run, $request->user(), false, $data['note']), $request->user())]);
    }

    public function publish(Request $request, PayrollRun $run, PayrollWorkflow $workflow): JsonResponse
    {
        Gate::authorize('manage', $run);

        return response()->json(['data' => $this->presentRun($workflow->publish($run, $request->user()), $request->user())]);
    }

    /** `summary` (one row per employee), `register` (every line with its source) or `provider` (the calendar's provider format). */
    public function export(Request $request, PayrollRun $run, ProviderRegistry $registry): Response
    {
        Gate::authorize('export', $run);
        $data = $request->validate(['format' => ['required', 'in:summary,register,provider']]);
        $c = $run->currency;
        if ($data['format'] === 'provider') {
            $file = $registry->get($run->period->calendar->provider)->export($run);
        } else {
            $rows = $data['format'] === 'summary'
                ? [['employee_number', 'employee_name', 'department', 'currency', 'days_paid', 'period_days', 'gross', 'deductions', 'net', 'issues']]
                : [['employee_number', 'employee_name', 'currency', 'code', 'name', 'kind', 'source', 'amount', 'reason', 'provider_reference']];
            $run->payslips()->with('lines')->orderBy('id')->chunk(200, function ($slips) use (&$rows, $data, $c) {
                foreach ($slips as $s) {
                    if ($data['format'] === 'summary') {
                        $rows[] = [$s->snapshot['employee_number'] ?? '', $s->snapshot['name'] ?? '', $s->snapshot['department'] ?? '', $c, $s->days_paid, $s->period_days,
                            Money::toDecimal($s->gross_minor, $c), Money::toDecimal($s->deductions_minor, $c), Money::toDecimal($s->net_minor, $c),
                            collect($s->issues ?? [])->pluck('code')->implode(' ')];
                    } else {
                        foreach ($s->lines as $l) {
                            $rows[] = [$s->snapshot['employee_number'] ?? '', $s->snapshot['name'] ?? '', $c, $l->code, $l->name, $l->kind, $l->source,
                                Money::toDecimal($l->amount_minor, $c), $l->reason, $l->provider_reference];
                        }
                    }
                }
            });
            $file = ['filename' => "payroll-{$run->public_id}-{$data['format']}.csv", 'mime' => 'text/csv', 'content' => Csv::build($rows)];
        }
        AuditLog::record(AuditEvents::PAYROLL_EXPORTED, $run, metadata: ['format' => $data['format'], 'status' => $run->status, 'employees' => $run->employee_count]);
        AuditLog::record(AuditEvents::DATA_EXPORTED, $run, metadata: ['resource' => 'payroll_run', 'format' => $data['format']]);

        return response($file['content'], 200, ['Content-Type' => $file['mime'].'; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
            'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}

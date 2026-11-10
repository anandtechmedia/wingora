<?php

namespace App\Http\Controllers\Api\V1;

use App\Audit\AuditEvents;
use App\Http\Controllers\Api\V1\Concerns\PresentsPayroll;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payslip;
use App\Payroll\PayslipPdf;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/** Employee self-service payslips and the PDF for any payslip the user may view. */
class PayslipController extends Controller
{
    use PresentsPayroll, ResolvesTimeEmployees;

    public function mine(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::PayslipsViewSelf), 403);
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = Payslip::query()->where('employee_id', $this->self($request)->id)
            ->whereHas('run', fn ($q) => $q->where('status', 'published'))
            ->with('run.period')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn (Payslip $s) => $this->presentPayslip($s) + [
            'period' => ['start_date' => $s->run->period->start_date?->toDateString(), 'end_date' => $s->run->period->end_date?->toDateString(), 'pay_date' => $s->run->period->pay_date?->toDateString()],
            'run_type' => $s->run->type,
        ]), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, Payslip $payslip): JsonResponse
    {
        Gate::authorize('view', $payslip);
        AuditLog::record(AuditEvents::PAYSLIP_VIEWED, $payslip, metadata: ['format' => 'json', 'own' => $request->user()->employee?->id === $payslip->employee_id]);

        return response()->json(['data' => $this->presentPayslip($payslip->load('lines'), true) + ['run' => $this->presentRun($payslip->run)]]);
    }

    public function pdf(Request $request, Payslip $payslip, PayslipPdf $renderer): Response
    {
        Gate::authorize('view', $payslip);
        AuditLog::record(AuditEvents::PAYSLIP_VIEWED, $payslip, metadata: ['format' => 'pdf', 'own' => $request->user()->employee?->id === $payslip->employee_id]);
        $name = 'payslip-'.preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($payslip->snapshot['employee_number'] ?? 'employee')).'-'.$payslip->run->period->start_date?->toDateString().'.pdf';

        return response($renderer->render($payslip), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }
}

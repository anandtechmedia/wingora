<?php

namespace App\Http\Controllers\Api\V1\Recruitment;

use App\Audit\AuditEvents;
use App\Employees\EmployeeService;
use App\Http\Controllers\Api\V1\Concerns\PresentsRecruitment;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\JobOffer;
use App\Models\OnboardingTemplate;
use App\Models\PipelineStage;
use App\Onboarding\OnboardingService;
use App\Payroll\Money;
use App\Recruitment\PrivateUpload;
use App\Recruitment\RecruitmentAccess;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Offers move only through explicit human actions (approve, send, record the candidate's answer, withdraw).
 * Hiring is a separate HR action that creates the employee record; nothing here is automated or AI-driven.
 */
class OfferController extends Controller
{
    use PresentsRecruitment;

    private const ACTIONS = ['approve' => 'approved', 'send' => 'sent', 'accept' => 'accepted', 'decline' => 'declined', 'withdraw' => 'withdrawn'];

    public function index(Request $request, JobApplication $application): JsonResponse
    {
        $this->offerAccess($request, $application);

        return response()->json(['data' => $application->offers()->with(['letter', 'approver'])->orderByDesc('id')->get()->map(fn ($o) => $this->presentOffer($o))->values()]);
    }

    public function store(Request $request, JobApplication $application): JsonResponse
    {
        $this->offerAccess($request, $application);
        abort_unless($application->status === 'active', 409, __('Offers can only be made on active applications.'));
        abort_if($application->offers()->whereIn('status', ['draft', 'approved', 'sent', 'accepted'])->exists(), 409, __('This application already has an open offer.'));
        $data = $request->validate([
            'job_title' => ['required', 'string', 'max:160'],
            'base_salary' => ['required', 'string', 'max:20'],
            'pay_frequency' => ['required', Rule::in(JobOffer::FREQUENCIES)],
            'start_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'expires_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'letter' => PrivateUpload::rules(['pdf', 'docx'], false),
        ]);
        $currency = $application->opening->legalEntity?->currency_code ?? abort(422, __('The opening has no legal entity currency.'));
        try {
            $amount = Money::parse($data['base_salary'], $currency);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['base_salary' => $e->getMessage()]);
        }
        abort_if($amount <= 0, 422, __('The salary must be positive.'));
        $offer = DB::transaction(function () use ($request, $application, $data, $currency, $amount) {
            $letter = $request->hasFile('letter') ? PrivateUpload::store($request->file('letter'), 'recruitment', $request->user()->id, 'letter') : null;
            $offer = new JobOffer(array_intersect_key($data, array_flip(['job_title', 'pay_frequency', 'start_date', 'expires_on', 'notes'])));
            $offer->forceFill(['tenant_id' => $application->tenant_id, 'job_application_id' => $application->id, 'status' => 'draft', 'currency' => $currency, 'base_salary_minor' => $amount,
                'letter_file_id' => $letter?->id, 'created_by' => $request->user()->id])->saveQuietly();
            AuditLog::record(AuditEvents::OFFER_CHANGED, $offer, [], ['status' => 'draft'], ['application' => $application->public_id, 'currency' => $currency]);

            return $offer;
        });

        return response()->json(['data' => $this->presentOffer($offer->load(['letter', 'approver']))], 201);
    }

    public function transition(Request $request, JobOffer $offer, string $action): JsonResponse
    {
        abort_unless(isset(self::ACTIONS[$action]), 404);
        $user = $request->user();
        $this->offerAccess($request, $offer->application);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $to = self::ACTIONS[$action];
        $result = DB::transaction(function () use ($offer, $to, $user, $data) {
            $locked = JobOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($to, JobOffer::TRANSITIONS[$locked->status], true), 409, __('This offer cannot move from :from to :to.', ['from' => $locked->status, 'to' => $to]));
            abort_if($to === 'approved' && (int) $locked->created_by === (int) $user->id, 403, __('Someone other than the offer author must approve it.'));
            $from = $locked->status;
            $locked->forceFill(array_filter([
                'status' => $to,
                'approved_by' => $to === 'approved' ? $user->id : null,
                'approved_at' => $to === 'approved' ? now() : null,
                'sent_at' => $to === 'sent' ? now() : null,
                'responded_at' => in_array($to, ['accepted', 'declined'], true) ? now() : null,
            ], fn ($v) => $v !== null))->saveQuietly();
            AuditLog::record(AuditEvents::OFFER_CHANGED, $locked, ['status' => $from], ['status' => $to], ['note' => $data['note'] ?? null]);

            return $locked;
        });

        return response()->json(['data' => $this->presentOffer($result->load(['letter', 'approver']))]);
    }

    public function letter(Request $request, JobOffer $offer): StreamedResponse
    {
        $this->offerAccess($request, $offer->application);
        $file = $offer->letter ?? abort(404);
        AuditLog::record(AuditEvents::CANDIDATE_DOCUMENT_VIEWED, $offer, metadata: ['kind' => 'offer_letter']);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store']);
    }

    /** HR turns an accepted offer into an employee record and, optionally, starts onboarding from a template. */
    public function hire(Request $request, JobApplication $application, EmployeeService $employees, OnboardingService $onboarding): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::RecruitmentManage) && $user->hasPermission(P::EmployeesManage), 403);
        $data = $request->validate([
            'employee_number' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._\-\/]+$/', Rule::unique('employees')->where('tenant_id', $user->tenant_id)],
            'work_email' => ['nullable', 'email:rfc', 'max:190'],
            'manager_id' => ['nullable', 'string', 'max:26'],
            'template_id' => ['nullable', 'string', 'max:26'],
            'version' => ['required', 'integer'],
        ]);
        $template = isset($data['template_id']) ? OnboardingTemplate::query()->where('public_id', $data['template_id'])->where('status', 'active')->first()
            ?? throw ValidationException::withMessages(['template_id' => __('Unknown onboarding template.')]) : null;
        $result = DB::transaction(function () use ($application, $data, $user, $employees, $onboarding, $template) {
            $locked = JobApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === (int) $data['version'], 409, __('Someone else changed this application. Refresh and try again.'));
            abort_unless($locked->status === 'active', 409, __('Only active applications can be hired.'));
            $offer = $locked->offers()->where('status', 'accepted')->latest('id')->first() ?? abort(409, __('Record the candidate\'s acceptance of an offer first.'));
            $opening = $locked->opening()->with(['legalEntity', 'department', 'workLocation', 'hiringManager'])->first();
            $candidate = $locked->candidate;
            $startDate = $offer->start_date?->toDateString() ?? now()->toDateString();
            $employee = $employees->create(array_filter([
                'employee_number' => $data['employee_number'],
                'first_name' => $candidate->first_name,
                'last_name' => $candidate->last_name,
                'work_email' => $data['work_email'] ?? null,
                'job_title' => $offer->job_title,
                'employment_type' => $opening->employment_type,
                'hire_date' => $startDate,
                'status' => 'active',
                'legal_entity_id' => $opening->legalEntity?->public_id,
                'department_id' => $opening->department?->public_id,
                'work_location_id' => $opening->workLocation?->public_id,
                'manager_id' => $data['manager_id'] ?? $opening->hiringManager?->public_id,
                'note' => 'Hired from recruitment',
            ], fn ($v) => $v !== null), $user);
            $locked->forceFill(['status' => 'hired', 'employee_id' => $employee->id, 'version' => $locked->version + 1, 'retain_until' => null,
                'pipeline_stage_id' => PipelineStage::ensureDefaults()->firstWhere('kind', 'hired')?->id ?? $locked->pipeline_stage_id])->saveQuietly();
            AuditLog::record(AuditEvents::CANDIDATE_HIRED, $locked, metadata: ['employee' => $employee->public_id, 'opening' => $opening->public_id]);
            $plan = $template ? $onboarding->start($employee, $template, $startDate, $user, $locked) : null;

            return [$locked, $employee, $plan];
        });
        [$hired, $employee, $plan] = $result;

        return response()->json(['data' => ['application' => $this->presentApplication($hired->load(['stage', 'opening', 'candidate', 'employee', 'offers']), $user),
            'employee' => $this->person($employee), 'onboarding_plan_id' => $plan?->public_id]], 201);
    }

    private function offerAccess(Request $request, JobApplication $application): void
    {
        abort_unless(RecruitmentAccess::canSee($request->user(), $application), 404);
        abort_unless($request->user()->hasPermission(P::RecruitmentOffers), 403);
    }
}

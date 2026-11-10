<?php

namespace App\Http\Controllers\Api\V1\Recruitment;

use App\Audit\AuditEvents;
use App\Http\Controllers\Api\V1\Concerns\PresentsRecruitment;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\PipelineStage;
use App\Models\RecruitmentSetting;
use App\Recruitment\CandidateEraser;
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

class ApplicationController extends Controller
{
    use PresentsRecruitment;

    private const WITH = ['stage', 'opening', 'candidate', 'employee', 'offers'];

    /** Kanban board: every stage with its cards, optionally for one opening. */
    public function board(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::RecruitmentView), 403);
        $data = $request->validate(['opening_id' => ['sometimes', 'string', 'max:26'], 'q' => ['sometimes', 'string', 'max:100'],
            'include_closed' => ['sometimes', 'boolean']]);
        $opening = isset($data['opening_id']) ? RecruitmentAccess::openings($user)->where('public_id', $data['opening_id'])->firstOrFail() : null;
        $stages = PipelineStage::ensureDefaults();
        $cards = RecruitmentAccess::applications($user)->with(self::WITH)
            ->when($opening, fn ($q) => $q->where('job_opening_id', $opening->id))
            ->when(! ($data['include_closed'] ?? false), fn ($q) => $q->whereIn('status', ['active', 'hired', 'rejected'])
                ->where(fn ($w) => $w->where('status', 'active')->orWhere('updated_at', '>=', now()->subDays(30))))
            ->orderBy('position')->orderBy('id')->limit(500)->get();
        $q = isset($data['q']) ? mb_strtolower($data['q']) : null;
        if ($q !== null) {
            // Names are stored as plain text but emails are encrypted, so the filter runs on the loaded page.
            $cards = $cards->filter(fn ($a) => str_contains(mb_strtolower($a->candidate->fullName().' '.$a->candidate->email), $q));
        }

        return response()->json(['data' => $stages->map(fn ($s) => $this->presentStage($s) + [
            'cards' => $cards->where('pipeline_stage_id', $s->id)->map(fn ($a) => $this->presentApplication($a, $user))->values(),
        ])->values()]);
    }

    public function show(Request $request, JobApplication $application): JsonResponse
    {
        $this->visible($request, $application);

        return response()->json(['data' => $this->presentApplication($application->load([...self::WITH, 'documents.file']), $request->user(), true)]);
    }

    /** HR adds a candidate they sourced; consent is recorded as confirmed by that person. */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentManage), 403);
        $data = $request->validate([
            'opening_id' => ['required', 'string', 'max:26'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+().\-\s]+$/'],
            'location' => ['nullable', 'string', 'max:120'],
            'linkedin_url' => ['nullable', 'url:https', 'max:255'],
            'source' => ['required', Rule::in(array_diff(Candidate::SOURCES, ['careers']))],
            'resume' => PrivateUpload::rules(['pdf', 'docx'], false),
            'consent_confirmed' => ['accepted'],
        ]);
        $opening = JobOpening::query()->where('public_id', $data['opening_id'])->where('status', '!=', 'closed')->first()
            ?? throw ValidationException::withMessages(['opening_id' => __('Choose an open job opening.')]);
        $settings = RecruitmentSetting::current();
        $application = DB::transaction(function () use ($request, $data, $opening, $settings) {
            $hash = Candidate::hashEmail($data['email']);
            $candidate = Candidate::query()->where('email_hash', $hash)->lockForUpdate()->first() ?? new Candidate;
            $candidate->fill(array_intersect_key($data, array_flip(['first_name', 'last_name', 'email', 'phone', 'location', 'linkedin_url', 'source'])));
            $candidate->forceFill(['email_hash' => $hash, 'anonymized_at' => null])->save();
            if (JobApplication::query()->where('job_opening_id', $opening->id)->where('candidate_id', $candidate->id)->exists()) {
                throw ValidationException::withMessages(['email' => __('This candidate already applied to this opening.')]);
            }
            $stage = PipelineStage::ensureDefaults()->firstWhere('kind', 'active');
            $application = new JobApplication;
            $application->forceFill(['job_opening_id' => $opening->id, 'candidate_id' => $candidate->id, 'pipeline_stage_id' => $stage->id, 'status' => 'active',
                'position' => (int) JobApplication::query()->where('pipeline_stage_id', $stage->id)->max('position') + 1,
                'consent_version' => $settings->consent_version.'-recorded-by-staff', 'consented_at' => now()])->save();
            if ($request->hasFile('resume')) {
                $this->attach($request, $application, 'resume');
            }
            AuditLog::record(AuditEvents::APPLICATION_RECEIVED, $application, metadata: ['opening' => $opening->public_id, 'source' => $candidate->source]);

            return $application;
        });

        return response()->json(['data' => $this->presentApplication($application->load([...self::WITH, 'documents.file']), $request->user(), true)], 201);
    }

    /** Moves a card. `version` must match, so two people dragging the same card cannot silently overwrite each other. */
    public function move(Request $request, JobApplication $application): JsonResponse
    {
        $user = $request->user();
        $this->actable($request, $application);
        $data = $request->validate(['stage_id' => ['required', 'string', 'max:26'], 'version' => ['required', 'integer'], 'position' => ['sometimes', 'integer', 'min:0']]);
        $stage = PipelineStage::query()->where('public_id', $data['stage_id'])->first() ?? throw ValidationException::withMessages(['stage_id' => __('Unknown stage.')]);
        abort_unless($stage->kind === 'active', 422, __('Use Hire or Reject to close an application.'));
        $moved = DB::transaction(function () use ($application, $stage, $data) {
            $locked = JobApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === (int) $data['version'], 409, __('Someone else moved this candidate. Refresh to see the latest board.'));
            abort_unless($locked->status === 'active', 409, __('Only active applications can be moved.'));
            $from = $locked->pipeline_stage_id;
            $locked->forceFill(['pipeline_stage_id' => $stage->id, 'version' => $locked->version + 1,
                'position' => $data['position'] ?? (int) JobApplication::query()->where('pipeline_stage_id', $stage->id)->max('position') + 1])->saveQuietly();
            AuditLog::record(AuditEvents::APPLICATION_MOVED, $locked, ['stage' => PipelineStage::query()->find($from)?->key], ['stage' => $stage->key]);

            return $locked;
        });

        return response()->json(['data' => $this->presentApplication($moved->load(self::WITH), $user)]);
    }

    public function reject(Request $request, JobApplication $application): JsonResponse
    {
        $this->actable($request, $application);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000'], 'version' => ['required', 'integer']]);

        return $this->close($request, $application, 'rejected', $data, AuditEvents::APPLICATION_REJECTED);
    }

    public function withdraw(Request $request, JobApplication $application): JsonResponse
    {
        $this->actable($request, $application);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000'], 'version' => ['required', 'integer']]);

        return $this->close($request, $application, 'withdrawn', $data, AuditEvents::APPLICATION_WITHDRAWN);
    }

    public function hold(Request $request, JobApplication $application): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentManage), 403);
        $data = $request->validate(['on_hold' => ['required', 'boolean']]);
        $application->candidate->forceFill(['on_hold' => $data['on_hold']])->save();

        return response()->json(['data' => $this->presentApplication($application->load(self::WITH), $request->user())]);
    }

    public function uploadDocument(Request $request, JobApplication $application): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentManage), 403);
        $request->validate(['file' => PrivateUpload::rules(), 'kind' => ['required', Rule::in(CandidateDocument::KINDS)]]);
        $doc = DB::transaction(fn () => $this->attach($request, $application, $request->input('kind'), 'file'));

        return response()->json(['data' => $this->presentDocument($doc->load('file'))], 201);
    }

    public function updateDocument(Request $request, CandidateDocument $document): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentManage), 403);
        $data = $request->validate(['kind' => ['required', Rule::in(CandidateDocument::KINDS)]]);
        $document->forceFill(['kind' => $data['kind'], 'suggested_kind' => null])->save();

        return response()->json(['data' => $this->presentDocument($document->load('file'))]);
    }

    /** Streams a candidate document after re-checking access to its application; every view is audited. */
    public function download(Request $request, CandidateDocument $document): StreamedResponse
    {
        $application = $document->application ?? abort(404);
        $this->visible($request, $application);
        $file = $document->file ?? abort(404);
        AuditLog::record(AuditEvents::CANDIDATE_DOCUMENT_VIEWED, $document, metadata: ['kind' => $document->kind, 'application' => $application->public_id]);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store']);
    }

    /** Erasure on request: removes the candidate's personal data and files from every application. */
    public function erase(Request $request, JobApplication $application, CandidateEraser $eraser): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentManage), 403);
        $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);
        $candidate = $application->candidate;
        abort_if($candidate->applications()->where('status', 'hired')->exists(), 409, __('Hired candidates are kept as employee records.'));
        $eraser->erase($candidate, 'request');
        AuditLog::record(AuditEvents::CANDIDATE_ERASED, $candidate, metadata: ['reason' => $request->input('reason')]);

        return response()->json(['data' => ['erased' => true]]);
    }

    private function close(Request $request, JobApplication $application, string $status, array $data, string $event): JsonResponse
    {
        $closed = DB::transaction(function () use ($application, $status, $data, $event) {
            $locked = JobApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->version === (int) $data['version'], 409, __('Someone else changed this application. Refresh and try again.'));
            abort_unless($locked->status === 'active', 409, __('Only active applications can be closed.'));
            $stage = $status === 'rejected' ? PipelineStage::ensureDefaults()->firstWhere('kind', 'rejected') : null;
            $locked->forceFill(['status' => $status, 'rejection_reason' => $data['reason'] ?? null, 'version' => $locked->version + 1,
                'pipeline_stage_id' => $stage?->id ?? $locked->pipeline_stage_id,
                'retain_until' => now()->addDays(RecruitmentSetting::current()->retention_days)])->saveQuietly();
            AuditLog::record($event, $locked, metadata: ['reason' => $data['reason'] ?? null]);

            return $locked;
        });

        return response()->json(['data' => $this->presentApplication($closed->load(self::WITH), $request->user())]);
    }

    private function attach(Request $request, JobApplication $application, string $kind, string $field = 'resume'): CandidateDocument
    {
        $file = PrivateUpload::store($request->file($field), 'recruitment', $request->user()->id, $field);
        $doc = new CandidateDocument;
        $doc->forceFill(['candidate_id' => $application->candidate_id, 'job_application_id' => $application->id, 'stored_file_id' => $file->id, 'kind' => $kind])->save();

        return $doc;
    }

    private function visible(Request $request, JobApplication $application): void
    {
        abort_unless(RecruitmentAccess::canSee($request->user(), $application), 404);
    }

    private function actable(Request $request, JobApplication $application): void
    {
        $this->visible($request, $application);
        abort_unless(RecruitmentAccess::canAct($request->user(), $application), 403);
    }
}

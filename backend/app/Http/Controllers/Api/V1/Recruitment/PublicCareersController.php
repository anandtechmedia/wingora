<?php

namespace App\Http\Controllers\Api\V1\Recruitment;

use App\Audit\AuditEvents;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Models\PipelineStage;
use App\Models\RecruitmentSetting;
use App\Models\Tenant;
use App\Recruitment\PrivateUpload;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Unauthenticated careers site. The tenant comes only from a careers slug the tenant has published and enabled;
 * responses contain published openings only and never reveal anything about other applicants.
 */
class PublicCareersController extends Controller
{
    public function show(string $site): JsonResponse
    {
        return $this->site($site, fn (RecruitmentSetting $s, Tenant $t) => response()->json(['data' => [
            'organization' => $t->name,
            'headline' => $s->careers_headline,
            'intro' => $s->careers_intro,
            'consent' => ['version' => $s->consent_version, 'text' => $s->consent_text, 'retention_days' => $s->retention_days],
            'openings' => JobOpening::query()->with(['legalEntity', 'workLocation', 'department'])->where('status', 'published')
                ->where(fn ($q) => $q->whereNull('closes_on')->orWhere('closes_on', '>=', now()->toDateString()))
                ->orderByDesc('published_at')->limit(200)->get()->map(fn ($o) => $this->presentPublic($o, false))->values(),
        ]]));
    }

    public function opening(string $site, string $opening): JsonResponse
    {
        return $this->site($site, fn () => response()->json(['data' => $this->presentPublic($this->find($opening), true)]));
    }

    public function apply(Request $request, string $site, string $opening): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+().\-\s]+$/'],
            'location' => ['nullable', 'string', 'max:120'],
            'linkedin_url' => ['nullable', 'url:https', 'max:255'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'resume' => PrivateUpload::rules(['pdf', 'docx']),
            'consent' => ['accepted'],
            'consent_version' => ['required', 'string', 'max:40'],
            'website' => ['nullable', 'string', 'max:0'],
        ], ['website.max' => __('Your application could not be submitted.')]);

        return $this->site($site, function (RecruitmentSetting $settings) use ($request, $data, $opening) {
            $job = $this->find($opening);
            abort_unless($job->isOpenForApplications(), 404);
            abort_unless(hash_equals($settings->consent_version, $data['consent_version']), 409, __('The consent statement changed. Reload the page and review it again.'));

            DB::transaction(function () use ($request, $data, $job, $settings) {
                $hash = Candidate::hashEmail($data['email']);
                $candidate = Candidate::query()->where('email_hash', $hash)->lockForUpdate()->first() ?? new Candidate;
                // The latest contact details given by the person win; an anonymized record is revived only by a new application.
                $candidate->fill(array_intersect_key($data, array_flip(['first_name', 'last_name', 'email', 'phone', 'location', 'linkedin_url'])));
                $candidate->forceFill(['email_hash' => $hash, 'source' => $candidate->exists ? $candidate->source : 'careers', 'anonymized_at' => null])->save();

                if (JobApplication::query()->where('job_opening_id', $job->id)->where('candidate_id', $candidate->id)->exists()) {
                    return; // Same response as a new application, so the endpoint cannot be used to probe who applied.
                }
                $stage = PipelineStage::ensureDefaults()->firstWhere('kind', 'active');
                $application = new JobApplication(['cover_letter' => $data['cover_letter'] ?? null]);
                $application->forceFill([
                    'job_opening_id' => $job->id, 'candidate_id' => $candidate->id, 'pipeline_stage_id' => $stage->id, 'status' => 'active',
                    'position' => (int) JobApplication::query()->where('pipeline_stage_id', $stage->id)->max('position') + 1,
                    'consent_version' => $settings->consent_version, 'consented_at' => now(),
                    'consent_ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
                ])->save();
                $file = PrivateUpload::store($request->file('resume'), 'recruitment', null, 'resume');
                $doc = new CandidateDocument;
                $doc->forceFill(['candidate_id' => $candidate->id, 'job_application_id' => $application->id, 'stored_file_id' => $file->id, 'kind' => 'resume'])->save();
                AuditLog::record(AuditEvents::APPLICATION_RECEIVED, $application, metadata: ['opening' => $job->public_id, 'source' => 'careers',
                    'consent_version' => $settings->consent_version]);
            });

            return response()->json(['data' => ['status' => 'received']], 202);
        });
    }

    private function site(string $slug, Closure $then): JsonResponse
    {
        abort_unless(preg_match('/^[a-z0-9-]{3,63}$/', $slug) === 1, 404);
        $settings = RecruitmentSetting::withoutGlobalScopes()->where('careers_slug', $slug)->where('careers_enabled', true)->first();
        $tenant = $settings ? Tenant::query()->whereKey($settings->tenant_id)->where('status', 'active')->first() : null;
        abort_unless($tenant, 404);

        return Tenancy::run($tenant, fn () => $then($settings, $tenant));
    }

    private function find(string $slug): JobOpening
    {
        return JobOpening::query()->with(['legalEntity', 'workLocation', 'department'])->where('slug', $slug)->where('status', 'published')->firstOrFail();
    }

    private function presentPublic(JobOpening $o, bool $detail): array
    {
        return [
            'slug' => $o->slug,
            'title' => $o->title,
            'summary' => $o->summary,
            'description' => $detail ? $o->description : null,
            'requirements' => $detail ? $o->requirements : null,
            'employment_type' => $o->employment_type,
            'workplace' => $o->workplace,
            'department' => $o->department?->name,
            'location' => $o->workLocation ? trim($o->workLocation->city.', '.$o->workLocation->country_code, ', ') : $o->legalEntity?->country_code,
            'closes_on' => $o->closes_on?->toDateString(),
            'published_at' => $o->published_at?->toIso8601String(),
            'open' => $o->isOpenForApplications(),
        ];
    }
}

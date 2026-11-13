<?php

namespace App\Http\Controllers\Api\V1\Recruitment;

use App\Ai\AiUnavailable;
use App\Ai\LanguageModel;
use App\Audit\AuditEvents;
use App\Billing\Entitlements;
use App\Http\Controllers\Controller;
use App\Models\AiDraft;
use App\Models\AuditLog;
use App\Models\JobApplication;
use App\Models\RecruitmentSetting;
use App\Recruitment\RecruitmentAccess;
use App\Security\Permission as P;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Advisory AI for recruiters: administrative summaries and document sorting suggestions only.
 * Output is stored as a draft for human review. Nothing here changes a stage, status, rating, offer or decision.
 */
class AiAssistController extends Controller
{
    private const SYSTEM = 'You help HR staff with administration. Write a short, neutral, factual summary of the application '
        .'materials provided: the role, what the applicant states about their experience, and which documents were supplied. '
        .'Do not score, rank, recommend, or say whether to hire, reject or advance anyone. Do not guess at age, gender, ethnicity, '
        .'religion, health, family status, nationality or any other personal characteristic. If information is missing, say so.';

    public function drafts(Request $request, JobApplication $application): JsonResponse
    {
        $this->access($request, $application);

        return response()->json(['data' => AiDraft::query()->with('reviewer')->where('subject_type', 'job_application')->where('subject_id', $application->id)
            ->where('status', '!=', 'discarded')->latest('id')->limit(20)->get()->map(fn ($d) => $this->present($d))->values()]);
    }

    public function summary(Request $request, JobApplication $application, LanguageModel $model): JsonResponse
    {
        $this->access($request, $application);
        $this->enabled($model);
        $application->load(['opening', 'documents.file', 'stage']);
        // Only role and application text are sent; names and contact details are replaced so the model never sees them.
        $prompt = implode("\n\n", array_filter([
            'Role: '.$application->opening->title,
            $application->opening->requirements ? 'Role requirements: '.mb_substr($application->opening->requirements, 0, 3000) : null,
            $application->cover_letter ? 'Applicant cover letter: '.mb_substr($this->scrub($application), 0, 5000) : 'No cover letter was provided.',
            'Documents supplied: '.($application->documents->map(fn ($d) => $d->kind)->implode(', ') ?: 'none'),
        ]));
        $started = microtime(true);
        try {
            $completion = app(Entitlements::class)->meterAi(Tenancy::current(), fn () => $model->complete(self::SYSTEM, $prompt));
        } catch (AiUnavailable $e) {
            abort(503, __('The AI assistant is unavailable right now. You can continue without it.'));
        }
        $draft = new AiDraft;
        $draft->forceFill(['tenant_id' => $application->tenant_id, 'feature' => 'candidate_summary', 'subject_type' => 'job_application', 'subject_id' => $application->id, 'model' => $model->name(),
            'content' => trim($completion->text), 'status' => 'pending_review', 'requested_by' => $request->user()->id,
            'input_tokens' => $completion->inputTokens, 'output_tokens' => $completion->outputTokens, 'latency_ms' => (int) ((microtime(true) - $started) * 1000)])->saveQuietly();
        AuditLog::record(AuditEvents::AI_DRAFT_GENERATED, $draft, metadata: ['feature' => 'candidate_summary', 'model' => $model->name(),
            'input_tokens' => $completion->inputTokens, 'output_tokens' => $completion->outputTokens, 'latency_ms' => $draft->latency_ms]);

        return response()->json(['data' => $this->present($draft)], 201);
    }

    /**
     * Suggests a document type for each file from its name. Suggestions are stored separately from the real type;
     * a person applies them. Works without a provider (keyword rules).
     */
    public function organize(Request $request, JobApplication $application): JsonResponse
    {
        $this->access($request, $application);
        abort_unless($request->user()->hasPermission(P::RecruitmentManage), 403);
        $suggestions = [];
        foreach ($application->documents()->with('file')->get() as $doc) {
            $kind = $this->guess((string) $doc->file?->original_name);
            if ($kind !== null && $kind !== $doc->kind) {
                $doc->forceFill(['suggested_kind' => $kind])->saveQuietly();
                $suggestions[] = ['id' => $doc->public_id, 'name' => $doc->file?->original_name, 'kind' => $doc->kind, 'suggested_kind' => $kind];
            }
        }

        return response()->json(['data' => $suggestions, 'meta' => ['method' => 'rules', 'requires_review' => true]]);
    }

    public function review(Request $request, AiDraft $draft): JsonResponse
    {
        $application = JobApplication::query()->whereKey($draft->subject_id)->firstOrFail();
        $this->access($request, $application);
        $data = $request->validate(['decision' => ['required', 'in:accept,discard']]);
        abort_unless($draft->status === 'pending_review', 409, __('This draft was already reviewed.'));
        $draft->forceFill(['status' => $data['decision'] === 'accept' ? 'accepted' : 'discarded', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()])->saveQuietly();
        AuditLog::record(AuditEvents::AI_DRAFT_REVIEWED, $draft, metadata: ['decision' => $data['decision'], 'feature' => $draft->feature]);

        return response()->json(['data' => $this->present($draft->load('reviewer'))]);
    }

    private function guess(string $name): ?string
    {
        $n = mb_strtolower($name);

        return match (true) {
            (bool) preg_match('/\b(cv|resume|résumé|curriculum)\b/u', $n) => 'resume',
            str_contains($n, 'cover') => 'cover_letter',
            (bool) preg_match('/(certificate|certification|diploma|degree|license|licence)/', $n) => 'certificate',
            str_contains($n, 'portfolio') => 'portfolio',
            default => null,
        };
    }

    private function scrub(JobApplication $a): string
    {
        $c = $a->candidate;
        $text = (string) $a->cover_letter;
        foreach (array_filter([$c->first_name, $c->last_name, $c->email, $c->phone]) as $value) {
            $text = str_ireplace($value, '[applicant]', $text);
        }

        return (string) preg_replace(['/[\w.+-]+@[\w-]+\.[\w.-]+/', '/\+?\d[\d\s().-]{7,}\d/'], ['[email]', '[phone]'], $text);
    }

    private function access(Request $request, JobApplication $application): void
    {
        abort_unless(RecruitmentAccess::canSee($request->user(), $application) && $request->user()->hasPermission(P::RecruitmentView), 404);
    }

    private function enabled(LanguageModel $model): void
    {
        abort_unless(RecruitmentSetting::current()->ai_assist_enabled, 409, __('AI assistance is turned off for this organization.'));
        abort_unless($model->available(), 503, __('No AI provider is configured.'));
    }

    private function present(AiDraft $d): array
    {
        return ['id' => $d->public_id, 'feature' => $d->feature, 'content' => $d->content, 'status' => $d->status, 'model' => $d->model, 'ai_generated' => true,
            'requires_review' => $d->status === 'pending_review', 'reviewed_by' => $d->reviewer?->name, 'reviewed_at' => $d->reviewed_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String()];
    }
}

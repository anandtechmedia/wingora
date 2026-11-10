<?php

namespace App\Http\Controllers\Api\V1\Recruitment;

use App\Ai\LanguageModel;
use App\Audit\AuditEvents;
use App\Http\Controllers\Api\V1\Concerns\PresentsRecruitment;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\JobApplication;
use App\Models\PipelineStage;
use App\Models\RecruitmentSetting;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RecruitmentSettingsController extends Controller
{
    use PresentsRecruitment;

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::RecruitmentView) || $user->hasPermission(P::RecruitmentInterview), 403);

        return response()->json(['data' => $this->payload(RecruitmentSetting::current(), $user->hasPermission(P::RecruitmentConfigure))]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentConfigure), 403);
        $settings = RecruitmentSetting::current();
        $data = $request->validate([
            'careers_enabled' => ['sometimes', 'boolean'],
            'careers_slug' => ['sometimes', 'nullable', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('recruitment_settings', 'careers_slug')->ignore($settings->id)],
            'careers_headline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'careers_intro' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'retention_days' => ['sometimes', 'integer', 'between:30,3650'],
            'consent_text' => ['sometimes', 'string', 'min:20', 'max:4000'],
            'ai_assist_enabled' => ['sometimes', 'boolean'],
        ]);
        if (($data['careers_enabled'] ?? $settings->careers_enabled) && empty($data['careers_slug'] ?? $settings->careers_slug)) {
            abort(422, __('Set a careers page address before turning the careers page on.'));
        }
        $old = $settings->only(array_keys($data));
        $settings->fill($data);
        if ($settings->isDirty('consent_text')) {
            // New wording is a new consent version; existing applications keep the version they agreed to.
            $settings->consent_version = 'v'.((int) ltrim($settings->getOriginal('consent_version'), 'v') + 1);
        }
        $settings->saveQuietly();
        AuditLog::record(AuditEvents::RECRUITMENT_SETTINGS_CHANGED, $settings, $old, $settings->only(array_keys($data)) + ['consent_version' => $settings->consent_version]);

        return response()->json(['data' => $this->payload($settings, true)]);
    }

    public function storeStage(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentConfigure), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $stages = PipelineStage::ensureDefaults();
        $key = Str::limit(Str::slug($data['name'], '_'), 30, '').'_'.Str::lower(Str::random(4));
        // New stages go before the closing (hired/rejected) stages.
        $before = $stages->firstWhere('kind', '!=', 'active');
        $order = $before ? $before->sort_order : $stages->max('sort_order') + 1;
        DB::transaction(function () use ($stages, $order, $data, $key) {
            $stages->where('sort_order', '>=', $order)->each(fn ($s) => $s->forceFill(['sort_order' => $s->sort_order + 1])->saveQuietly());
            PipelineStage::query()->create(['key' => $key, 'name' => $data['name'], 'kind' => 'active', 'sort_order' => $order]);
        });

        return response()->json(['data' => $this->payload(RecruitmentSetting::current(), true)], 201);
    }

    public function updateStage(Request $request, PipelineStage $stage): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentConfigure), 403);
        $stage->update($request->validate(['name' => ['required', 'string', 'max:80']]));

        return response()->json(['data' => $this->payload(RecruitmentSetting::current(), true)]);
    }

    public function destroyStage(Request $request, PipelineStage $stage): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::RecruitmentConfigure), 403);
        abort_unless($stage->kind === 'active' && PipelineStage::query()->where('kind', 'active')->count() > 1, 409, __('This stage cannot be removed.'));
        abort_if(JobApplication::query()->where('pipeline_stage_id', $stage->id)->exists(), 409, __('Move the candidates in this stage first.'));
        $stage->delete();

        return response()->json(['data' => $this->payload(RecruitmentSetting::current(), true)]);
    }

    private function payload(RecruitmentSetting $s, bool $full): array
    {
        return [
            'careers_enabled' => $s->careers_enabled,
            'careers_slug' => $s->careers_slug,
            'careers_headline' => $full ? $s->careers_headline : null,
            'careers_intro' => $full ? $s->careers_intro : null,
            'retention_days' => $s->retention_days,
            'consent_version' => $s->consent_version,
            'consent_text' => $full ? $s->consent_text : null,
            'ai_assist_enabled' => $s->ai_assist_enabled,
            'ai_available' => app(LanguageModel::class)->available(),
            'stages' => PipelineStage::ensureDefaults()->map(fn ($st) => $this->presentStage($st))->values(),
        ];
    }
}

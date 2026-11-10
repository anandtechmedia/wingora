<?php

namespace App\Http\Controllers\Api\V1\Recruitment;

use App\Audit\AuditEvents;
use App\Http\Controllers\Api\V1\Concerns\PresentsRecruitment;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use App\Models\JobApplication;
use App\Models\User;
use App\Recruitment\RecruitmentAccess;
use App\Security\Permission as P;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InterviewController extends Controller
{
    use PresentsRecruitment;

    private const WITH = ['panel', 'feedback.author', 'application.candidate', 'application.opening'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::RecruitmentView) || $user->hasPermission(P::RecruitmentInterview), 403);
        $data = $request->validate(['scope' => ['sometimes', 'in:mine,all'], 'application_id' => ['sometimes', 'string', 'max:26'], 'upcoming' => ['sometimes', 'boolean']]);
        $query = Interview::query()->with(self::WITH)->whereIn('job_application_id', RecruitmentAccess::applications($user)->select('job_applications.id'));
        if (($data['scope'] ?? 'mine') === 'mine' || ! $user->hasPermission(P::RecruitmentView)) {
            $query->whereHas('panel', fn ($p) => $p->where('users.id', $user->id));
        }
        if (isset($data['application_id'])) {
            $query->whereIn('job_application_id', JobApplication::query()->where('public_id', $data['application_id'])->select('id'));
        }
        $items = $query->when($data['upcoming'] ?? false, fn ($q) => $q->where('ends_at', '>=', now())->where('status', 'scheduled'))
            ->orderBy('starts_at')->limit(200)->get();

        return response()->json(['data' => $items->map(fn ($i) => $this->presentInterview($i, $user))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'application_id' => ['required', 'string', 'max:26'],
            'title' => ['required', 'string', 'max:120'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i'],
            'duration_minutes' => ['required', 'integer', 'between:15,480'],
            'timezone' => ['required', 'timezone:all'],
            'mode' => ['required', Rule::in(Interview::MODES)],
            'location' => ['nullable', 'string', 'max:255'],
            'panel' => ['required', 'array', 'min:1', 'max:10'],
            'panel.*' => ['string', 'max:26', 'distinct'],
        ]);
        $application = JobApplication::query()->where('public_id', $data['application_id'])->first() ?? abort(404);
        abort_unless(RecruitmentAccess::canSee($user, $application), 404);
        abort_unless(RecruitmentAccess::canAct($user, $application), 403);
        abort_unless($application->status === 'active', 409, __('Interviews can only be scheduled for active applications.'));
        $panel = User::query()->where('tenant_id', $user->tenant_id)->whereIn('public_id', $data['panel'])->where('status', 'active')->get();
        if ($panel->count() !== count($data['panel']) || $panel->contains(fn (User $u) => ! $u->hasPermission(P::RecruitmentInterview) && ! $u->hasPermission(P::RecruitmentManage))) {
            throw ValidationException::withMessages(['panel' => __('Every panelist must be an active user who can interview.')]);
        }
        // Times are entered in the interview's own zone and stored as UTC instants.
        $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['starts_at'], $data['timezone'])->utc();
        abort_if($start->isPast(), 422, __('Choose a time in the future.'));
        $interview = DB::transaction(function () use ($data, $application, $start, $panel, $user) {
            $interview = new Interview(['title' => $data['title'], 'mode' => $data['mode'], 'location' => $data['location'] ?? null, 'timezone' => $data['timezone']]);
            $interview->forceFill(['tenant_id' => $application->tenant_id, 'job_application_id' => $application->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes((int) $data['duration_minutes']),
                'status' => 'scheduled', 'scheduled_by' => $user->id])->saveQuietly();
            $interview->panel()->attach($panel->pluck('id')->mapWithKeys(fn ($id) => [$id => ['tenant_id' => $application->tenant_id]])->all());
            AuditLog::record(AuditEvents::INTERVIEW_SCHEDULED, $interview, metadata: ['application' => $application->public_id, 'panel_size' => $panel->count(),
                'starts_at' => $start->toIso8601String()]);

            return $interview;
        });

        return response()->json(['data' => $this->presentInterview($interview->load(self::WITH), $user)], 201);
    }

    public function cancel(Request $request, Interview $interview): JsonResponse
    {
        return $this->setStatus($request, $interview, 'cancelled');
    }

    public function complete(Request $request, Interview $interview): JsonResponse
    {
        return $this->setStatus($request, $interview, 'completed');
    }

    /** Only panelists submit feedback, once each. It is a human opinion and never moves the candidate by itself. */
    public function feedback(Request $request, Interview $interview): JsonResponse
    {
        $user = $request->user();
        abort_unless($interview->hasPanelist($user), 403, __('Only interview panelists can give feedback.'));
        abort_if($interview->status === 'cancelled', 409, __('This interview was cancelled.'));
        abort_if($interview->starts_at->isFuture(), 409, __('Feedback opens when the interview starts.'));
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'recommendation' => ['required', Rule::in(InterviewFeedback::RECOMMENDATIONS)],
            'strengths' => ['nullable', 'string', 'max:4000'], 'concerns' => ['nullable', 'string', 'max:4000'], 'notes' => ['nullable', 'string', 'max:4000']]);
        abort_if(InterviewFeedback::query()->where('interview_id', $interview->id)->where('user_id', $user->id)->exists(), 409, __('You already submitted feedback.'));
        $fb = new InterviewFeedback($data);
        $fb->forceFill(['tenant_id' => $interview->tenant_id, 'interview_id' => $interview->id, 'user_id' => $user->id])->saveQuietly();
        AuditLog::record(AuditEvents::INTERVIEW_FEEDBACK_SUBMITTED, $interview, metadata: ['rating' => $fb->rating, 'recommendation' => $fb->recommendation]);

        return response()->json(['data' => $this->presentInterview($interview->fresh()->load(self::WITH), $user)], 201);
    }

    private function setStatus(Request $request, Interview $interview, string $status): JsonResponse
    {
        $user = $request->user();
        $application = $interview->application;
        abort_unless(RecruitmentAccess::canSee($user, $application), 404);
        abort_unless(RecruitmentAccess::canAct($user, $application), 403);
        abort_unless($interview->status === 'scheduled', 409, __('This interview is no longer scheduled.'));
        $interview->forceFill(['status' => $status])->saveQuietly();
        if ($status === 'cancelled') {
            AuditLog::record(AuditEvents::INTERVIEW_CANCELLED, $interview, metadata: ['application' => $application->public_id]);
        }

        return response()->json(['data' => $this->presentInterview($interview->load(self::WITH), $user)]);
    }
}

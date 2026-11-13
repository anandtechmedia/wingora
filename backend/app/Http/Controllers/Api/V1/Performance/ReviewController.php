<?php

namespace App\Http\Controllers\Api\V1\Performance;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PerformanceFeedback;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewCycle;
use App\Performance\PerformanceAccess;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reviews move pending_self → pending_manager → completed → acknowledged. The employee writes the self assessment;
 * their manager (or HR) writes the manager assessment and rating, which the employee sees only once it is completed.
 */
class ReviewController extends Controller
{
    use Concerns;

    private const WITH = ['cycle', 'employee', 'manager'];

    public function index(Request $request): JsonResponse
    {
        abort_unless(PerformanceAccess::any($request->user()), 403);
        $data = $request->validate([
            'scope' => ['sometimes', 'in:mine,team,all'],
            'cycle_id' => ['sometimes', 'string', 'max:26'],
            'status' => ['sometimes', Rule::in(PerformanceReview::STATUSES)],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = $this->scoped(PerformanceReview::query(), $request, $data['scope'] ?? 'all')->with(self::WITH)
            ->withCount(['feedback', 'feedback as feedback_submitted_count' => fn ($q) => $q->where('status', 'submitted')])
            ->when($data['cycle_id'] ?? null, fn ($q, $c) => $q->whereIn('review_cycle_id', PerformanceReviewCycle::query()->where('public_id', $c)->select('id')))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['q'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->whereHas('employee', fn ($e) => $e->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('employee_number', 'like', $like));
            });
        $page = $query->orderByDesc('review_cycle_id')->orderBy('status')->orderBy('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($r) => $this->present($r, $request))->values(), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, PerformanceReview $review): JsonResponse
    {
        $this->visible($request, $review);

        return response()->json(['data' => $this->detail($review, $request)]);
    }

    public function submitSelf(Request $request, PerformanceReview $review): JsonResponse
    {
        $this->visible($request, $review);
        abort_unless(PerformanceAccess::isSelf($request->user(), $review->employee), 403);

        return $this->transition($request, $review, 'pending_self', function (PerformanceReview $r, array $answers) {
            $r->forceFill(['self_answers' => $answers, 'self_submitted_at' => now(), 'status' => 'pending_manager']);
        });
    }

    public function submitManager(Request $request, PerformanceReview $review): JsonResponse
    {
        $this->visible($request, $review);
        abort_unless(PerformanceAccess::canManage($request->user(), $review->employee), 403);
        $scale = $review->cycle->rating_scale ?? 5;
        $rating = $request->validate(['rating' => ['required', 'integer', 'between:1,'.$scale]])['rating'];

        return $this->transition($request, $review, 'pending_manager', function (PerformanceReview $r, array $answers) use ($rating, $request) {
            $r->forceFill(['manager_answers' => $answers, 'rating' => $rating, 'manager_submitted_at' => now(), 'manager_submitted_by' => $request->user()->id, 'status' => 'completed']);
        });
    }

    public function acknowledge(Request $request, PerformanceReview $review): JsonResponse
    {
        $this->visible($request, $review);
        abort_unless(PerformanceAccess::isSelf($request->user(), $review->employee), 403);
        $data = $request->validate(['version' => ['required', 'integer']]);
        DB::transaction(function () use ($review, $data) {
            $r = PerformanceReview::query()->lockForUpdate()->findOrFail($review->id);
            $this->checkVersion($r, (int) $data['version']);
            abort_unless($r->status === 'completed', 422, __('Only a completed review can be acknowledged.'));
            $r->forceFill(['status' => 'acknowledged', 'acknowledged_at' => now(), 'version' => $r->version + 1])->save();
        });

        return response()->json(['data' => $this->detail($review->fresh(), $request)]);
    }

    /** Managers and HR ask colleagues for 360-degree feedback on the review. */
    public function requestFeedback(Request $request, PerformanceReview $review): JsonResponse
    {
        $user = $request->user();
        $this->visible($request, $review);
        abort_unless(PerformanceAccess::canManage($user, $review->employee), 403);
        abort_unless($review->cycle->status === 'open' && $review->status !== 'acknowledged', 422, __('Feedback can only be requested while the review is in progress.'));
        $data = $request->validate([
            'reviewer_id' => ['required', 'string', 'max:26'],
            'relationship' => ['required', Rule::in(PerformanceFeedback::RELATIONSHIPS)],
            'anonymous' => ['sometimes', 'boolean'],
        ]);
        $reviewer = Employee::query()->where('public_id', $data['reviewer_id'])->where('status', 'active')->whereNotNull('user_id')->with('user')->first();
        if (! $reviewer || $reviewer->id === $review->employee_id || ! $reviewer->user?->hasPermission(P::PerformanceView)) {
            throw ValidationException::withMessages(['reviewer_id' => __('Choose an active colleague, other than the person being reviewed.')]);
        }
        if ($review->feedback()->where('reviewer_id', $reviewer->user_id)->exists()) {
            throw ValidationException::withMessages(['reviewer_id' => __('Feedback has already been requested from this person.')]);
        }
        $f = new PerformanceFeedback;
        $f->forceFill(['review_id' => $review->id, 'reviewer_id' => $reviewer->user_id, 'relationship' => $data['relationship'],
            'anonymous' => $data['anonymous'] ?? true, 'status' => 'requested', 'requested_by' => $user->id])->save();

        return response()->json(['data' => $this->detail($review->fresh(), $request)], 201);
    }

    /** Active colleagues who can be asked for feedback. Names only. */
    public function people(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::PerformanceReview) || $user->hasPermission(P::PerformanceManage), 403);
        $q = $request->validate(['q' => ['sometimes', 'string', 'max:100']])['q'] ?? null;
        $rows = Employee::query()->where('status', 'active')->whereNotNull('user_id')
            ->when($q, function ($query, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $query->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('preferred_name', 'like', $like));
            })
            ->orderBy('first_name')->orderBy('last_name')->limit(20)->get(['id', 'public_id', 'first_name', 'last_name', 'preferred_name', 'employee_number']);

        return response()->json(['data' => $rows->map(fn ($e) => $this->person($e))->values()]);
    }

    private function transition(Request $request, PerformanceReview $review, string $from, callable $apply): JsonResponse
    {
        $data = $request->validate(['version' => ['required', 'integer'], 'answers' => ['required', 'array']]);
        $answers = $this->answers($review->cycle, $data['answers']);
        DB::transaction(function () use ($review, $data, $from, $apply, $answers) {
            $r = PerformanceReview::query()->lockForUpdate()->findOrFail($review->id);
            $this->checkVersion($r, (int) $data['version']);
            abort_unless($r->status === $from && PerformanceReviewCycle::query()->whereKey($r->review_cycle_id)->sharedLock()->value('status') === 'open', 422, __('This review is not waiting for that step.'));
            $apply($r, $answers);
            $r->version = $r->version + 1;
            $r->save();
        });

        return response()->json(['data' => $this->detail($review->fresh(), $request)]);
    }

    /** Every cycle question must be answered: text up to 5000 characters, ratings within the cycle's scale. */
    private function answers(PerformanceReviewCycle $cycle, array $input): array
    {
        $out = [];
        $errors = [];
        foreach ($cycle->questions as $q) {
            $v = $input[$q['key']] ?? null;
            if ($q['type'] === 'rating') {
                $ok = is_int($v) || (is_string($v) && ctype_digit($v));
                $v = $ok ? (int) $v : null;
                if ($v === null || $v < 1 || $v > ($cycle->rating_scale ?? 5)) {
                    $errors['answers.'.$q['key']] = __('Choose a rating from 1 to :max.', ['max' => $cycle->rating_scale ?? 5]);
                }
            } elseif (! is_string($v) || trim($v) === '' || mb_strlen($v) > 5000) {
                $errors['answers.'.$q['key']] = __('Answer this question (up to 5000 characters).');
            }
            $out[$q['key']] = is_string($v) ? trim($v) : $v;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    private function visible(Request $request, PerformanceReview $review): void
    {
        abort_unless(PerformanceAccess::any($request->user()) && PerformanceAccess::canView($request->user(), $review->employee), 404);
    }

    private function present(PerformanceReview $r, Request $request): array
    {
        $user = $request->user();
        $manages = PerformanceAccess::canManage($user, $r->employee);
        $released = in_array($r->status, ['completed', 'acknowledged'], true);

        return ['id' => $r->public_id, 'status' => $r->status, 'cycle' => ['id' => $r->cycle->public_id, 'name' => $r->cycle->name, 'status' => $r->cycle->status,
            'self_due_on' => $r->cycle->self_due_on?->toDateString(), 'manager_due_on' => $r->cycle->manager_due_on?->toDateString(), 'rating_scale' => $r->cycle->rating_scale ?? 5],
            'employee' => $this->person($r->employee), 'manager' => $this->person($r->manager),
            'rating' => $manages || $released ? $r->rating : null,
            'self_submitted_at' => $r->self_submitted_at?->toIso8601String(), 'manager_submitted_at' => $r->manager_submitted_at?->toIso8601String(),
            'acknowledged_at' => $r->acknowledged_at?->toIso8601String(),
            'feedback_requested' => $manages ? ($r->feedback_count ?? null) : null, 'feedback_submitted' => $manages ? ($r->feedback_submitted_count ?? null) : null,
            'can_self' => $r->status === 'pending_self' && PerformanceAccess::isSelf($user, $r->employee),
            'can_manage' => $manages, 'version' => $r->version];
    }

    private function detail(PerformanceReview $r, Request $request): array
    {
        $r->load(self::WITH);
        $manages = PerformanceAccess::canManage($request->user(), $r->employee);
        $released = in_array($r->status, ['completed', 'acknowledged'], true);
        $feedback = $manages || $released ? $r->feedback()->with('reviewer')->orderBy('id')->get()
            ->filter(fn ($f) => $manages || $f->status === 'submitted')
            ->map(fn ($f) => ['id' => $f->public_id, 'relationship' => $f->relationship, 'anonymous' => $f->anonymous, 'status' => $f->status,
                'reviewer' => $f->anonymous ? null : $f->reviewer?->name, 'answers' => $f->status === 'submitted' ? $f->answers : null,
                'submitted_at' => $f->submitted_at?->toIso8601String()])->values() : [];

        return $this->present($r, $request) + [
            'questions' => $r->cycle->questions,
            'self_answers' => $r->self_answers,
            'manager_answers' => $manages || $released ? $r->manager_answers : null,
            'feedback' => $feedback,
        ];
    }
}

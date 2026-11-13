<?php

namespace App\Http\Controllers\Api\V1\Performance;

use App\Http\Controllers\Controller;
use App\Models\PerformanceFeedback;
use App\Performance\PerformanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Feedback the signed-in user has been asked to give. Only the reviewer sees or answers their own requests here. */
class FeedbackController extends Controller
{
    use Concerns;

    public function index(Request $request): JsonResponse
    {
        abort_unless(PerformanceAccess::any($request->user()), 403);
        $data = $request->validate(['status' => ['sometimes', Rule::in(PerformanceFeedback::STATUSES)], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = PerformanceFeedback::query()->where('reviewer_id', $request->user()->id)->with(['review.cycle', 'review.employee'])
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("status = 'requested' desc")->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($f) => $this->present($f))->values(), 'meta' => $this->meta($page)]);
    }

    public function submit(Request $request, PerformanceFeedback $feedback): JsonResponse
    {
        $scale = $feedback->review->cycle->rating_scale ?? 5;
        $data = $request->validate([
            'strengths' => ['required', 'string', 'max:5000'],
            'improvements' => ['required', 'string', 'max:5000'],
            'rating' => ['sometimes', 'nullable', 'integer', 'between:1,'.$scale],
        ]);

        return $this->decide($request, $feedback, fn (PerformanceFeedback $f) => $f->forceFill([
            'answers' => ['strengths' => trim($data['strengths']), 'improvements' => trim($data['improvements']), 'rating' => $data['rating'] ?? null],
            'status' => 'submitted', 'submitted_at' => now(),
        ]));
    }

    public function decline(Request $request, PerformanceFeedback $feedback): JsonResponse
    {
        return $this->decide($request, $feedback, fn (PerformanceFeedback $f) => $f->forceFill(['status' => 'declined']));
    }

    private function decide(Request $request, PerformanceFeedback $feedback, callable $apply): JsonResponse
    {
        abort_unless($feedback->reviewer_id === $request->user()->id && PerformanceAccess::any($request->user()), 404);
        DB::transaction(function () use ($feedback, $apply) {
            $f = PerformanceFeedback::query()->lockForUpdate()->findOrFail($feedback->id);
            abort_unless($f->status === 'requested' && $feedback->review->cycle->status === 'open', 422, __('This feedback request is no longer open.'));
            $apply($f);
            $f->save();
        });

        return response()->json(['data' => $this->present($feedback->fresh())]);
    }

    private function present(PerformanceFeedback $f): array
    {
        return ['id' => $f->public_id, 'status' => $f->status, 'relationship' => $f->relationship, 'anonymous' => $f->anonymous,
            'subject' => $this->person($f->review->employee), 'cycle' => ['id' => $f->review->cycle->public_id, 'name' => $f->review->cycle->name,
                'status' => $f->review->cycle->status, 'manager_due_on' => $f->review->cycle->manager_due_on?->toDateString(), 'rating_scale' => $f->review->cycle->rating_scale ?? 5],
            'answers' => $f->answers, 'submitted_at' => $f->submitted_at?->toIso8601String()];
    }
}

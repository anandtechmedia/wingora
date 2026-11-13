<?php

namespace App\Http\Controllers\Api\V1\Performance;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PerformanceFeedback;
use App\Models\PerformanceMeeting;
use App\Models\PerformanceObjective;
use App\Models\PerformanceReview;
use App\Performance\PerformanceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** An employee's performance over time: completed reviews and ratings, objectives, feedback and one-to-one counts. */
class HistoryController extends Controller
{
    use Concerns;

    public function show(Request $request, Employee $employee): JsonResponse
    {
        $user = $request->user();
        abort_unless(PerformanceAccess::any($user) && PerformanceAccess::canView($user, $employee), 404);
        $reviews = PerformanceReview::query()->where('employee_id', $employee->id)->whereIn('status', ['completed', 'acknowledged'])
            ->with(['cycle', 'manager'])->withCount(['feedback as feedback_count' => fn ($q) => $q->where('status', 'submitted')])
            ->get()->sortByDesc(fn ($r) => $r->cycle->starts_on)->values();
        $objectives = PerformanceObjective::query()->where('employee_id', $employee->id)->with('cycle')->orderByDesc('due_on')->orderByDesc('id')->limit(100)->get();

        return response()->json(['data' => [
            'employee' => $this->person($employee),
            'reviews' => $reviews->map(fn ($r) => ['id' => $r->public_id, 'cycle' => ['id' => $r->cycle->public_id, 'name' => $r->cycle->name,
                'starts_on' => $r->cycle->starts_on?->toDateString(), 'ends_on' => $r->cycle->ends_on?->toDateString(), 'rating_scale' => $r->cycle->rating_scale ?? 5],
                'status' => $r->status, 'rating' => $r->rating, 'manager' => $this->person($r->manager), 'completed_at' => $r->manager_submitted_at?->toIso8601String(),
                'feedback_count' => $r->feedback_count])->values(),
            'objectives' => $objectives->map(fn ($o) => ['id' => $o->public_id, 'title' => $o->title, 'kind' => $o->kind, 'status' => $o->status, 'progress' => $o->progress,
                'due_on' => $o->due_on?->toDateString(), 'cycle' => $o->cycle?->name])->values(),
            'summary' => [
                'objectives_completed' => $objectives->where('status', 'completed')->count(),
                'objectives_active' => $objectives->where('status', 'active')->count(),
                'meetings_completed' => PerformanceMeeting::query()->where('employee_id', $employee->id)->where('status', 'completed')->count(),
                'feedback_received' => PerformanceFeedback::query()->whereIn('review_id', PerformanceReview::query()->where('employee_id', $employee->id)->select('id'))->where('status', 'submitted')->count(),
            ],
        ]]);
    }
}

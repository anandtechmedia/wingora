<?php

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Audit\AuditEvents;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OnboardingPlan;
use App\Models\OnboardingTask;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Onboarding\OnboardingService;
use App\Recruitment\PrivateUpload;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OnboardingPlanController extends Controller
{
    use ResolvesTimeEmployees;

    private const WITH = ['employee.manager', 'employee.department', 'template', 'tasks.assigneeUser', 'tasks.document', 'tasks.plan.employee'];

    public function __construct(private readonly OnboardingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['status' => ['sometimes', Rule::in(OnboardingPlan::STATUSES)], 'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = $this->service->visiblePlans($user)->with(self::WITH)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['q'] ?? null, fn ($q, $s) => $q->whereHas('employee', fn ($e) => $e->where(fn ($w) => $w
                ->where('first_name', 'like', '%'.addcslashes($s, '%_\\').'%')->orWhere('last_name', 'like', '%'.addcslashes($s, '%_\\').'%')
                ->orWhere('employee_number', 'like', '%'.addcslashes($s, '%_\\').'%'))))
            ->orderByRaw("case status when 'in_progress' then 0 else 1 end")->orderByDesc('start_date')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($p) => $this->presentPlan($p, $user, false))->values(), 'meta' => $this->meta($page)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::OnboardingManage), 403);
        $data = $request->validate(['employee_id' => ['required', 'string', 'max:26'], 'template_id' => ['required', 'string', 'max:26'],
            'start_date' => ['required', 'date_format:Y-m-d']]);
        $employee = Employee::query()->where('public_id', $data['employee_id'])->first() ?? throw ValidationException::withMessages(['employee_id' => __('Unknown employee.')]);
        $template = OnboardingTemplate::query()->with('tasks')->where('public_id', $data['template_id'])->where('status', 'active')->first()
            ?? throw ValidationException::withMessages(['template_id' => __('Unknown onboarding template.')]);
        $plan = $this->service->start($employee, $template, $data['start_date'], $request->user());

        return response()->json(['data' => $this->presentPlan($plan->load(self::WITH), $request->user(), true)], 201);
    }

    public function show(Request $request, OnboardingPlan $plan): JsonResponse
    {
        $this->visible($request->user(), $plan);

        return response()->json(['data' => $this->presentPlan($plan->load(self::WITH), $request->user(), true)]);
    }

    public function cancel(Request $request, OnboardingPlan $plan): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::OnboardingManage), 403);
        abort_unless($plan->status === 'in_progress', 409, __('Only onboarding in progress can be cancelled.'));
        $plan->forceFill(['status' => 'cancelled'])->save();

        return response()->json(['data' => $this->presentPlan($plan->load(self::WITH), $request->user(), true)]);
    }

    public function myTasks(Request $request): JsonResponse
    {
        $user = $request->user();
        $tasks = $this->service->myTasks($user)->with(['plan.employee', 'assigneeUser', 'document'])->limit(200)->get();

        return response()->json(['data' => $tasks->map(fn ($t) => $this->presentTask($t, $user) + ['employee' => $this->person($t->plan->employee),
            'plan_id' => $t->plan->public_id])->values()]);
    }

    public function complete(Request $request, OnboardingTask $task): JsonResponse
    {
        $user = $request->user();
        $this->workable($user, $task);
        abort_if($task->document_key !== null, 422, __('Upload the document to complete this task.'));
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        return $this->finish($request, $task, ['pending', 'returned'], 'completed', AuditEvents::ONBOARDING_TASK_COMPLETED, $data['note'] ?? null);
    }

    public function upload(Request $request, OnboardingTask $task): JsonResponse
    {
        $user = $request->user();
        $this->workable($user, $task);
        abort_if($task->document_key === null, 422, __('This task does not collect a document.'));
        $request->validate(['file' => PrivateUpload::rules()]);
        DB::transaction(function () use ($request, $task, $user) {
            $locked = OnboardingTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['pending', 'returned'], true), 409, __('This document was already submitted.'));
            $previous = $locked->document;
            $file = PrivateUpload::store($request->file('file'), 'onboarding', $user->id);
            $file->forceFill(['employee_id' => $locked->plan->employee_id])->save();
            $locked->forceFill(['document_file_id' => $file->id, 'status' => 'submitted', 'review_note' => null])->saveQuietly();
            PrivateUpload::destroy($previous);
        });

        return response()->json(['data' => $this->presentTask($task->fresh(['plan.employee', 'assigneeUser', 'document']), $user)]);
    }

    /** HR accepts or returns a submitted document. */
    public function review(Request $request, OnboardingTask $task): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::OnboardingManage), 403);
        $data = $request->validate(['decision' => ['required', 'in:accept,return'], 'note' => ['required_if:decision,return', 'nullable', 'string', 'max:1000']]);
        $accept = $data['decision'] === 'accept';

        return $this->finish($request, $task, ['submitted'], $accept ? 'completed' : 'returned', AuditEvents::ONBOARDING_DOCUMENT_REVIEWED, $data['note'] ?? null,
            ['decision' => $data['decision']]);
    }

    public function skip(Request $request, OnboardingTask $task): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::OnboardingManage), 403);
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);

        return $this->finish($request, $task, ['pending', 'returned', 'submitted'], 'skipped', AuditEvents::ONBOARDING_TASK_COMPLETED, $data['note'], ['skipped' => true]);
    }

    public function document(Request $request, OnboardingTask $task): StreamedResponse
    {
        $user = $request->user();
        $file = $task->document ?? abort(404);
        abort_unless($user->hasPermission(P::OnboardingManage) || $user->employee?->id === $task->plan->employee_id || (int) $file->uploaded_by === (int) $user->id, 404);
        AuditLog::record('file.downloaded', $file, metadata: ['file_category' => 'onboarding', 'via' => 'onboarding_task', 'document_key' => $task->document_key]);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, no-store']);
    }

    private function finish(Request $request, OnboardingTask $task, array $from, string $to, string $event, ?string $note, array $meta = []): JsonResponse
    {
        $user = $request->user();
        abort_unless($task->plan->status === 'in_progress', 409, __('This onboarding is not in progress.'));
        DB::transaction(function () use ($task, $from, $to, $event, $note, $meta, $user) {
            $locked = OnboardingTask::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, $from, true), 409, __('This task has already been updated.'));
            $done = in_array($to, OnboardingTask::DONE, true);
            $locked->forceFill(['status' => $to, 'review_note' => $note, 'completed_by' => $done ? $user->id : null, 'completed_at' => $done ? now() : null])->saveQuietly();
            AuditLog::record($event, $locked, ['status' => $task->status], ['status' => $to], $meta + ['plan' => $locked->plan->public_id, 'title' => $locked->title]);
            $this->service->refresh($locked->plan);
        });

        return response()->json(['data' => $this->presentTask($task->fresh(['plan.employee', 'assigneeUser', 'document']), $user)]);
    }

    private function visible(User $user, OnboardingPlan $plan): void
    {
        abort_unless($this->service->visiblePlans($user)->whereKey($plan->id)->exists(), 404);
    }

    private function workable(User $user, OnboardingTask $task): void
    {
        abort_unless($this->service->visiblePlans($user)->whereKey($task->onboarding_plan_id)->exists() || $this->service->canWork($user, $task), 404);
        abort_unless($this->service->canWork($user, $task), 403, __('This task is assigned to someone else.'));
        abort_unless($task->plan->status === 'in_progress', 409, __('This onboarding is not in progress.'));
    }

    private function presentPlan(OnboardingPlan $p, User $user, bool $detail): array
    {
        return [
            'id' => $p->public_id,
            'status' => $p->status,
            'start_date' => $p->start_date->toDateString(),
            'completed_at' => $p->completed_at?->toIso8601String(),
            'employee' => $this->person($p->employee) + ['job_title' => $p->employee?->job_title, 'department' => $p->employee?->department?->name],
            'manager' => $this->person($p->employee?->manager),
            'template' => $p->template ? ['id' => $p->template->public_id, 'name' => $p->template->name] : null,
            'progress' => OnboardingService::progress($p),
            'tasks' => $detail ? $p->tasks->map(fn ($t) => $this->presentTask($t, $user))->values() : null,
            'can' => ['manage' => $user->hasPermission(P::OnboardingManage)],
        ];
    }

    private function presentTask(OnboardingTask $t, User $user): array
    {
        $today = now()->toDateString();

        return [
            'id' => $t->public_id,
            'stage' => $t->stage,
            'title' => $t->title,
            'description' => $t->description,
            'assignee' => $t->assignee,
            'assignee_user' => $t->assigneeUser ? ['id' => $t->assigneeUser->public_id, 'name' => $t->assigneeUser->name] : null,
            'due_on' => $t->due_on->toDateString(),
            'overdue' => ! in_array($t->status, OnboardingTask::DONE, true) && $t->due_on->toDateString() < $today,
            'status' => $t->status,
            'review_note' => $t->review_note,
            'document' => $t->document_key ? ['key' => $t->document_key, 'name' => $t->document_name,
                'file' => $t->document ? ['name' => $t->document->original_name, 'size' => $t->document->size] : null] : null,
            'completed_at' => $t->completed_at?->toIso8601String(),
            'can' => ['work' => $this->service->canWork($user, $t), 'review' => $user->hasPermission(P::OnboardingManage)],
        ];
    }
}

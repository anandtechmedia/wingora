<?php

namespace App\Http\Controllers\Api\V1\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\LegalEntity;
use App\Models\OnboardingTemplate;
use App\Models\OnboardingTemplateTask;
use App\Models\User;
use App\Onboarding\OnboardingService;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OnboardingTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::OnboardingManage) || $request->user()->hasPermission(P::OnboardingConfigure), 403);
        $items = OnboardingTemplate::query()->with(['legalEntity', 'tasks.assigneeUser'])->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn ($t) => $this->present($t))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->configure($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:2000'],
            'legal_entity_id' => ['nullable', 'string', 'max:26']]);
        $t = new OnboardingTemplate(['name' => $data['name'], 'description' => $data['description'] ?? null, 'status' => 'active']);
        $t->legal_entity_id = $this->entity($data['legal_entity_id'] ?? null)?->id;
        $t->save();

        return response()->json(['data' => $this->present($t->load(['legalEntity', 'tasks']))], 201);
    }

    public function update(Request $request, OnboardingTemplate $template): JsonResponse
    {
        $this->configure($request);
        $template->update($request->validate(['name' => ['sometimes', 'string', 'max:120'], 'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])]]));

        return response()->json(['data' => $this->present($template->load(['legalEntity', 'tasks.assigneeUser']))]);
    }

    public function storeTask(Request $request, OnboardingTemplate $template): JsonResponse
    {
        $this->configure($request);
        $task = new OnboardingTemplateTask;
        $this->fillTask($request, $task, true);
        $task->forceFill(['onboarding_template_id' => $template->id, 'sort_order' => (int) $template->tasks()->max('sort_order') + 1])->save();

        return response()->json(['data' => $this->present($template->fresh()->load(['legalEntity', 'tasks.assigneeUser']))], 201);
    }

    public function updateTask(Request $request, OnboardingTemplateTask $task): JsonResponse
    {
        $this->configure($request);
        $this->fillTask($request, $task, false);
        $task->save();

        return response()->json(['data' => $this->present($task->template->load(['legalEntity', 'tasks.assigneeUser']))]);
    }

    public function destroyTask(Request $request, OnboardingTemplateTask $task): JsonResponse
    {
        $this->configure($request);
        $template = $task->template;
        $task->delete();

        return response()->json(['data' => $this->present($template->load(['legalEntity', 'tasks.assigneeUser']))]);
    }

    /** Employment documents configured in the approved country compliance rules for a legal entity. */
    public function documentRequirements(Request $request, OnboardingService $service): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::OnboardingManage) || $request->user()->hasPermission(P::OnboardingConfigure), 403);
        $data = $request->validate(['legal_entity_id' => ['required', 'string', 'max:26'], 'date' => ['sometimes', 'date_format:Y-m-d']]);
        $entity = $this->entity($data['legal_entity_id']);

        return response()->json(['data' => $service->countryDocuments($entity, $data['date'] ?? now()->toDateString()),
            'meta' => ['country_code' => $entity->country_code, 'subdivision_code' => $entity->subdivision_code, 'source' => 'country_rules.compliance']]);
    }

    private function fillTask(Request $request, OnboardingTemplateTask $task, bool $creating): void
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'stage' => [$req, Rule::in(OnboardingTemplateTask::STAGES)],
            'title' => [$req, 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'assignee' => [$req, Rule::in(OnboardingTemplateTask::ASSIGNEES)],
            'assignee_user_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'due_offset_days' => [$req, 'integer', 'between:-60,365'],
            'document_key' => ['sometimes', 'nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'document_name' => ['sometimes', 'nullable', 'string', 'max:160', 'required_with:document_key'],
        ]);
        $task->fill(array_intersect_key($data, array_flip($task->getFillable())));
        if (array_key_exists('assignee_user_id', $data)) {
            $task->assignee_user_id = $data['assignee_user_id'] === null ? null : (User::query()->where('tenant_id', $request->user()->tenant_id)
                ->where('public_id', $data['assignee_user_id'])->value('id') ?? throw ValidationException::withMessages(['assignee_user_id' => __('Unknown user.')]));
        }
        if ($task->assignee === 'user' && ! $task->assignee_user_id) {
            throw ValidationException::withMessages(['assignee_user_id' => __('Choose the person responsible.')]);
        }
    }

    private function entity(?string $id): ?LegalEntity
    {
        return $id === null ? null : (LegalEntity::query()->where('public_id', $id)->first()
            ?? throw ValidationException::withMessages(['legal_entity_id' => __('Unknown legal entity.')]));
    }

    private function configure(Request $request): void
    {
        abort_unless($request->user()->hasPermission(P::OnboardingConfigure), 403);
    }

    private function present(OnboardingTemplate $t): array
    {
        return [
            'id' => $t->public_id, 'name' => $t->name, 'description' => $t->description, 'status' => $t->status,
            'legal_entity' => $t->legalEntity ? ['id' => $t->legalEntity->public_id, 'name' => $t->legalEntity->name, 'country_code' => $t->legalEntity->country_code] : null,
            'tasks' => $t->tasks->map(fn ($x) => ['id' => $x->public_id, 'stage' => $x->stage, 'title' => $x->title, 'description' => $x->description,
                'assignee' => $x->assignee, 'assignee_user' => $x->assigneeUser ? ['id' => $x->assigneeUser->public_id, 'name' => $x->assigneeUser->name] : null,
                'due_offset_days' => $x->due_offset_days, 'document_key' => $x->document_key, 'document_name' => $x->document_name])->values(),
        ];
    }
}

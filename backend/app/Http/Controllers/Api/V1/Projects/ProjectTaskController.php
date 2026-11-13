<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectTask;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Project tasks. Managers and the project manager create and assign them; the assignee may only move the status. */
class ProjectTaskController extends Controller
{
    use Concerns;

    public const STATUSES = ['todo', 'in_progress', 'done'];

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->viewProject($request, $project);
        $data = $request->validate(['status' => ['nullable', Rule::in(self::STATUSES)], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $page = ProjectTask::query()->with('assignee')->where('project_id', $project->id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("case status when 'in_progress' then 0 when 'todo' then 1 else 2 end")->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 50));

        return response()->json(['data' => $page->getCollection()->map(fn ($t) => $this->present($t))->values(), 'meta' => $this->meta($page)]);
    }

    /** Open tasks assigned to the signed-in employee across their projects. */
    public function mine(Request $request): JsonResponse
    {
        $this->viewer($request);
        $data = $request->validate(['include_done' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $page = ProjectTask::query()->with(['project', 'assignee'])->where('assignee_employee_id', $this->me($request) ?? 0)
            ->when(! ($data['include_done'] ?? false), fn ($q) => $q->where('status', '!=', 'done'))
            ->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($t) => $this->present($t, true))->values(), 'meta' => $this->meta($page)]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->manageProject($request, $project);
        abort_if(in_array($project->status, ['completed', 'cancelled'], true), 422, __('This project is closed.'));
        $data = $this->validated($request, true);
        $assignee = $this->assignee($project, $data['assignee_id'] ?? null);
        $task = (new ProjectTask)->forceFill([
            'project_id' => $project->id, 'title' => $data['title'], 'description' => $data['description'] ?? null, 'assignee_employee_id' => $assignee?->id,
            'status' => $data['status'] ?? 'todo', 'due_on' => $data['due_on'] ?? null, 'estimate_hours' => $data['estimate_hours'] ?? null, 'version' => 1,
        ]);
        $task->save();

        return response()->json(['data' => $this->present($task->load('assignee'))], 201);
    }

    public function update(Request $request, ProjectTask $task): JsonResponse
    {
        $project = $task->project;
        $this->viewProject($request, $project);
        $manages = $this->canManage($request) || $this->leads($project, $this->me($request));
        $data = $this->validated($request, false) + $request->validate(['version' => ['required', 'integer']]);
        if (! $manages) {
            abort_unless((int) $task->assignee_employee_id === $this->me($request), 403);
            abort_if(count(array_diff(array_keys($data), ['status', 'version'])) > 0, 403, __('Only the status of your own task can be changed.'));
        }
        $assignee = array_key_exists('assignee_id', $data) ? $this->assignee($project, $data['assignee_id']) : null;
        $task = DB::transaction(function () use ($task, $data, $assignee) {
            $t = ProjectTask::query()->lockForUpdate()->findOrFail($task->id);
            $this->checkVersion($t, $data['version']);
            abort_if(in_array(Project::query()->whereKey($t->project_id)->value('status'), ['completed', 'cancelled'], true), 422, __('This project is closed.'));
            foreach (['title', 'description', 'status', 'due_on', 'estimate_hours'] as $field) {
                if (array_key_exists($field, $data)) {
                    $t->{$field} = $data[$field];
                }
            }
            if (array_key_exists('assignee_id', $data)) {
                $t->assignee_employee_id = $assignee?->id;
            }
            $t->version = $t->version + 1;
            $t->save();

            return $t;
        });

        return response()->json(['data' => $this->present($task->load('assignee'))]);
    }

    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'assignee_id' => ['nullable', 'string', 'max:26'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'due_on' => ['nullable', 'date'],
            'estimate_hours' => ['nullable', 'numeric', 'between:0,99999.99'],
        ]);
    }

    private function assignee(Project $project, ?string $publicId): ?Employee
    {
        if (! $publicId) {
            return null;
        }
        $employee = Employee::query()->where('public_id', $publicId)->first();
        if (! $employee || ! ProjectMember::query()->where('project_id', $project->id)->where('employee_id', $employee->id)->exists()) {
            throw ValidationException::withMessages(['assignee_id' => __('Assign the task to a member of this project.')]);
        }

        return $employee;
    }

    private function present(ProjectTask $t, bool $withProject = false): array
    {
        return [
            'id' => $t->public_id, 'title' => $t->title, 'description' => $t->description, 'status' => $t->status, 'assignee' => $this->person($t->assignee),
            'due_on' => $t->due_on?->toDateString(), 'estimate_hours' => $t->estimate_hours, 'version' => (int) $t->version,
        ] + ($withProject ? ['project' => ['id' => $t->project->public_id, 'code' => $t->project->code, 'name' => $t->project->name]] : []);
    }
}

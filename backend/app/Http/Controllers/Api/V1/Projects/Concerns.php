<?php

namespace App\Http\Controllers\Api\V1\Projects;

use App\Models\Employee;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Security\Permission as P;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Shared access rules. projects.manage sees and manages every project; a project's manager manages its
 * members and tasks; members see their projects and log time to active ones. Everything else is 403/404.
 */
trait Concerns
{
    protected function canManage(Request $request): bool
    {
        return $request->user()->hasPermission(P::ProjectsManage);
    }

    protected function viewer(Request $request): void
    {
        abort_unless($request->user()->hasPermission(P::ProjectsView) || $this->canManage($request), 403);
    }

    protected function manager(Request $request): void
    {
        abort_unless($this->canManage($request), 403);
    }

    protected function me(Request $request): ?int
    {
        return $request->user()->employee?->id;
    }

    protected function leads(Project $project, ?int $me): bool
    {
        return $me !== null && (int) $project->manager_employee_id === $me;
    }

    protected function isMember(Project $project, ?int $me): bool
    {
        return $this->leads($project, $me)
            || ($me !== null && ProjectMember::query()->where('project_id', $project->id)->where('employee_id', $me)->exists());
    }

    protected function viewProject(Request $request, Project $project): void
    {
        $this->viewer($request);
        abort_unless($this->canManage($request) || $this->isMember($project, $this->me($request)), 404);
    }

    protected function manageProject(Request $request, Project $project): void
    {
        $this->viewProject($request, $project);
        abort_unless($this->canManage($request) || $this->leads($project, $this->me($request)), 403);
    }

    protected function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    protected function person(?Employee $e): ?array
    {
        return $e ? ['id' => $e->public_id, 'name' => trim(($e->preferred_name ?: $e->first_name).' '.$e->last_name), 'employee_number' => $e->employee_number] : null;
    }

    protected function checkVersion(object $model, int $version): void
    {
        abort_if((int) $model->version !== $version, 409, __('This record was changed by someone else. Reload and try again.'));
    }
}

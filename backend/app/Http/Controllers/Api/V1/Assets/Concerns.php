<?php

namespace App\Http\Controllers\Api\V1\Assets;

use App\Models\Employee;
use App\Security\Permission as P;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

trait Concerns
{
    protected const CONDITIONS = ['new', 'good', 'fair', 'poor', 'damaged'];

    protected const CURRENCIES = ['USD', 'AED', 'CAD', 'MXN'];

    protected function manager(Request $request): void
    {
        abort_unless($request->user()->hasPermission(P::AssetsManage), 403);
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

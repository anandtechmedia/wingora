<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\StoredFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** Lists an employee's documents the caller may open. Uploads and downloads use /files, which re-check the policy. */
class EmployeeDocumentController extends Controller
{
    public function index(Request $request, Employee $employee): JsonResponse
    {
        Gate::authorize('view', $employee);
        $user = $request->user();
        $files = $employee->documents()->with('uploader:id,name')->latest()->limit(200)->get()->filter(fn (StoredFile $f) => $user->can('view', $f));

        return response()->json([
            'data' => $files->values()->map(fn (StoredFile $f) => [
                'id' => $f->public_id, 'category' => $f->category, 'name' => $f->original_name, 'mime_type' => $f->mime_type, 'size' => $f->size,
                'uploaded_by' => $f->uploader?->name, 'created_at' => $f->created_at?->toIso8601String(), 'can_delete' => $user->can('delete', $f),
            ]),
            'meta' => ['upload_categories' => array_values(array_filter(StoredFile::CATEGORIES, fn ($c) => $user->can('create', [StoredFile::class, $employee, $c])))],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\StoredFile;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Private files: never publicly addressable, every download re-checks the policy. */
class FileController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $cfg = config('security.files');
        $data = $request->validate([
            'file' => ['required', File::types($cfg['extensions'])->max($cfg['max_kilobytes']), 'mimetypes:'.implode(',', $cfg['mime_types'])],
            'category' => ['required', Rule::in(StoredFile::CATEGORIES)],
            'employee_id' => ['nullable', 'string', 'ulid'],
        ]);

        $upload = $data['file'];
        $claimed = strtolower($upload->getClientOriginalExtension());
        $detected = strtolower((string) $upload->guessExtension());
        $equivalent = ['jpeg' => 'jpg', 'csv' => 'txt'];
        if (($equivalent[$claimed] ?? $claimed) !== ($equivalent[$detected] ?? $detected)) {
            throw ValidationException::withMessages(['file' => 'The file content does not match its extension.']);
        }

        $employee = isset($data['employee_id']) ? Employee::query()->where('public_id', $data['employee_id'])->firstOrFail() : null;
        Gate::authorize('create', [StoredFile::class, $employee, $data['category']]);

        $path = Storage::disk($cfg['disk'])->putFileAs(
            'tenants/'.Tenancy::current()->public_id.'/'.$data['category'],
            $upload,
            Str::ulid().'.'.$detected,
        );

        $file = new StoredFile;
        $file->forceFill([
            'uploaded_by' => $request->user()->id,
            'employee_id' => $employee?->id,
            'category' => $data['category'],
            'disk' => $cfg['disk'],
            'path' => $path,
            'original_name' => Str::limit(preg_replace('/[^\w.\- ]+/u', '_', $upload->getClientOriginalName()), 190, ''),
            'mime_type' => $upload->getMimeType(),
            'size' => $upload->getSize(),
            'sha256' => hash_file('sha256', $upload->getRealPath()),
        ])->save();

        return response()->json(['data' => $this->present($file)], 201);
    }

    public function show(StoredFile $file): JsonResponse
    {
        Gate::authorize('view', $file);

        return response()->json(['data' => $this->present($file)]);
    }

    public function download(StoredFile $file): StreamedResponse
    {
        Gate::authorize('view', $file);
        AuditLog::record('file.downloaded', $file, metadata: ['file_category' => $file->category, 'mime_type' => $file->mime_type, 'size' => $file->size]);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function destroy(StoredFile $file): JsonResponse
    {
        Gate::authorize('delete', $file);
        $file->delete();

        return response()->json(null, 204);
    }

    private function present(StoredFile $file): array
    {
        return [
            'id' => $file->public_id,
            'category' => $file->category,
            'name' => $file->original_name,
            'mime_type' => $file->mime_type,
            'size' => $file->size,
            'sha256' => $file->sha256,
            'created_at' => $file->created_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Recruitment;

use App\Models\StoredFile;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

/**
 * Stores a recruitment or onboarding document on the private disk with the same checks as /files:
 * allow-listed types, size limit, and the detected content type must match the extension.
 */
final class PrivateUpload
{
    /** @param list<string>|null $extensions narrower allow-list, e.g. CVs */
    public static function rules(?array $extensions = null, bool $required = true): array
    {
        $cfg = config('security.files');
        $types = $extensions === null ? $cfg['extensions'] : array_values(array_intersect($extensions, $cfg['extensions']));

        return [$required ? 'required' : 'nullable', File::types($types)->max($cfg['max_kilobytes']), 'mimetypes:'.implode(',', $cfg['mime_types'])];
    }

    public static function store(UploadedFile $upload, string $category, ?int $uploadedBy, string $field = 'file'): StoredFile
    {
        $cfg = config('security.files');
        $claimed = strtolower($upload->getClientOriginalExtension());
        $detected = strtolower((string) $upload->guessExtension());
        $equivalent = ['jpeg' => 'jpg', 'csv' => 'txt'];
        if (($equivalent[$claimed] ?? $claimed) !== ($equivalent[$detected] ?? $detected)) {
            throw ValidationException::withMessages([$field => __('The file content does not match its extension.')]);
        }

        $path = Storage::disk($cfg['disk'])->putFileAs('tenants/'.Tenancy::current()->public_id.'/'.$category, $upload, Str::ulid().'.'.$detected);
        $file = new StoredFile;
        $file->forceFill([
            'uploaded_by' => $uploadedBy,
            'category' => $category,
            'disk' => $cfg['disk'],
            'path' => $path,
            'original_name' => Str::limit(preg_replace('/[^\w.\- ]+/u', '_', $upload->getClientOriginalName()), 190, ''),
            'mime_type' => $upload->getMimeType(),
            'size' => $upload->getSize(),
            'sha256' => hash_file('sha256', $upload->getRealPath()),
        ])->save();

        return $file;
    }

    /** Removes the stored bytes and the record; used by erasure and retention. */
    public static function destroy(?StoredFile $file): void
    {
        if ($file === null) {
            return;
        }
        Storage::disk($file->disk)->delete($file->path);
        $file->forceDelete();
    }
}

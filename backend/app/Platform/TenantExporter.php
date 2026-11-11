<?php

namespace App\Platform;

use App\Models\DataExport;
use App\Models\DeletionRequest;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\SupportGrant;
use App\Models\Tenant;
use App\Models\TenantModel;
use App\Models\User;
use App\Support\Csv;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Builds the organization's complete export: one CSV per kind of record (decrypted, with internal ids so rows can be
 * related) plus every stored document, in a private ZIP that expires after DataExport::RETENTION_DAYS.
 */
final class TenantExporter
{
    private const SKIP = [DataExport::class, DeletionRequest::class, Subscription::class, SupportGrant::class];

    public function build(DataExport $export): void
    {
        $tenant = Tenant::query()->findOrFail($export->tenant_id);
        $tmp = tempnam(sys_get_temp_dir(), 'wingora-export-');
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export archive.');
        }
        try {
            Tenancy::run($tenant, function () use ($zip, $tenant) {
                foreach (self::models() as $class) {
                    $csv = $this->csv($class::query()->orderBy('id')->lazyById(500));
                    if ($csv !== null) {
                        $zip->addFromString('data/'.Str::snake(Str::pluralStudly(class_basename($class))).'.csv', $csv);
                    }
                }
                $users = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->with('roles')->orderBy('id')->get()
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status,
                        'roles' => $u->effectiveRoles()->pluck('key')->implode(' '), 'last_login_at' => $u->last_login_at?->toIso8601String(), 'created_at' => $u->created_at?->toIso8601String()]);
                $zip->addFromString('data/users.csv', Csv::build([array_keys($users->first() ?? ['id' => 0]), ...$users->map(fn ($r) => array_values($r))]));

                StoredFile::query()->orderBy('id')->lazyById(200)->each(function (StoredFile $file) use ($zip) {
                    $disk = Storage::disk($file->disk);
                    if ($disk->exists($file->path)) {
                        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $file->original_name) ?: 'file';
                        $zip->addFromString('files/'.$file->public_id.'-'.Str::limit($name, 120, ''), $disk->get($file->path));
                    }
                });
                $zip->addFromString('README.txt', "Wingora data export for {$tenant->name}, created ".now()->toIso8601String().".\n"
                    ."data/: one CSV per record type; *_id columns refer to the id column of the related file.\nfiles/: stored documents, named <stored file public_id>-<original name>.\n");
            });
            $zip->close();
            $path = 'exports/'.$tenant->public_id.'/'.$export->public_id.'.zip';
            $stream = fopen($tmp, 'r');
            Storage::disk('private')->put($path, $stream);
            is_resource($stream) && fclose($stream);
            $export->forceFill(['status' => 'ready', 'disk' => 'private', 'path' => $path, 'size' => filesize($tmp),
                'completed_at' => now(), 'expires_at' => now()->addDays(DataExport::RETENTION_DAYS)])->saveQuietly();
        } finally {
            @unlink($tmp);
        }
    }

    /** @return list<class-string<TenantModel>> */
    public static function models(): array
    {
        $models = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, TenantModel::class) && ! in_array($class, self::SKIP, true)) {
                $models[] = $class;
            }
        }
        sort($models);

        return $models;
    }

    /** @param iterable<Model> $rows */
    private function csv(iterable $rows): ?string
    {
        $header = null;
        $lines = [];
        foreach ($rows as $model) {
            // Keep the model's own hidden attributes (secrets, storage paths) hidden, but include ids for relations.
            $model->setHidden(array_values(array_filter(
                array_diff($model->getHidden(), ['id']),
                fn ($a) => $a === 'tenant_id' || ! str_ends_with($a, '_id'),
            )));
            $data = array_map(fn ($v) => is_array($v) || is_object($v) ? json_encode($v) : $v, $model->attributesToArray());
            $header ??= array_keys($data);
            $lines[] = array_map(fn ($k) => $data[$k] ?? null, $header);
        }

        return $header === null ? null : Csv::build([$header, ...$lines]);
    }
}

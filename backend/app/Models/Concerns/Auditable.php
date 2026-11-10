<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => AuditLog::record($model->auditEvent('created'), $model, [], $model->auditValues($model->getAttributes())));

        static::updated(function (Model $model) {
            $changes = collect($model->getChanges())->except(['updated_at', 'remember_token'])->all();
            if ($changes === []) {
                return;
            }
            $old = collect($changes)->mapWithKeys(fn ($v, $key) => [$key => $model->getRawOriginal($key)])->all();
            AuditLog::record($model->auditEvent('updated'), $model, $model->auditValues($old), $model->auditValues($changes));
        });

        static::deleted(fn (Model $model) => AuditLog::record($model->auditEvent('deleted'), $model));
    }

    public function auditEvent(string $action): string
    {
        return $this->getMorphClass().'.'.$action;
    }

    /** Hidden and encrypted attributes are never written to the audit trail. */
    public function auditValues(array $values): array
    {
        $sensitive = array_merge($this->getHidden(), array_keys(array_filter(
            $this->getCasts(),
            fn ($cast) => is_string($cast) && str_starts_with($cast, 'encrypted') || $cast === 'hashed',
        )), $this->auditRedacted ?? []);

        return collect($values)
            ->except(['id', 'created_at', 'updated_at', 'remember_token'])
            ->map(fn ($value, $key) => in_array($key, $sensitive, true) ? '[REDACTED]' : $value)
            ->all();
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use LogicException;

/** Append-only audit trail of data changes and sensitive reads. */
class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit logs are immutable.'));
    }

    public function allowsNullTenant(): bool
    {
        return true;
    }

    public static function record(string $event, ?Model $subject = null, array $old = [], array $new = []): void
    {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        static::query()->withoutGlobalScopes()->insert([
            'tenant_id' => match (true) {
                $subject instanceof Tenant => $subject->id,
                $subject !== null && array_key_exists('tenant_id', $subject->getAttributes()) => $subject->getAttribute('tenant_id'),
                default => Tenancy::id(),
            },
            'actor_user_id' => Auth::id(),
            'event' => substr($event, 0, 80),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'old_values' => $old ? json_encode($old) : null,
            'new_values' => $new ? json_encode($new) : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
            'request_id' => Context::get('request_id'),
            'created_at' => now(),
        ]);
    }
}

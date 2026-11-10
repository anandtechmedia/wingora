<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use LogicException;

/** Append-only security event log, mirrored to the `security` log channel. */
class SecurityEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Security events are immutable.'));
        static::deleting(fn () => throw new LogicException('Security events are immutable.'));
    }

    public function allowsNullTenant(): bool
    {
        return true;
    }

    /** Also mirrored into the central audit trail unless $audit is false (when the caller writes its own audit entry). */
    public static function record(string $type, string $severity = 'info', ?User $user = null, array $metadata = [], ?int $tenantId = null, bool $audit = true): void
    {
        $request = request();
        $row = [
            'tenant_id' => $tenantId ?? $user?->tenant_id ?? Tenancy::id(),
            'user_id' => $user?->id,
            'type' => $type,
            'severity' => $severity,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'request_id' => Context::get('request_id'),
            'metadata' => $metadata ? json_encode($metadata) : null,
            'created_at' => now(),
        ];

        static::query()->withoutGlobalScopes()->insert($row);
        if ($audit) {
            AuditLog::record($type, null, [], [], $metadata,
                outcome: preg_match('/failed|blocked|denied|locked|missing/', $type) ? 'failure' : 'success',
                tenantId: $row['tenant_id'], actor: $user);
        }

        Log::channel('security')->log($severity === 'critical' ? 'critical' : ($severity === 'warning' ? 'warning' : 'info'), $type, array_merge($row, ['metadata' => $metadata]));
    }
}

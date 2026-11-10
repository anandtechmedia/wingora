<?php

namespace App\Models;

use App\Audit\AppendOnlyBuilder;
use App\Audit\AuditCategory;
use App\Audit\AuditChain;
use App\Audit\Redactor;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LogicException;

/** Centralized, append-only, hash-chained audit trail. Write only through AuditLog::record(). */
class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id', 'actor_user_id', 'subject_id', 'chain', 'prev_hash'];

    protected function casts(): array
    {
        return [
            'category' => AuditCategory::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are immutable.'));
        static::deleting(fn () => throw new LogicException('Audit logs are immutable.'));
    }

    public function newEloquentBuilder($query): AppendOnlyBuilder
    {
        return new AppendOnlyBuilder($query);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function allowsNullTenant(): bool
    {
        return true;
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withoutGlobalScopes();
    }

    /**
     * @param  array  $old  before-values (redacted)
     * @param  array  $new  after-values (redacted)
     * @param  array  $metadata  context such as filters or counts (redacted)
     */
    public static function record(
        string $event,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        array $metadata = [],
        string $outcome = 'success',
        ?AuditCategory $category = null,
        ?int $tenantId = null,
        ?User $actor = null,
    ): void {
        $console = app()->runningInConsole() && ! app()->runningUnitTests();
        $request = $console ? null : request();
        $authenticated = Auth::hasUser() ? Auth::user() : null;
        $actor ??= $authenticated;
        $route = $request?->route();
        $encode = fn (array $values) => $values ? json_encode(Redactor::clean($values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $tenantId ??= match (true) {
            $subject instanceof Tenant => $subject->id,
            $subject !== null && array_key_exists('tenant_id', $subject->getAttributes()) => $subject->getAttribute('tenant_id'),
            default => Tenancy::id(),
        };

        AuditChain::append([
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenantId === null ? null : (int) $tenantId,
            'actor_user_id' => $actor?->id,
            'actor_type' => match (true) {
                $actor !== null && $authenticated !== null && $actor->is($authenticated) => 'user',
                $actor !== null => 'unauthenticated',
                $console => 'system',
                default => 'anonymous',
            },
            'category' => ($category ?? AuditCategory::forEvent($event, $subject))->value,
            'event' => substr($event, 0, 80),
            'outcome' => $outcome === 'failure' ? 'failure' : 'success',
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_public_id' => $subject?->getAttributes()['public_id'] ?? null,
            'old_values' => $encode($old),
            'new_values' => $encode($new),
            'metadata' => $encode($metadata),
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 255) : null,
            'http_method' => $request?->method(),
            'route' => $route ? substr((string) ($route->getName() ?? $route->uri()), 0, 120) : null,
            'request_id' => Context::get('request_id'),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Context;
use LogicException;

/** Append-only approval history for rule versions. */
class RuleVersionEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Rule history is immutable.'));
        static::deleting(fn () => throw new LogicException('Rule history is immutable.'));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withoutGlobalScopes();
    }

    public static function log(RuleVersion $version, string $action, ?string $from, ?string $to, ?User $actor, ?string $comment = null): void
    {
        $event = new self;
        $event->forceFill([
            'rule_version_id' => $version->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor?->id,
            'comment' => $comment,
            'request_id' => Context::get('request_id'),
        ])->save();
    }
}

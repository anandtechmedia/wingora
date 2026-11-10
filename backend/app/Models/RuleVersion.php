<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class RuleVersion extends Model
{
    use Auditable, HasPublicId;

    public const DRAFT = 'draft';

    public const PENDING = 'pending_approval';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const RETIRED = 'retired';

    /** Content columns frozen once a version leaves draft. */
    private const CONTENT = ['rule_set_id', 'version', 'payload', 'effective_from', 'change_summary', 'legal_reference', 'created_by'];

    protected $attributes = ['status' => self::DRAFT];

    protected $fillable = ['effective_from', 'effective_to', 'payload', 'change_summary', 'legal_reference'];

    protected $hidden = ['id', 'rule_set_id', 'created_by', 'updated_by', 'submitted_by', 'approved_by'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $version) {
            if ($version->getOriginal('status') !== self::DRAFT && $version->isDirty(self::CONTENT)) {
                throw new LogicException('Submitted rule versions are immutable; create a new version.');
            }
        });
        static::deleting(fn (self $version) => $version->status === self::DRAFT
            ?: throw new LogicException('Only draft rule versions can be deleted.'));
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(RuleVersionEvent::class)->orderBy('id');
    }

    /** Approved versions whose effective range contains $date (Y-m-d). */
    public function scopeEffectiveOn(Builder $query, string $date): void
    {
        $query->where('status', self::APPROVED)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date));
    }

    /** Approved versions whose effective range overlaps [$from, $to]. */
    public function scopeEffectiveBetween(Builder $query, string $from, string $to): void
    {
        $query->where('status', self::APPROVED)
            ->whereDate('effective_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from));
    }

    public function authoredBy(User $user): bool
    {
        return in_array($user->id, array_filter([$this->created_by, $this->updated_by, $this->submitted_by]), true);
    }
}

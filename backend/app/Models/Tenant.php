<?php

namespace App\Models;

use App\Billing\Subscriptions;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    use Auditable, HasFactory, HasPublicId;

    protected $attributes = ['status' => 'active', 'mfa_required' => false, 'audit_legal_hold' => false];

    protected $fillable = ['name', 'slug', 'country_code'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['mfa_required' => 'boolean', 'audit_legal_hold' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Every organization starts on a free trial; access rules depend on there being a subscription.
        static::created(fn (Tenant $tenant) => app(Subscriptions::class)->startTrial($tenant));
    }

    public function subscription(): HasOne
    {
        // Keyed by tenant_id, so it is safe to load outside a tenant context (platform console).
        return $this->hasOne(Subscription::class)->withoutGlobalScopes();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

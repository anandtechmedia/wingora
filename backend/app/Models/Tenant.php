<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    use Auditable, HasFactory, HasPublicId;

    protected $attributes = ['status' => 'active', 'mfa_required' => false];

    protected $fillable = ['name', 'slug', 'country_code'];

    protected $hidden = ['id'];

    protected function casts(): array
    {
        return ['mfa_required' => 'boolean'];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

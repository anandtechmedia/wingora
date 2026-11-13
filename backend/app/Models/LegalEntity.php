<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A registered employer in one country. A tenant may own many, across countries. */
class LegalEntity extends TenantModel
{
    protected $attributes = ['status' => 'active'];

    protected $fillable = ['name', 'legal_name', 'country_code', 'subdivision_code', 'currency_code', 'default_language_code',
        'registration_number', 'tax_identifier', 'address_line1', 'city', 'postal_code', 'status'];

    protected $hidden = ['id', 'tenant_id', 'registration_number', 'tax_identifier'];

    protected function casts(): array
    {
        return ['registration_number' => 'encrypted', 'tax_identifier' => 'encrypted'];
    }

    public function workLocations(): HasMany
    {
        return $this->hasMany(WorkLocation::class);
    }
}

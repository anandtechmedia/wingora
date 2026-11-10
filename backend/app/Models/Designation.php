<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Designation extends TenantModel
{
    protected $attributes = ['status' => 'active'];

    protected $fillable = ['name'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}

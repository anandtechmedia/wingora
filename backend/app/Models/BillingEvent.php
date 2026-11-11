<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A verified, processed payment webhook event. Only identifiers are kept, never the payload. */
class BillingEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $hidden = ['id', 'tenant_id'];
}

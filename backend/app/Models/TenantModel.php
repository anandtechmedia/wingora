<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    use Auditable, BelongsToTenant, HasPublicId;

    protected $hidden = ['id', 'tenant_id'];
}

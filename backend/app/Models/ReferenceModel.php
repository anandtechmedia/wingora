<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Global reference data: read-only through the API, maintained by reviewed seeders. */
abstract class ReferenceModel extends Model
{
    protected $hidden = ['id', 'created_at', 'updated_at'];

    protected $guarded = ['id'];
}

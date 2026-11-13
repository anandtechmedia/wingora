<?php

namespace App\Models;

class Currency extends ReferenceModel
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'minor_units' => 'integer'];
    }
}

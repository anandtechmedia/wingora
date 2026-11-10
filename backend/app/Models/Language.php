<?php

namespace App\Models;

/** `active` languages are served; `planned` ones (Arabic, French) have the architecture but no translations yet. */
class Language extends ReferenceModel
{
    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}

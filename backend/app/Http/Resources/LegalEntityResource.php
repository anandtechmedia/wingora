<?php

namespace App\Http\Resources;

use App\Models\LegalEntity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LegalEntity */
class LegalEntityResource extends JsonResource
{
    private bool $withIdentifiers = false;

    public function withIdentifiers(bool $include = true): static
    {
        $this->withIdentifiers = $include;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'country_code' => $this->country_code,
            'subdivision_code' => $this->subdivision_code,
            'currency_code' => $this->currency_code,
            'default_language_code' => $this->default_language_code,
            'address_line1' => $this->address_line1,
            'city' => $this->city,
            'postal_code' => $this->postal_code,
            'status' => $this->status,
            'registration_number' => $this->when($this->withIdentifiers, fn () => $this->registration_number),
            'tax_identifier' => $this->when($this->withIdentifiers, fn () => $this->tax_identifier),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\WorkLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkLocation */
class WorkLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'code' => $this->code,
            'legal_entity' => $this->whenLoaded('legalEntity', fn () => ['id' => $this->legalEntity->public_id, 'name' => $this->legalEntity->name]),
            'holiday_calendar' => $this->whenLoaded('holidayCalendar', fn () => $this->holidayCalendar ? ['id' => $this->holidayCalendar->public_id, 'name' => $this->holidayCalendar->name] : null),
            'country_code' => $this->country_code,
            'subdivision_code' => $this->subdivision_code,
            'city' => $this->city,
            'address_line1' => $this->address_line1,
            'postal_code' => $this->postal_code,
            'timezone' => $this->timezone,
            'is_remote' => $this->is_remote,
            'status' => $this->status,
        ];
    }
}

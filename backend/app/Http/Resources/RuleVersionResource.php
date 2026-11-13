<?php

namespace App\Http\Resources;

use App\Models\RuleVersion;
use App\Models\RuleVersionEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RuleVersion */
class RuleVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'rule_set' => $this->whenLoaded('ruleSet', fn () => [
                'id' => $this->ruleSet->public_id,
                'key' => $this->ruleSet->key,
                'name' => $this->ruleSet->name,
                'category' => $this->ruleSet->category->value,
                'country_code' => $this->ruleSet->country_code,
                'subdivision_code' => $this->ruleSet->subdivision_code,
                'jurisdiction' => $this->ruleSet->jurisdiction(),
            ]),
            'version' => $this->version,
            'status' => $this->status,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'payload' => $this->payload,
            'change_summary' => $this->change_summary,
            'legal_reference' => $this->legal_reference,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'history' => $this->whenLoaded('events', fn () => $this->events->map(fn (RuleVersionEvent $e) => [
                'action' => $e->action,
                'from_status' => $e->from_status,
                'to_status' => $e->to_status,
                'actor' => $e->actor ? ['id' => $e->actor->public_id, 'name' => $e->actor->name] : null,
                'comment' => $e->comment,
                'at' => $e->created_at->toIso8601String(),
            ])),
        ];
    }
}

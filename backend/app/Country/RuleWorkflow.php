<?php

namespace App\Country;

use App\Models\RuleSet;
use App\Models\RuleVersion;
use App\Models\RuleVersionEvent;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * draft -> pending_approval -> approved | rejected; approved -> retired.
 * Every transition is written to the append-only history. Authorization (including maker-checker) is
 * enforced by RuleVersionPolicy before these methods are called.
 */
class RuleWorkflow
{
    public function createDraft(RuleSet $set, array $data, User $actor): RuleVersion
    {
        return DB::transaction(function () use ($set, $data, $actor) {
            RuleSet::query()->whereKey($set->id)->lockForUpdate()->first();
            $version = new RuleVersion($data);
            $version->forceFill([
                'rule_set_id' => $set->id,
                'version' => (int) $set->versions()->max('version') + 1,
                'created_by' => $actor->id,
            ])->save();
            RuleVersionEvent::log($version, 'created', null, RuleVersion::DRAFT, $actor);

            return $version;
        });
    }

    public function updateDraft(RuleVersion $version, array $data, User $actor): RuleVersion
    {
        $this->expectStatus($version, RuleVersion::DRAFT);
        DB::transaction(function () use ($version, $data, $actor) {
            $version->fill($data)->forceFill(['updated_by' => $actor->id])->save();
            RuleVersionEvent::log($version, 'updated', RuleVersion::DRAFT, RuleVersion::DRAFT, $actor);
        });

        return $version;
    }

    public function submit(RuleVersion $version, User $actor, ?string $comment): RuleVersion
    {
        return $this->transition($version, RuleVersion::DRAFT, RuleVersion::PENDING, 'submitted', $actor, $comment,
            ['submitted_by' => $actor->id, 'submitted_at' => now()]);
    }

    public function reject(RuleVersion $version, User $actor, string $comment): RuleVersion
    {
        return $this->transition($version, RuleVersion::PENDING, RuleVersion::REJECTED, 'rejected', $actor, $comment);
    }

    public function retire(RuleVersion $version, User $actor, string $comment): RuleVersion
    {
        return $this->transition($version, RuleVersion::APPROVED, RuleVersion::RETIRED, 'retired', $actor, $comment);
    }

    /**
     * Approving a version that starts after an open-ended approved version end-dates the older one
     * (recorded as "superseded"). Any other overlap is a conflict.
     */
    public function approve(RuleVersion $version, User $actor, ?string $comment): RuleVersion
    {
        return DB::transaction(function () use ($version, $actor, $comment) {
            RuleSet::query()->whereKey($version->rule_set_id)->lockForUpdate()->first();
            $version->refresh();
            $this->expectStatus($version, RuleVersion::PENDING);

            $from = $version->effective_from->toDateString();
            $to = $version->effective_to?->toDateString() ?? '9999-12-31';
            $overlapping = RuleVersion::query()->where('rule_set_id', $version->rule_set_id)->whereKeyNot($version->id)
                ->effectiveBetween($from, $to)->get();

            foreach ($overlapping as $other) {
                if ($other->effective_to !== null || ! $other->effective_from->lt($version->effective_from)) {
                    throw ValidationException::withMessages(['effective_from' => "Overlaps approved version {$other->version}; adjust the effective dates."]);
                }
                $other->forceFill(['effective_to' => Carbon::parse($from)->subDay()->toDateString()])->save();
                RuleVersionEvent::log($other, 'superseded', RuleVersion::APPROVED, RuleVersion::APPROVED, $actor, "End-dated by version {$version->version}");
            }

            $version->forceFill(['status' => RuleVersion::APPROVED, 'approved_by' => $actor->id, 'approved_at' => now()])->save();
            RuleVersionEvent::log($version, 'approved', RuleVersion::PENDING, RuleVersion::APPROVED, $actor, $comment);
            SecurityEvent::record('country_rules.approved', 'info', $actor, ['rule_version' => $version->public_id]);

            return $version;
        });
    }

    private function transition(RuleVersion $version, string $from, string $to, string $action, User $actor, ?string $comment, array $extra = []): RuleVersion
    {
        return DB::transaction(function () use ($version, $from, $to, $action, $actor, $comment, $extra) {
            RuleSet::query()->whereKey($version->rule_set_id)->lockForUpdate()->first();
            $version->refresh();
            $this->expectStatus($version, $from);
            $version->forceFill(['status' => $to] + $extra)->save();
            RuleVersionEvent::log($version, $action, $from, $to, $actor, $comment);

            return $version;
        });
    }

    private function expectStatus(RuleVersion $version, string $status): void
    {
        if ($version->status !== $status) {
            abort(409, "Rule version must be {$status}; it is {$version->status}.");
        }
    }
}

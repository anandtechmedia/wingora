<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Country\RuleWorkflow;
use App\Http\Controllers\Controller;
use App\Http\Resources\RuleVersionResource;
use App\Models\RuleVersion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RuleVersionController extends Controller
{
    public function __construct(private readonly RuleWorkflow $workflow) {}

    public function show(RuleVersion $ruleVersion): RuleVersionResource
    {
        Gate::authorize('view', $ruleVersion);

        return $this->present($ruleVersion);
    }

    public function update(Request $request, RuleVersion $ruleVersion): RuleVersionResource
    {
        Gate::authorize('update', $ruleVersion);
        $data = RuleSetController::validateVersion($request, $ruleVersion->ruleSet);

        return $this->present($this->workflow->updateDraft($ruleVersion, $data, $request->user()));
    }

    public function submit(Request $request, RuleVersion $ruleVersion): RuleVersionResource
    {
        Gate::authorize('submit', $ruleVersion);

        return $this->present($this->workflow->submit($ruleVersion, $request->user(), $this->comment($request, false)));
    }

    public function approve(Request $request, RuleVersion $ruleVersion): RuleVersionResource
    {
        Gate::authorize('approve', $ruleVersion);

        return $this->present($this->workflow->approve($ruleVersion, $request->user(), $this->comment($request, false)));
    }

    public function reject(Request $request, RuleVersion $ruleVersion): RuleVersionResource
    {
        Gate::authorize('reject', $ruleVersion);

        return $this->present($this->workflow->reject($ruleVersion, $request->user(), $this->comment($request, true)));
    }

    public function retire(Request $request, RuleVersion $ruleVersion): RuleVersionResource
    {
        Gate::authorize('retire', $ruleVersion);

        return $this->present($this->workflow->retire($ruleVersion, $request->user(), $this->comment($request, true)));
    }

    private function comment(Request $request, bool $required): ?string
    {
        return $request->validate(['comment' => [$required ? 'required' : 'nullable', 'string', 'max:1000']])['comment'] ?? null;
    }

    private function present(RuleVersion $version): RuleVersionResource
    {
        return new RuleVersionResource($version->load(['ruleSet', 'events.actor']));
    }
}

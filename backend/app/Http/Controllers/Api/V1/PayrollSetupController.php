<?php

namespace App\Http\Controllers\Api\V1;

use App\Country\RuleCategory;
use App\Country\RuleResolver;
use App\Http\Controllers\Api\V1\Concerns\PresentsPayroll;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\LegalEntity;
use App\Models\PayrollCalendar;
use App\Models\PayrollPeriod;
use App\Models\SalaryComponent;
use App\Models\SalaryStructure;
use App\Payroll\Money;
use App\Payroll\PayrollCalculator;
use App\Payroll\PeriodGenerator;
use App\Payroll\Providers\ProviderRegistry;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Pay calendars and periods, salary structures and their components, and the configured providers. */
class PayrollSetupController extends Controller
{
    use PresentsPayroll, ResolvesTimeEmployees;

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()->hasPermission(P::PayrollView), 403);
    }

    private function authorizeConfigure(Request $request): void
    {
        abort_unless($request->user()->hasPermission(P::PayrollConfigure), 403);
    }

    private function entity(string $publicId): LegalEntity
    {
        return LegalEntity::query()->where('public_id', $publicId)->first() ?? throw ValidationException::withMessages(['legal_entity' => __('Unknown legal entity.')]);
    }

    /** Pay frequencies the entity's country rules allow on `$date`. Fails closed when no approved payroll rule exists. */
    private function allowedFrequencies(LegalEntity $entity, string $date): array
    {
        return app(RuleResolver::class)->effective($entity->country_code, null, RuleCategory::Payroll, $date)
            ->flatMap(fn ($v) => $v->payload['pay_frequencies'] ?? [])->unique()->values()->all();
    }

    public function providers(Request $request, ProviderRegistry $registry): JsonResponse
    {
        $this->authorizeView($request);

        return response()->json(['data' => collect($registry->all())->map(fn ($p) => ['key' => $p->key(), 'name' => $p->name()])->values()]);
    }

    public function calendars(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $items = PayrollCalendar::query()->with('legalEntity:id,public_id,name,country_code,currency_code')->withCount('periods')->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn ($c) => $this->presentCalendar($c))]);
    }

    public function storeCalendar(Request $request, ProviderRegistry $registry): JsonResponse
    {
        $this->authorizeConfigure($request);
        $data = $request->validate($this->calendarRules($registry) + ['legal_entity' => ['required', 'string', 'max:26'],
            'pay_frequency' => ['required', Rule::in(array_keys(PayrollCalculator::PERIODS_PER_YEAR))], 'first_period_start' => ['required', 'date_format:Y-m-d']]);
        $entity = $this->entity($data['legal_entity']);
        $allowed = $this->allowedFrequencies($entity, $data['first_period_start']);
        if (! in_array($data['pay_frequency'], $allowed, true)) {
            throw ValidationException::withMessages(['pay_frequency' => $allowed === []
                ? __('No approved payroll configuration covers :country on that date.', ['country' => $entity->country_code])
                : __('Allowed pay frequencies for :country: :list.', ['country' => $entity->country_code, 'list' => implode(', ', $allowed)])]);
        }
        if ($data['pay_frequency'] === 'semimonthly' && ! in_array((int) substr($data['first_period_start'], 8, 2), [1, 16], true)) {
            throw ValidationException::withMessages(['first_period_start' => __('Semimonthly periods start on the 1st or the 16th.')]);
        }
        $calendar = new PayrollCalendar(collect($data)->except('legal_entity')->all());
        $calendar->forceFill(['legal_entity_id' => $entity->id])->save();

        return response()->json(['data' => $this->presentCalendar($calendar->load('legalEntity'))], 201);
    }

    public function updateCalendar(Request $request, PayrollCalendar $calendar, ProviderRegistry $registry): JsonResponse
    {
        $this->authorizeConfigure($request);
        $data = $request->validate(collect($this->calendarRules($registry))->map(fn ($r) => array_merge(['sometimes'], array_diff($r, ['required'])))->all()
            + ['status' => ['sometimes', 'in:active,inactive']]);
        $calendar->fill($data)->save();

        return response()->json(['data' => $this->presentCalendar($calendar->load('legalEntity'))]);
    }

    public function generatePeriods(Request $request, PayrollCalendar $calendar, PeriodGenerator $generator): JsonResponse
    {
        $this->authorizeConfigure($request);
        $data = $request->validate(['count' => ['required', 'integer', 'between:1,53']]);
        $periods = DB::transaction(function () use ($calendar, $generator, $data) {
            $locked = PayrollCalendar::query()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();

            return $generator->generate($locked, (int) $data['count']);
        });

        return response()->json(['data' => collect($periods)->map(fn ($p) => $this->presentPeriod($p))], 201);
    }

    public function periods(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $data = $request->validate(['calendar' => ['sometimes', 'string', 'max:26'], 'year' => ['sometimes', 'integer', 'between:2000,2100'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = PayrollPeriod::query()->with(['calendar.legalEntity:id,public_id,name,country_code,currency_code', 'runs:id,public_id,payroll_period_id,type,status'])
            ->when($data['calendar'] ?? null, fn ($q, $c) => $q->whereHas('calendar', fn ($q) => $q->where('public_id', $c)))
            ->when($data['year'] ?? null, fn ($q, $y) => $q->whereBetween('start_date', ["{$y}-01-01", "{$y}-12-31"]))
            ->orderByDesc('start_date')->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($p) => $this->presentPeriod($p, true)), 'meta' => $this->meta($page)]);
    }

    public function structures(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $items = SalaryStructure::query()->with(['legalEntity:id,public_id,name,country_code,currency_code', 'components'])->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn ($s) => $this->presentStructure($s))]);
    }

    public function storeStructure(Request $request): JsonResponse
    {
        $this->authorizeConfigure($request);
        $data = $request->validate(['legal_entity' => ['required', 'string', 'max:26'], 'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_\-]+$/', Rule::unique('salary_structures')->where('tenant_id', $request->user()->tenant_id)]]);
        $structure = new SalaryStructure(['name' => $data['name'], 'code' => $data['code']]);
        $structure->forceFill(['legal_entity_id' => $this->entity($data['legal_entity'])->id])->save();

        return response()->json(['data' => $this->presentStructure($structure->load(['legalEntity', 'components']))], 201);
    }

    public function updateStructure(Request $request, SalaryStructure $structure): JsonResponse
    {
        $this->authorizeConfigure($request);
        $structure->fill($request->validate(['name' => ['sometimes', 'string', 'max:120'], 'status' => ['sometimes', 'in:active,inactive']]))->save();

        return response()->json(['data' => $this->presentStructure($structure->load(['legalEntity', 'components']))]);
    }

    public function storeComponent(Request $request, SalaryStructure $structure): JsonResponse
    {
        $this->authorizeConfigure($request);
        $structure->loadMissing('legalEntity');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_\-]+$/', 'not_in:BASE,LOAN,REIMB'], 'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(SalaryComponent::KINDS)], 'method' => ['required', Rule::in(SalaryComponent::METHODS)],
            'amount' => ['required_if:method,fixed', 'prohibited_unless:method,fixed', 'nullable', 'string', 'max:20'],
            'rate_percent' => ['required_if:method,percent_of_base', 'prohibited_unless:method,percent_of_base', 'nullable', 'numeric', 'between:0.01,100', 'decimal:0,2'],
            'rule_key' => ['nullable', 'string', 'max:40'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'sort_order' => ['sometimes', 'integer', 'between:0,999'],
        ]);
        // Named statutory items from the country rules may only be entered or provider-supplied; Wingora holds no rates for them.
        if (! empty($data['rule_key'])) {
            $keys = app(RuleResolver::class)->effective($structure->legalEntity->country_code, null, RuleCategory::Payroll, $data['effective_from'])
                ->flatMap(fn ($v) => collect($v->payload['statutory_items'] ?? [])->pluck('key'))->all();
            if (! in_array($data['rule_key'], $keys, true)) {
                throw ValidationException::withMessages(['rule_key' => __('Not a statutory item in the country payroll configuration.')]);
            }
            if (in_array($data['method'], ['fixed', 'percent_of_base'], true)) {
                throw ValidationException::withMessages(['method' => __('Statutory items must be entered or provider-supplied; Wingora does not calculate them.')]);
            }
        }
        $currency = $structure->legalEntity->currency_code;
        try {
            $amount = isset($data['amount']) ? Money::parse($data['amount'], $currency) : null;
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        abort_if($amount !== null && $amount <= 0, 422, __('The amount must be positive.'));
        $component = new SalaryComponent(['code' => $data['code'], 'name' => $data['name'], 'kind' => $data['kind'], 'method' => $data['method'],
            'amount_minor' => $amount, 'rate_basis_points' => isset($data['rate_percent']) ? (int) round($data['rate_percent'] * 100) : null,
            'rule_key' => $data['rule_key'] ?? null, 'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null, 'sort_order' => $data['sort_order'] ?? 0]);
        $component->forceFill(['salary_structure_id' => $structure->id])->save();

        return response()->json(['data' => $this->presentComponent($component, $currency)], 201);
    }

    /** Components are effective-dated: ending one sets `effective_to`; amounts are never edited in place. */
    public function endComponent(Request $request, SalaryStructure $structure, SalaryComponent $component): JsonResponse
    {
        $this->authorizeConfigure($request);
        abort_unless($component->salary_structure_id === $structure->id, 404);
        $data = $request->validate(['effective_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$component->effective_from?->toDateString()]]);
        $component->forceFill(['effective_to' => $data['effective_to']])->save();

        return response()->json(['data' => $this->presentComponent($component, $structure->legalEntity->currency_code)]);
    }

    private function calendarRules(ProviderRegistry $registry): array
    {
        return ['name' => ['required', 'string', 'max:120'], 'pay_date_offset_days' => ['sometimes', 'integer', 'between:0,31'],
            'approval_levels' => ['required', 'integer', 'between:1,'.config('payroll.max_approval_levels', 3)],
            'provider' => ['sometimes', Rule::in(array_keys($registry->all()))]];
    }

    private function presentCalendar(PayrollCalendar $c): array
    {
        return ['id' => $c->public_id, 'name' => $c->name, 'pay_frequency' => $c->pay_frequency, 'first_period_start' => $c->first_period_start?->toDateString(),
            'pay_date_offset_days' => $c->pay_date_offset_days, 'approval_levels' => $c->approval_levels, 'provider' => $c->provider, 'status' => $c->status,
            'legal_entity' => ['id' => $c->legalEntity->public_id, 'name' => $c->legalEntity->name, 'country_code' => $c->legalEntity->country_code, 'currency_code' => $c->legalEntity->currency_code],
            'periods_count' => $c->periods_count ?? null];
    }

    private function presentPeriod(PayrollPeriod $p, bool $withRuns = false): array
    {
        $data = ['id' => $p->public_id, 'start_date' => $p->start_date?->toDateString(), 'end_date' => $p->end_date?->toDateString(), 'pay_date' => $p->pay_date?->toDateString()];
        if ($withRuns) {
            $data['calendar'] = ['id' => $p->calendar->public_id, 'name' => $p->calendar->name, 'pay_frequency' => $p->calendar->pay_frequency,
                'legal_entity' => $p->calendar->legalEntity->name, 'currency' => $p->calendar->legalEntity->currency_code];
            $data['runs'] = $p->runs->map(fn ($r) => ['id' => $r->public_id, 'type' => $r->type, 'status' => $r->status])->values();
        }

        return $data;
    }

    private function presentStructure(SalaryStructure $s): array
    {
        $currency = $s->legalEntity->currency_code;

        return ['id' => $s->public_id, 'name' => $s->name, 'code' => $s->code, 'status' => $s->status,
            'legal_entity' => ['id' => $s->legalEntity->public_id, 'name' => $s->legalEntity->name, 'currency_code' => $currency],
            'components' => $s->components->map(fn ($c) => $this->presentComponent($c, $currency))->values()];
    }

    private function presentComponent(SalaryComponent $c, string $currency): array
    {
        return ['id' => $c->public_id, 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind, 'method' => $c->method,
            'source' => in_array($c->method, ['fixed', 'percent_of_base'], true) ? 'calculated' : $c->method,
            'amount' => $this->money($c->amount_minor, $currency), 'rate_percent' => $c->rate_basis_points === null ? null : number_format($c->rate_basis_points / 100, 2, '.', ''),
            'rule_key' => $c->rule_key, 'effective_from' => $c->effective_from?->toDateString(), 'effective_to' => $c->effective_to?->toDateString(), 'sort_order' => $c->sort_order];
    }
}

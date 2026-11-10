<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LegalEntityResource;
use App\Models\AuditLog;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LegalEntityController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', LegalEntity::class);
        $data = $request->validate(['country' => ['sometimes', 'string', 'size:2'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);

        return LegalEntityResource::collection(LegalEntity::query()
            ->when($data['country'] ?? null, fn ($q, $c) => $q->where('country_code', $c))
            ->orderBy('name')->paginate((int) ($data['per_page'] ?? 25)));
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', LegalEntity::class);
        $entity = LegalEntity::create($this->validated($request, (string) $request->input('country_code')));

        return $this->present($request, $entity)->response()->setStatusCode(201);
    }

    public function show(Request $request, LegalEntity $legalEntity): LegalEntityResource
    {
        Gate::authorize('view', $legalEntity);

        return $this->present($request, $legalEntity);
    }

    /** The country of a legal entity is fixed; operating in another country requires another entity. */
    public function update(Request $request, LegalEntity $legalEntity): LegalEntityResource
    {
        Gate::authorize('update', $legalEntity);
        $legalEntity->update($this->validated($request, $legalEntity->country_code, $legalEntity));

        return $this->present($request, $legalEntity);
    }

    private function validated(Request $request, string $country, ?LegalEntity $existing = null): array
    {
        $required = $existing ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:160'],
            'legal_name' => [$required, 'string', 'max:200', Rule::unique('legal_entities')->where('tenant_id', $request->user()->tenant_id)
                ->where('country_code', $country)->ignore($existing?->id)],
            'country_code' => $existing ? ['prohibited'] : ['required', 'string', Rule::exists('countries', 'code')->where('is_active', true)],
            'subdivision_code' => ['nullable', 'string', Rule::exists('subdivisions', 'code')->where('country_code', $country)],
            'currency_code' => [$required, 'string', Rule::exists('currencies', 'code')->where('is_active', true)],
            'default_language_code' => [$required, 'string', Rule::exists('country_languages', 'language_code')->where('country_code', $country)],
            'registration_number' => ['nullable', 'string', 'max:64'],
            'tax_identifier' => ['nullable', 'string', 'max:64'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'status' => $existing ? ['sometimes', Rule::in(['active', 'inactive'])] : ['prohibited'],
        ]);
    }

    private function present(Request $request, LegalEntity $entity): LegalEntityResource
    {
        $identifiers = $request->user()->can('viewIdentifiers', $entity);
        if ($identifiers) {
            AuditLog::record('legal_entity.identifiers_viewed', $entity);
        }

        return (new LegalEntityResource($entity))->withIdentifiers($identifiers);
    }
}

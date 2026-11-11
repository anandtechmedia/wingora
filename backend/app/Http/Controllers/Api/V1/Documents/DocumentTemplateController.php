<?php

namespace App\Http\Controllers\Api\V1\Documents;

use App\Documents\DocumentAccess;
use App\Documents\LetterPdf;
use App\Http\Controllers\Controller;
use App\Models\DocumentTemplate;
use App\Models\LegalEntity;
use App\Security\Permission as P;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentTemplateController extends Controller
{
    /** Requesters see active templates for their legal entity; configurers see every template. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(DocumentAccess::any($user), 403);
        $configure = $user->hasPermission(P::DocumentsConfigure);
        $entity = $user->employee?->legal_entity_id;
        $items = DocumentTemplate::query()->with('legalEntity')
            ->when(! $configure, fn ($q) => $q->where('active', true)
                ->where(fn ($w) => $w->whereNull('legal_entity_id')->when($entity, fn ($e) => $e->orWhere('legal_entity_id', $entity))))
            ->orderBy('sort_order')->orderBy('name')->get();

        return response()->json(['data' => $items->map(fn ($t) => $this->present($t, $configure))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::DocumentsConfigure), 403);
        $data = $this->validated($request, null);
        $template = new DocumentTemplate;
        $this->apply($template, $data);
        $template->forceFill(['created_by' => $request->user()->id])->save();

        return response()->json(['data' => $this->present($template->fresh('legalEntity'), true)], 201);
    }

    public function update(Request $request, DocumentTemplate $documentTemplate): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::DocumentsConfigure), 403);
        $data = $this->validated($request, $documentTemplate);
        $this->apply($documentTemplate, $data);
        $documentTemplate->save();

        return response()->json(['data' => $this->present($documentTemplate->fresh('legalEntity'), true)]);
    }

    /** Validates the merged result, so a partial update can never leave an inconsistent template. */
    private function validated(Request $request, ?DocumentTemplate $current): array
    {
        $sometimes = $current ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$sometimes, 'string', 'min:3', 'max:120', Rule::unique('document_templates', 'name')->where('tenant_id', $request->user()->tenant_id)->ignore($current?->id)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'delivery' => [$sometimes, Rule::in(DocumentTemplate::DELIVERIES)],
            'body' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'fields' => ['sometimes', 'array', 'max:10'],
            'fields.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,39}$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:100'],
            'fields.*.type' => ['required', Rule::in(DocumentTemplate::FIELD_TYPES)],
            'fields.*.required' => ['sometimes', 'boolean'],
            'requires_manager' => ['sometimes', 'boolean'],
            'includes_salary' => ['sometimes', 'boolean'],
            'legal_entity_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
        ]);
        if (isset($data['fields'])) {
            $data['fields'] = array_map(fn ($f) => ['key' => $f['key'], 'label' => $f['label'], 'type' => $f['type'], 'required' => (bool) ($f['required'] ?? false)], $data['fields']);
        }
        if (array_key_exists('legal_entity_id', $data)) {
            $entity = $data['legal_entity_id'] ? LegalEntity::query()->where('public_id', $data['legal_entity_id'])->first() : null;
            if ($data['legal_entity_id'] && $entity === null) {
                throw ValidationException::withMessages(['legal_entity_id' => __('Unknown legal entity.')]);
            }
            $data['legal_entity_id'] = $entity?->id;
        }

        $delivery = $data['delivery'] ?? $current?->delivery;
        $body = array_key_exists('body', $data) ? $data['body'] : $current?->body;
        $fields = $data['fields'] ?? $current?->fields ?? [];
        $salary = (bool) ($data['includes_salary'] ?? $current?->includes_salary ?? false);
        if ($delivery === 'generated') {
            if (blank($body)) {
                throw ValidationException::withMessages(['body' => __('A generated document needs a letter body.')]);
            }
            $unknown = LetterPdf::unknownTokens($body, array_column($fields, 'key'), $salary);
            if ($unknown !== []) {
                throw ValidationException::withMessages(['body' => __('Unknown placeholders: :list', ['list' => implode(', ', $unknown)])]);
            }
        }
        if ($salary && ($delivery !== 'generated' || ! in_array('salary', LetterPdf::tokens((string) $body), true))) {
            throw ValidationException::withMessages(['includes_salary' => __('Salary letters must be generated and use the {{salary}} placeholder.')]);
        }

        return $data;
    }

    private function apply(DocumentTemplate $t, array $data): void
    {
        $t->fill(array_intersect_key($data, array_flip($t->getFillable())));
        if (! $t->exists && ! isset($data['fields'])) {
            $t->fields = [];
        }
        if (array_key_exists('legal_entity_id', $data)) {
            $t->legal_entity_id = $data['legal_entity_id'];
        }
    }

    public static function present(DocumentTemplate $t, bool $full): array
    {
        $out = [
            'id' => $t->public_id, 'name' => $t->name, 'description' => $t->description, 'delivery' => $t->delivery,
            'fields' => $t->fields ?? [], 'requires_manager' => $t->requires_manager, 'includes_salary' => $t->includes_salary,
            'legal_entity' => $t->legalEntity ? ['id' => $t->legalEntity->public_id, 'name' => $t->legalEntity->name, 'country_code' => $t->legalEntity->country_code] : null,
            'active' => $t->active, 'sort_order' => $t->sort_order,
        ];
        if ($full) {
            $out['body'] = $t->body;
            $out['placeholders'] = LetterPdf::PLACEHOLDERS;
        }

        return $out;
    }
}

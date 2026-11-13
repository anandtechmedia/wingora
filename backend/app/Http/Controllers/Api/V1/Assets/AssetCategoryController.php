<?php

namespace App\Http\Controllers\Api\V1\Assets;

use App\Http\Controllers\Controller;
use App\Models\AssetCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssetCategoryController extends Controller
{
    use Concerns;

    public function index(Request $request): JsonResponse
    {
        $this->manager($request);
        $categories = AssetCategory::query()->withCount('assets')->orderByDesc('active')->orderBy('name')->limit(500)->get();

        return response()->json(['data' => $categories->map(fn ($c) => $this->present($c))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->manager($request);
        $data = $this->validated($request, true);
        $this->ensureUniqueName($data['name']);
        $category = (new AssetCategory)->forceFill([
            'name' => $data['name'], 'description' => $data['description'] ?? null, 'active' => $data['active'] ?? true, 'version' => 1,
        ]);
        $category->save();

        return response()->json(['data' => $this->present($category->loadCount('assets'))], 201);
    }

    public function update(Request $request, AssetCategory $category): JsonResponse
    {
        $this->manager($request);
        $data = $this->validated($request, false) + $request->validate(['version' => ['required', 'integer']]);
        $category = DB::transaction(function () use ($category, $data) {
            $c = AssetCategory::query()->lockForUpdate()->findOrFail($category->id);
            $this->checkVersion($c, $data['version']);
            if (isset($data['name']) && $data['name'] !== $c->name) {
                $this->ensureUniqueName($data['name'], $c->id);
            }
            $c->forceFill(collect($data)->only(['name', 'description', 'active'])->all() + ['version' => $c->version + 1])->save();

            return $c;
        });

        return response()->json(['data' => $this->present($category->loadCount('assets'))]);
    }

    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'name' => [$required, 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'active' => ['sometimes', 'boolean'],
        ]);
    }

    private function ensureUniqueName(string $name, ?int $except = null): void
    {
        $exists = AssetCategory::query()->where('name', $name)->when($except, fn ($q) => $q->where('id', '!=', $except))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => __('A category with this name already exists.')]);
        }
    }

    private function present(AssetCategory $c): array
    {
        return ['id' => $c->public_id, 'name' => $c->name, 'description' => $c->description, 'active' => $c->active, 'assets_count' => (int) ($c->assets_count ?? 0), 'version' => $c->version];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Helpdesk;

use App\Audit\AuditEvents;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HelpdeskCategory;
use App\Models\KbArticle;
use App\Models\User;
use App\Security\Permission as P;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** FAQs and knowledge-base articles. Everyone with helpdesk access reads published ones; helpdesk managers write. */
class KnowledgeBaseController extends Controller
{
    use ResolvesTimeEmployees;

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['kind' => ['sometimes', Rule::in(KbArticle::KINDS)], 'status' => ['sometimes', 'in:draft,published'],
            'category_id' => ['sometimes', 'string', 'max:26'], 'q' => ['sometimes', 'string', 'max:100'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $page = $this->readable($user)->with('category')
            ->when($data['kind'] ?? null, fn ($q, $k) => $q->where('kind', $k))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($data['category_id'] ?? null, fn ($q, $c) => $q->whereIn('helpdesk_category_id', HelpdeskCategory::query()->where('public_id', $c)->select('id')))
            ->when($data['q'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('summary', 'like', $like)->orWhere('body', 'like', $like));
            })
            ->orderBy('kind')->orderBy('sort_order')->orderBy('title')->paginate((int) ($data['per_page'] ?? 50));

        return response()->json(['data' => $page->getCollection()->map(fn ($a) => $this->present($a, $user->hasPermission(P::HelpdeskManage)))->values(), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, KbArticle $article): JsonResponse
    {
        abort_unless($this->readable($request->user())->whereKey($article->id)->exists(), 404);

        return response()->json(['data' => $this->present($article->load('category'), $request->user()->hasPermission(P::HelpdeskManage), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::HelpdeskManage), 403);
        $data = $request->validate($this->rules(true));
        $article = new KbArticle;
        $this->apply($article, $data, $user);
        $article->forceFill(['slug' => Str::limit(Str::slug($data['title']), 100, '').'-'.Str::lower(Str::random(6)), 'status' => 'draft', 'author_id' => $user->id])->save();

        return response()->json(['data' => $this->present($article->load('category'), true, true)], 201);
    }

    public function update(Request $request, KbArticle $article): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::HelpdeskManage), 403);
        $this->apply($article, $request->validate($this->rules(false)), $user);
        $article->save();

        return response()->json(['data' => $this->present($article->load('category'), true, true)]);
    }

    public function publish(Request $request, KbArticle $article): JsonResponse
    {
        return $this->setStatus($request, $article, 'published');
    }

    public function unpublish(Request $request, KbArticle $article): JsonResponse
    {
        return $this->setStatus($request, $article, 'draft');
    }

    public function destroy(Request $request, KbArticle $article): JsonResponse
    {
        abort_unless($request->user()->hasPermission(P::HelpdeskManage), 403);
        $article->delete();

        return response()->json(null, 204);
    }

    private function setStatus(Request $request, KbArticle $article, string $status): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::HelpdeskManage), 403);
        $article->forceFill(['status' => $status, 'published_at' => $status === 'published' ? now() : null, 'updated_by' => $user->id])->save();
        if ($status === 'published') {
            AuditLog::record(AuditEvents::HELPDESK_ARTICLE_PUBLISHED, $article, [], ['title' => $article->title, 'kind' => $article->kind]);
        }

        return response()->json(['data' => $this->present($article->load('category'), true, true)]);
    }

    private function readable(User $user): Builder
    {
        abort_unless($user->hasPermission(P::HelpdeskSubmit) || $user->hasPermission(P::HelpdeskAgent) || $user->hasPermission(P::HelpdeskManage), 403);

        return KbArticle::query()->when(! $user->hasPermission(P::HelpdeskManage), fn ($q) => $q->where('status', 'published'));
    }

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';

        return [
            'kind' => [$req, Rule::in(KbArticle::KINDS)],
            'title' => [$req, 'string', 'min:3', 'max:200'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'body' => [$req, 'string', 'min:3', 'max:100000'],
            'category_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'sort_order' => ['sometimes', 'integer', 'between:0,1000'],
        ];
    }

    private function apply(KbArticle $article, array $data, User $user): void
    {
        $article->fill(array_intersect_key($data, array_flip($article->getFillable())));
        if (array_key_exists('category_id', $data)) {
            $category = $data['category_id'] ? HelpdeskCategory::query()->where('public_id', $data['category_id'])->first() : null;
            abort_if($data['category_id'] && $category === null, 422, __('Unknown category.'));
            $article->helpdesk_category_id = $category?->id;
        }
        $article->updated_by = $user->id;
    }

    private function present(KbArticle $a, bool $manage, bool $detail = false): array
    {
        $out = ['id' => $a->public_id, 'kind' => $a->kind, 'title' => $a->title, 'summary' => $a->summary, 'status' => $a->status,
            'category' => $a->category ? ['id' => $a->category->public_id, 'name' => $a->category->name] : null,
            'published_at' => $a->published_at?->toIso8601String(), 'updated_at' => $a->updated_at?->toIso8601String(), 'sort_order' => $a->sort_order,
            'can' => ['edit' => $manage]];
        if ($detail || $a->kind === 'faq') {
            $out['body'] = $a->body;
        }

        return $out;
    }
}

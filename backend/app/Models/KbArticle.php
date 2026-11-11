<?php

namespace App\Models;

use App\Models\Concerns\HelpdeskAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KbArticle extends TenantModel
{
    use HelpdeskAudited;

    public const KINDS = ['faq', 'article'];

    protected $fillable = ['kind', 'title', 'summary', 'body', 'sort_order'];

    protected $hidden = ['id', 'tenant_id', 'helpdesk_category_id', 'author_id', 'updated_by'];

    protected array $auditRedacted = ['body'];

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'sort_order' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(HelpdeskCategory::class, 'helpdesk_category_id');
    }

    public function auditEvent(string $action): string
    {
        return 'helpdesk.article_'.$action;
    }
}

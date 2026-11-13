<?php

namespace App\Models;

use App\Models\Concerns\HelpdeskAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HelpdeskComment extends TenantModel
{
    use HelpdeskAudited;

    protected $fillable = ['body'];

    protected $hidden = ['id', 'tenant_id', 'helpdesk_ticket_id', 'author_id'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'internal' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HelpdeskTicket::class, 'helpdesk_ticket_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(HelpdeskAttachment::class);
    }
}

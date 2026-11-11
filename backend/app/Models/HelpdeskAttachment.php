<?php

namespace App\Models;

use App\Models\Concerns\HelpdeskAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HelpdeskAttachment extends TenantModel
{
    use HelpdeskAudited;

    protected $hidden = ['id', 'tenant_id', 'helpdesk_ticket_id', 'helpdesk_comment_id', 'stored_file_id', 'uploaded_by'];

    protected function casts(): array
    {
        return ['internal' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(HelpdeskTicket::class, 'helpdesk_ticket_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'stored_file_id');
    }
}

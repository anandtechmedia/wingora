<?php

namespace App\Models;

use App\Models\Concerns\HelpdeskAudited;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HelpdeskTicket extends TenantModel
{
    use HelpdeskAudited;

    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const STATUSES = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];

    public const OPEN_STATUSES = ['open', 'in_progress', 'waiting'];

    /** Transitions an agent may make. Closed is final. */
    public const STAFF_FLOW = [
        'open' => ['in_progress', 'waiting', 'resolved'],
        'in_progress' => ['waiting', 'resolved'],
        'waiting' => ['in_progress', 'resolved'],
        'resolved' => ['open', 'closed'],
        'closed' => [],
    ];

    /** Transitions the requester may make: withdraw (close) or reopen a resolved ticket. */
    public const REQUESTER_FLOW = [
        'open' => ['closed'],
        'in_progress' => ['closed'],
        'waiting' => ['closed'],
        'resolved' => ['open', 'closed'],
        'closed' => [],
    ];

    protected $fillable = ['subject', 'description', 'priority'];

    protected $hidden = ['id', 'tenant_id', 'helpdesk_category_id', 'requester_id', 'employee_id', 'assignee_id'];

    protected array $auditRedacted = ['subject'];

    protected function casts(): array
    {
        return ['description' => 'encrypted', 'number' => 'integer', 'version' => 'integer', 'due_at' => 'immutable_datetime',
            'first_response_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(HelpdeskCategory::class, 'helpdesk_category_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(HelpdeskComment::class)->orderBy('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(HelpdeskAttachment::class)->orderBy('id');
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true) && $this->due_at !== null && $this->due_at->isPast();
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Helpdesk;

use App\Audit\AuditEvents;
use App\Helpdesk\HelpdeskAccess;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HelpdeskAttachment;
use App\Models\HelpdeskCategory;
use App\Models\HelpdeskComment;
use App\Models\HelpdeskSetting;
use App\Models\HelpdeskTicket;
use App\Models\User;
use App\Recruitment\PrivateUpload;
use App\Security\Permission as P;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketController extends Controller
{
    use ResolvesTimeEmployees;

    private const WITH = ['category', 'requester', 'assignee'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::HelpdeskSubmit) || HelpdeskAccess::isStaff($user), 403);
        $data = $request->validate([
            'scope' => ['sometimes', 'in:mine,assigned,queue'],
            'status' => ['sometimes', Rule::in([...HelpdeskTicket::STATUSES, 'active'])],
            'priority' => ['sometimes', Rule::in(HelpdeskTicket::PRIORITIES)],
            'category_id' => ['sometimes', 'string', 'max:26'],
            'q' => ['sometimes', 'string', 'max:100'],
            'overdue' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $scope = $data['scope'] ?? (HelpdeskAccess::isStaff($user) ? 'queue' : 'mine');
        $query = HelpdeskAccess::tickets($user)->with(self::WITH);
        match ($scope) {
            'mine' => $query->where('requester_id', $user->id),
            'assigned' => $query->where('assignee_id', $user->id),
            'queue' => HelpdeskAccess::isStaff($user) ? $query->whereIn('helpdesk_category_id', HelpdeskAccess::workableCategories($user)->select('id')) : $query->where('requester_id', $user->id),
        };
        $status = $data['status'] ?? null;
        $query->when($status === 'active', fn ($q) => $q->whereIn('status', HelpdeskTicket::OPEN_STATUSES))
            ->when($status && $status !== 'active', fn ($q) => $q->where('status', $status))
            ->when($data['priority'] ?? null, fn ($q, $p) => $q->where('priority', $p))
            ->when($data['category_id'] ?? null, fn ($q, $c) => $q->whereIn('helpdesk_category_id', HelpdeskCategory::query()->where('public_id', $c)->select('id')))
            ->when($data['overdue'] ?? false, fn ($q) => $q->whereIn('status', HelpdeskTicket::OPEN_STATUSES)->where('due_at', '<', now()))
            ->when($data['q'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('subject', 'like', $like)->when(ctype_digit(ltrim($s, '#')), fn ($n) => $n->orWhere('number', (int) ltrim($s, '#'))));
            });
        $page = $query->orderByRaw("case when status in ('open','in_progress','waiting') then 0 else 1 end")
            ->orderByRaw('due_at is null')->orderBy('due_at')->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($t) => $this->present($t, $user))->values(), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, HelpdeskTicket $ticket): JsonResponse
    {
        $this->visible($request->user(), $ticket);

        return response()->json(['data' => $this->present($ticket, $request->user(), true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::HelpdeskSubmit), 403);
        $data = $request->validate([
            'category_id' => ['required', 'string', 'max:26'],
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['required', 'string', 'min:3', 'max:10000'],
            'priority' => ['sometimes', Rule::in(HelpdeskTicket::PRIORITIES)],
            'files' => ['sometimes', 'array', 'max:5'],
            'files.*' => PrivateUpload::rules(),
        ]);
        $category = HelpdeskCategory::query()->where('public_id', $data['category_id'])->where('active', true)->first();
        abort_if($category === null, 422, __('Choose an active category.'));

        $ticket = DB::transaction(function () use ($user, $data, $category, $request) {
            $settings = HelpdeskSetting::current();
            $settings = HelpdeskSetting::query()->whereKey($settings->id)->lockForUpdate()->first();
            $priority = $data['priority'] ?? 'normal';
            $ticket = new HelpdeskTicket;
            $ticket->fill(['subject' => $data['subject'], 'description' => $data['description'], 'priority' => $priority]);
            $ticket->forceFill([
                'tenant_id' => $user->tenant_id,
                'number' => $settings->next_number,
                'helpdesk_category_id' => $category->id,
                'requester_id' => $user->id,
                'employee_id' => $user->employee?->id,
                'status' => 'open',
                'due_at' => $this->dueAt($settings, $priority, now()),
            ])->save();
            $settings->forceFill(['next_number' => $settings->next_number + 1])->save();
            foreach ($request->file('files', []) as $i => $upload) {
                $this->attach($ticket, null, $upload, $user, false, "files.$i");
            }

            return $ticket;
        });
        AuditLog::record(AuditEvents::HELPDESK_TICKET_CREATED, $ticket, [], ['number' => $ticket->number, 'category' => $category->public_id, 'priority' => $ticket->priority]);

        return response()->json(['data' => $this->present($ticket->fresh(), $user, true)], 201);
    }

    /** Staff-only: change priority (recomputes the due-by time) or category. */
    public function update(Request $request, HelpdeskTicket $ticket): JsonResponse
    {
        $user = $request->user();
        $this->staff($user, $ticket);
        $data = $request->validate(['priority' => ['sometimes', Rule::in(HelpdeskTicket::PRIORITIES)], 'category_id' => ['sometimes', 'string', 'max:26'], 'version' => ['required', 'integer']]);

        return $this->locked($ticket, $data['version'], function (HelpdeskTicket $t) use ($data, $user) {
            abort_if($t->status === 'closed', 409, __('Closed tickets cannot be changed.'));
            if (isset($data['category_id'])) {
                $category = HelpdeskAccess::workableCategories($user)->where('public_id', $data['category_id'])->where('active', true)->first();
                abort_if($category === null, 422, __('Choose an active category.'));
                $t->helpdesk_category_id = $category->id;
                if ($t->assignee && ! HelpdeskAccess::canAssignTo($t->assignee, $t)) {
                    $t->assignee_id = null;
                }
            }
            if (isset($data['priority']) && $data['priority'] !== $t->priority) {
                $t->priority = $data['priority'];
                $t->due_at = $this->dueAt(HelpdeskSetting::current(), $t->priority, $t->created_at);
            }
        }, $user);
    }

    public function assign(Request $request, HelpdeskTicket $ticket): JsonResponse
    {
        $user = $request->user();
        $this->staff($user, $ticket);
        $data = $request->validate(['assignee_id' => ['present', 'nullable', 'string', 'max:26'], 'version' => ['required', 'integer']]);
        $assignee = $data['assignee_id'] ? User::query()->where('public_id', $data['assignee_id'])->first() : null;
        abort_if($data['assignee_id'] && ($assignee === null || ! HelpdeskAccess::canAssignTo($assignee, $ticket)), 422, __('That person cannot work tickets in this category.'));
        if (! $user->hasPermission(P::HelpdeskManage)) {
            // Agents may take a ticket or release their own, nothing else.
            abort_unless(($assignee?->id === $user->id) || ($assignee === null && $ticket->assignee_id === $user->id), 403);
        }

        return $this->locked($ticket, $data['version'], function (HelpdeskTicket $t) use ($assignee) {
            abort_if($t->status === 'closed', 409, __('Closed tickets cannot be changed.'));
            $before = $t->assignee?->public_id;
            $t->assignee_id = $assignee?->id;
            AuditLog::record(AuditEvents::HELPDESK_TICKET_ASSIGNED, $t, ['assignee' => $before], ['assignee' => $assignee?->public_id]);
        }, $user);
    }

    public function status(Request $request, HelpdeskTicket $ticket): JsonResponse
    {
        $user = $request->user();
        $this->visible($user, $ticket);
        $data = $request->validate(['status' => ['required', Rule::in(HelpdeskTicket::STATUSES)], 'version' => ['required', 'integer']]);

        return $this->locked($ticket, $data['version'], function (HelpdeskTicket $t) use ($data, $user) {
            abort_unless(in_array($data['status'], $this->next($t, $user), true), 409, __('That status change is not allowed.'));
            $before = $t->status;
            $t->status = $data['status'];
            if ($data['status'] === 'resolved') {
                $t->resolved_at = now();
            }
            if ($data['status'] === 'closed') {
                $t->closed_at = now();
            }
            if ($data['status'] === 'open') {
                $t->resolved_at = null;
            }
            AuditLog::record(AuditEvents::HELPDESK_STATUS_CHANGED, $t, ['status' => $before], ['status' => $t->status]);
        }, $user);
    }

    public function comment(Request $request, HelpdeskTicket $ticket): JsonResponse
    {
        $user = $request->user();
        $this->visible($user, $ticket);
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:10000'],
            'internal' => ['sometimes', 'boolean'],
            'files' => ['sometimes', 'array', 'max:5'],
            'files.*' => PrivateUpload::rules(),
        ]);
        $works = HelpdeskAccess::works($user, $ticket);
        $internal = (bool) ($data['internal'] ?? false);
        abort_if($internal && ! $works, 403, __('Only the helpdesk team can add internal notes.'));
        DB::transaction(function () use ($ticket, $user, $data, $internal, $works, $request) {
            // Lock first so a concurrent close cannot slip in between the check and the insert.
            $t = HelpdeskTicket::query()->whereKey($ticket->id)->lockForUpdate()->first();
            abort_if($t->status === 'closed', 409, __('Closed tickets cannot be changed.'));
            $comment = new HelpdeskComment;
            $comment->fill(['body' => $data['body']]);
            $comment->forceFill(['tenant_id' => $ticket->tenant_id, 'helpdesk_ticket_id' => $ticket->id, 'author_id' => $user->id, 'internal' => $internal])->save();
            foreach ($request->file('files', []) as $i => $upload) {
                $this->attach($ticket, $comment, $upload, $user, $internal, "files.$i");
            }
            if (! $internal && $works && $t->requester_id !== $user->id && $t->first_response_at === null) {
                $t->first_response_at = now();
            }
            if (! $internal && $t->requester_id === $user->id && $t->status === 'waiting') {
                $t->status = 'in_progress';
            }
            if ($t->isDirty()) {
                $t->version++;
                $t->save();
            }
            AuditLog::record(AuditEvents::HELPDESK_COMMENT_ADDED, $t, [], ['internal' => $internal, 'attachments' => count($request->file('files', []))]);
        });

        return response()->json(['data' => $this->present($ticket->fresh(), $user, true)], 201);
    }

    public function download(Request $request, HelpdeskAttachment $attachment): StreamedResponse
    {
        $user = $request->user();
        $ticket = $attachment->ticket;
        $this->visible($user, $ticket);
        abort_if($attachment->internal && ! HelpdeskAccess::works($user, $ticket), 404);
        $file = $attachment->file;
        abort_if($file === null || $file->tenant_id !== $ticket->tenant_id, 404);
        AuditLog::record(AuditEvents::HELPDESK_ATTACHMENT_VIEWED, $ticket, [], ['attachment' => $attachment->public_id]);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    /** People a ticket in a category can be assigned to (helpdesk managers and agents). */
    public function agents(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(HelpdeskAccess::isStaff($user) || $user->hasPermission(P::HelpdeskConfigure), 403);
        $users = User::query()->where('tenant_id', $user->tenant_id)->orderBy('name')->limit(500)->get()
            ->filter(fn (User $u) => HelpdeskAccess::isStaff($u));

        return response()->json(['data' => $users->map(fn (User $u) => ['id' => $u->public_id, 'name' => $u->name])->values()]);
    }

    private function visible(User $user, HelpdeskTicket $ticket): void
    {
        abort_unless(HelpdeskAccess::tickets($user)->whereKey($ticket->id)->exists(), 404);
    }

    private function staff(User $user, HelpdeskTicket $ticket): void
    {
        $this->visible($user, $ticket);
        abort_unless(HelpdeskAccess::works($user, $ticket), 403);
    }

    /** Applies a change under a row lock and an optimistic version check, so two agents cannot overwrite each other. */
    private function locked(HelpdeskTicket $ticket, int $version, callable $change, User $user): JsonResponse
    {
        DB::transaction(function () use ($ticket, $version, $change) {
            $t = HelpdeskTicket::query()->whereKey($ticket->id)->lockForUpdate()->first();
            abort_if($t->version !== $version, 409, __('This ticket was changed by someone else. Refresh and try again.'));
            $change($t);
            $t->version++;
            $t->save();
        });

        return response()->json(['data' => $this->present($ticket->fresh(), $user, true)]);
    }

    private function dueAt(HelpdeskSetting $settings, string $priority, \DateTimeInterface $from): ?\DateTimeInterface
    {
        $hours = $settings->due_hours[$priority] ?? null;

        return $hours ? CarbonImmutable::instance($from)->addHours((int) $hours) : null;
    }

    private function attach(HelpdeskTicket $ticket, ?HelpdeskComment $comment, $upload, User $user, bool $internal, string $field): void
    {
        $file = PrivateUpload::store($upload, 'helpdesk', $user->id, $field);
        $a = new HelpdeskAttachment;
        $a->forceFill(['tenant_id' => $ticket->tenant_id, 'helpdesk_ticket_id' => $ticket->id, 'helpdesk_comment_id' => $comment?->id,
            'stored_file_id' => $file->id, 'internal' => $internal, 'uploaded_by' => $user->id])->save();
    }

    /** @return list<string> */
    private function next(HelpdeskTicket $t, User $user): array
    {
        $flow = HelpdeskAccess::works($user, $t) ? HelpdeskTicket::STAFF_FLOW[$t->status] : [];
        if ($t->requester_id === $user->id) {
            $flow = array_values(array_unique([...$flow, ...HelpdeskTicket::REQUESTER_FLOW[$t->status]]));
        }

        return $flow;
    }

    private function present(HelpdeskTicket $t, User $user, bool $detail = false): array
    {
        $t->loadMissing(self::WITH);
        $works = HelpdeskAccess::works($user, $t);
        $out = [
            'id' => $t->public_id,
            'number' => $t->number,
            'subject' => $t->subject,
            'status' => $t->status,
            'priority' => $t->priority,
            'category' => ['id' => $t->category->public_id, 'name' => $t->category->name, 'restricted' => $t->category->restricted],
            'requester' => ['id' => $t->requester->public_id, 'name' => $t->requester->name],
            'assignee' => $t->assignee ? ['id' => $t->assignee->public_id, 'name' => $t->assignee->name] : null,
            'due_at' => $t->due_at?->toIso8601String(),
            'overdue' => $t->isOverdue(),
            'first_response_at' => $t->first_response_at?->toIso8601String(),
            'resolved_at' => $t->resolved_at?->toIso8601String(),
            'closed_at' => $t->closed_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'updated_at' => $t->updated_at?->toIso8601String(),
            'version' => $t->version,
            'next' => $this->next($t, $user),
            'can' => [
                'work' => $works,
                'assign' => $works && $user->hasPermission(P::HelpdeskManage),
                'take' => $works && $t->assignee_id !== $user->id,
                'internal_notes' => $works,
                'comment' => $t->status !== 'closed',
            ],
        ];
        if ($detail) {
            $comments = $t->comments()->with('author')->when(! $works, fn ($q) => $q->where('internal', false))->get();
            $attachments = $t->attachments()->with('file')->when(! $works, fn ($q) => $q->where('internal', false))->get();
            $out['description'] = $t->description;
            $out['comments'] = $comments->map(fn (HelpdeskComment $c) => [
                'id' => $c->public_id, 'body' => $c->body, 'internal' => $c->internal, 'created_at' => $c->created_at?->toIso8601String(),
                'author' => ['id' => $c->author->public_id, 'name' => $c->author->name, 'staff' => $c->author_id !== $t->requester_id],
                'attachments' => $attachments->where('helpdesk_comment_id', $c->id)->map(fn ($a) => $this->presentAttachment($a))->values(),
            ])->values();
            $out['attachments'] = $attachments->whereNull('helpdesk_comment_id')->map(fn ($a) => $this->presentAttachment($a))->values();
        }

        return $out;
    }

    private function presentAttachment(HelpdeskAttachment $a): array
    {
        return ['id' => $a->public_id, 'name' => $a->file?->original_name, 'size' => $a->file?->size, 'mime_type' => $a->file?->mime_type, 'internal' => $a->internal];
    }
}

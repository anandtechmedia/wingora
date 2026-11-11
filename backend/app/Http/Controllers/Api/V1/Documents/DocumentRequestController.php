<?php

namespace App\Http\Controllers\Api\V1\Documents;

use App\Audit\AuditEvents;
use App\Documents\DocumentAccess;
use App\Documents\LetterPdf;
use App\Http\Controllers\Api\V1\Concerns\ResolvesTimeEmployees;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DocumentRequest;
use App\Models\DocumentTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Recruitment\PrivateUpload;
use App\Security\Permission as P;
use App\Time\Approvers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentRequestController extends Controller
{
    use ResolvesTimeEmployees;

    private const WITH = ['template', 'employee', 'requester', 'managerDecider', 'decider', 'file'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(DocumentAccess::any($user), 403);
        $data = $request->validate([
            'scope' => ['sometimes', 'in:mine,approvals,all'],
            'status' => ['sometimes', Rule::in([...DocumentRequest::STATUSES, 'open'])],
            'template_id' => ['sometimes', 'string', 'max:26'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);
        $query = DocumentAccess::requests($user)->with(self::WITH);
        match ($data['scope'] ?? 'all') {
            'mine' => $query->where('requester_id', $user->id),
            'approvals' => $this->actionable($query, $user),
            'all' => null,
        };
        $status = $data['status'] ?? null;
        $query->when($status === 'open', fn ($q) => $q->whereIn('status', [...DocumentRequest::PENDING, 'approved']))
            ->when($status && $status !== 'open', fn ($q) => $q->where('status', $status))
            ->when($data['template_id'] ?? null, fn ($q, $t) => $q->whereIn('document_template_id', DocumentTemplate::query()->where('public_id', $t)->select('id')))
            ->when($data['q'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->whereHas('employee', fn ($e) => $e->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)->orWhere('employee_number', 'like', $like))
                    ->when(ctype_digit(ltrim($s, '#')), fn ($n) => $n->orWhere('number', (int) ltrim($s, '#'))));
            });
        $page = $query->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 25));

        return response()->json(['data' => $page->getCollection()->map(fn ($r) => $this->present($r, $user))->values(), 'meta' => $this->meta($page)]);
    }

    public function show(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $this->visible($request->user(), $documentRequest);

        return response()->json(['data' => $this->present($documentRequest, $request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasPermission(P::DocumentsRequest), 403);
        $employee = $this->self($request);
        $data = $request->validate(['template_id' => ['required', 'string', 'max:26'], 'answers' => ['sometimes', 'array']]);
        $template = DocumentTemplate::query()->where('public_id', $data['template_id'])->where('active', true)
            ->where(fn ($q) => $q->whereNull('legal_entity_id')->orWhere('legal_entity_id', $employee->legal_entity_id))->first();
        abort_if($template === null, 422, __('Choose an available document type.'));
        $answers = $this->answers($template, $data['answers'] ?? []);
        $manager = $template->requires_manager && $this->hasManager($employee);

        $r = DB::transaction(function () use ($user, $employee, $template, $answers, $manager) {
            // Serialises numbering per tenant.
            Tenant::query()->whereKey($user->tenant_id)->lockForUpdate()->first();
            $r = new DocumentRequest;
            $r->forceFill([
                'tenant_id' => $user->tenant_id,
                'number' => (int) DocumentRequest::query()->max('number') + 1,
                'document_template_id' => $template->id,
                'employee_id' => $employee->id,
                'requester_id' => $user->id,
                'answers' => $answers,
                'status' => $manager ? 'pending_manager' : 'pending_hr',
                'manager_step' => $manager,
                'includes_salary' => $template->includes_salary,
            ])->save();

            return $r;
        });
        AuditLog::record(AuditEvents::DOCUMENT_REQUEST_SUBMITTED, $r, [], ['number' => $r->number, 'template' => $template->public_id, 'status' => $r->status]);

        return response()->json(['data' => $this->present($r->fresh(), $user)], 201);
    }

    public function decide(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $user = $request->user();
        $this->visible($user, $documentRequest);
        $data = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'min:3', 'max:1000'],
            'version' => ['required', 'integer'],
        ]);

        return $this->locked($documentRequest, $data['version'], function (DocumentRequest $r) use ($user, $data) {
            abort_unless(DocumentAccess::canDecide($user, $r), in_array($r->status, DocumentRequest::PENDING, true) ? 403 : 409);
            abort_if($r->status === 'approved' && $data['decision'] === 'approve', 409, __('This request is already approved.'));
            $step = $r->status;
            $manager = $step === 'pending_manager';
            $r->forceFill($manager ? ['manager_decided_by' => $user->id, 'manager_decided_at' => now()] : ['decided_by' => $user->id, 'decided_at' => now()]);
            if ($data['decision'] === 'reject') {
                $r->status = 'rejected';
                $r->rejection_reason = $data['reason'];
            } else {
                $r->status = $manager ? 'pending_hr' : 'approved';
            }
            AuditLog::record(AuditEvents::DOCUMENT_REQUEST_DECIDED, $r, ['status' => $step], ['status' => $r->status, 'step' => $manager ? 'manager' : 'final', 'decision' => $data['decision']]);
        }, $user);
    }

    public function cancel(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $user = $request->user();
        $this->visible($user, $documentRequest);
        abort_unless($documentRequest->requester_id === $user->id, 403);
        $data = $request->validate(['version' => ['required', 'integer']]);

        return $this->locked($documentRequest, $data['version'], function (DocumentRequest $r) {
            abort_unless(in_array($r->status, DocumentRequest::PENDING, true), 409, __('Only pending requests can be cancelled.'));
            $before = $r->status;
            $r->forceFill(['status' => 'cancelled', 'cancelled_at' => now()]);
            AuditLog::record(AuditEvents::DOCUMENT_REQUEST_CANCELLED, $r, ['status' => $before], ['status' => 'cancelled']);
        }, $user);
    }

    /** Generated templates render the letter (salary letters need the HR-typed figure); upload templates take the finished file. */
    public function deliver(Request $request, DocumentRequest $documentRequest): JsonResponse
    {
        $user = $request->user();
        $this->visible($user, $documentRequest);
        $template = $documentRequest->template;
        // Letters render the template's current text, so a salary flag changed since submission would print a blank or stray figure.
        abort_if($documentRequest->includes_salary !== $template->includes_salary, 409, __('This document type changed after the request was made. Reject it so the employee can request again.'));
        $data = $request->validate([
            'version' => ['required', 'integer'],
            'salary' => [$documentRequest->includes_salary ? 'required' : 'prohibited', 'nullable', 'string', 'min:1', 'max:120'],
            'file' => $template->delivery === 'upload' ? PrivateUpload::rules(['pdf', 'docx']) : ['prohibited'],
        ]);

        return $this->locked($documentRequest, (int) $data['version'], function (DocumentRequest $r) use ($user, $data, $template, $request) {
            abort_unless(DocumentAccess::canDeliver($user, $r), $r->status === 'approved' ? 403 : 409);
            $name = Str::slug($template->name).'-DR-'.$r->number;
            $file = $template->delivery === 'upload'
                ? PrivateUpload::store($request->file('file'), 'documents', $user->id)
                : PrivateUpload::storeBytes((new LetterPdf)->render($r, $data['salary'] ?? null), "$name.pdf", 'application/pdf', 'documents', $user->id, $r->employee_id);
            if ($template->delivery === 'upload') {
                $file->forceFill(['employee_id' => $r->employee_id])->save();
            }
            $r->forceFill(['status' => 'delivered', 'stored_file_id' => $file->id, 'delivered_by' => $user->id, 'delivered_at' => now()]);
            // The salary figure exists only inside the letter; the audit trail records that one was included, never its value.
            AuditLog::record(AuditEvents::DOCUMENT_DELIVERED, $r, ['status' => 'approved'], ['status' => 'delivered', 'delivery' => $template->delivery, 'salary_included' => $r->includes_salary]);
        }, $user);
    }

    public function download(Request $request, DocumentRequest $documentRequest): StreamedResponse
    {
        $user = $request->user();
        $this->visible($user, $documentRequest);
        abort_unless(DocumentAccess::canDownload($user, $documentRequest), 404);
        $file = $documentRequest->file;
        abort_if($file === null || $file->tenant_id !== $documentRequest->tenant_id, 404);
        AuditLog::record(AuditEvents::DOCUMENT_DOWNLOADED, $documentRequest, [], ['number' => $documentRequest->number]);

        return Storage::disk($file->disk)->download($file->path, $file->original_name, ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function actionable($query, User $user): void
    {
        $salary = array_keys(array_filter([0 => $user->hasPermission(P::DocumentsManage), 1 => $user->hasPermission(P::DocumentsSalary)]));
        $team = $user->hasPermission(P::DocumentsManage)
            ? null
            : Approvers::visibleEmployees($user, P::DocumentsRequest, P::DocumentsApprove, P::DocumentsManage)->select('employees.id');
        $query->where('requester_id', '!=', $user->id)
            ->when($user->employee, fn ($q, $me) => $q->where('employee_id', '!=', $me->id))
            ->where(function ($q) use ($salary, $team, $user) {
                $q->whereIn('status', ['pending_hr', 'approved'])->whereIn('includes_salary', $salary === [] ? [-1] : $salary);
                if ($user->hasPermission(P::DocumentsManage) || $user->hasPermission(P::DocumentsApprove)) {
                    $q->orWhere(fn ($m) => $m->where('status', 'pending_manager')->when($team, fn ($t) => $t->whereIn('employee_id', $team)));
                }
            });
    }

    private function hasManager($employee): bool
    {
        $manager = $employee->manager;

        return $manager?->user !== null && $manager->user->hasPermission(P::DocumentsApprove);
    }

    /** @return array<string, string> */
    private function answers(DocumentTemplate $template, array $answers): array
    {
        $keys = array_column($template->fields ?? [], 'key');
        $extra = array_diff(array_keys($answers), $keys);
        if ($extra !== []) {
            throw ValidationException::withMessages(['answers' => __('Unknown answers: :list', ['list' => implode(', ', $extra)])]);
        }
        $rules = [];
        foreach ($template->fields ?? [] as $f) {
            $rules[$f['key']] = [($f['required'] ?? false) ? 'required' : 'nullable', 'string', ...match ($f['type']) {
                'textarea' => ['max:2000'], 'date' => ['date_format:Y-m-d'], default => ['max:200'],
            }];
        }
        $validator = validator($answers, $rules, [], collect($template->fields ?? [])->mapWithKeys(fn ($f) => [$f['key'] => $f['label']])->all());
        if ($validator->fails()) {
            throw ValidationException::withMessages(collect($validator->errors()->messages())->mapWithKeys(fn ($m, $k) => ["answers.$k" => $m])->all());
        }

        return collect($validator->validated())->filter(fn ($v) => $v !== null && $v !== '')->map(fn ($v) => (string) $v)->all();
    }

    private function visible(User $user, DocumentRequest $r): void
    {
        abort_unless(DocumentAccess::requests($user)->whereKey($r->id)->exists(), 404);
    }

    private function locked(DocumentRequest $r, int $version, callable $change, User $user): JsonResponse
    {
        DB::transaction(function () use ($r, $version, $change) {
            $locked = DocumentRequest::query()->whereKey($r->id)->lockForUpdate()->first();
            abort_if($locked->version !== $version, 409, __('This request was changed by someone else. Refresh and try again.'));
            $change($locked);
            $locked->version++;
            $locked->save();
        });

        return response()->json(['data' => $this->present($r->fresh(), $user)]);
    }

    private function present(DocumentRequest $r, User $user): array
    {
        $r->loadMissing(self::WITH);
        $t = $r->template;

        return [
            'id' => $r->public_id,
            'number' => $r->number,
            'template' => ['id' => $t->public_id, 'name' => $t->name, 'delivery' => $t->delivery, 'includes_salary' => $r->includes_salary, 'fields' => $t->fields ?? []],
            'employee' => $this->person($r->employee),
            'requester' => ['id' => $r->requester->public_id, 'name' => $r->requester->name],
            'status' => $r->status,
            'answers' => (object) ($r->answers ?? []),
            'manager_step' => $r->manager_step,
            'manager_decision' => $r->manager_decided_at ? ['by' => $r->managerDecider?->name, 'at' => $r->manager_decided_at->toIso8601String()] : null,
            'decision' => $r->decided_at ? ['by' => $r->decider?->name, 'at' => $r->decided_at->toIso8601String()] : null,
            'rejection_reason' => $r->rejection_reason,
            'delivered_at' => $r->delivered_at?->toIso8601String(),
            'document' => DocumentAccess::canDownload($user, $r) && $r->file ? ['name' => $r->file->original_name, 'size' => $r->file->size, 'mime_type' => $r->file->mime_type] : null,
            'created_at' => $r->created_at?->toIso8601String(),
            'version' => $r->version,
            'can' => [
                'decide' => DocumentAccess::canDecide($user, $r),
                'deliver' => DocumentAccess::canDeliver($user, $r),
                'cancel' => $r->requester_id === $user->id && in_array($r->status, DocumentRequest::PENDING, true),
                'download' => DocumentAccess::canDownload($user, $r),
            ],
        ];
    }
}

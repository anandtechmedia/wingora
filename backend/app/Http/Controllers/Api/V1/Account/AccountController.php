<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Audit\AuditEvents;
use App\Billing\Subscriptions;
use App\Http\Controllers\Controller;
use App\Jobs\BuildTenantExport;
use App\Models\AuditLog;
use App\Models\DataExport;
use App\Models\DeletionRequest;
use App\Models\Subscription;
use App\Models\SupportGrant;
use App\Security\Permission;
use App\Security\TwoFactor;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Organization Owner controls: Wingora support access, full data export and account deletion. */
class AccountController extends Controller
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function supportGrants(Request $request): JsonResponse
    {
        $this->owner($request);

        return response()->json(['data' => SupportGrant::query()->with('grantor')->latest('id')->limit(20)->get()->map(fn ($g) => $this->presentGrant($g))]);
    }

    public function grantSupport(Request $request): JsonResponse
    {
        $this->owner($request);
        $data = $request->validate([
            'hours' => ['required', 'integer', 'between:1,72'],
            'reason' => ['required', 'string', 'max:500'],
            'include_payroll' => ['sometimes', 'boolean'],
        ]);
        abort_if(SupportGrant::query()->active()->exists(), 409, __('Support access is already granted. Revoke it before granting it again.'));

        $grant = new SupportGrant;
        $grant->forceFill(['granted_by' => $request->user()->id, 'reason' => $data['reason'], 'include_payroll' => (bool) ($data['include_payroll'] ?? false),
            'expires_at' => now()->addHours((int) $data['hours'])])->save();
        AuditLog::record(AuditEvents::SUPPORT_GRANTED, $grant, new: ['expires_at' => $grant->expires_at->toIso8601String(), 'include_payroll' => $grant->include_payroll]);

        return response()->json(['data' => $this->presentGrant($grant->load('grantor'))], 201);
    }

    public function revokeSupport(Request $request, SupportGrant $grant): JsonResponse
    {
        $this->owner($request);
        abort_unless($grant->isActive(), 409, __('This support access has already ended.'));
        $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $request->user()->id])->save();
        AuditLog::record(AuditEvents::SUPPORT_REVOKED, $grant);

        return response()->json(['data' => $this->presentGrant($grant->load('grantor'))]);
    }

    public function exports(Request $request): JsonResponse
    {
        $this->owner($request);

        return response()->json(['data' => DataExport::query()->latest('id')->limit(10)->get()->map(fn ($e) => $this->presentExport($e))]);
    }

    public function requestExport(Request $request): JsonResponse
    {
        $this->owner($request);
        $this->confirmMfa($request);
        abort_if(DataExport::query()->whereIn('status', ['queued', 'running'])->exists(), 409, __('An export is already being prepared.'));

        $export = new DataExport;
        $export->forceFill(['requested_by' => $request->user()->id, 'status' => 'queued'])->save();
        AuditLog::record(AuditEvents::EXPORT_REQUESTED, $export);
        BuildTenantExport::dispatch($export->id);

        return response()->json(['data' => $this->presentExport($export->fresh())], 202);
    }

    public function downloadExport(Request $request, DataExport $export): StreamedResponse
    {
        $this->owner($request);
        abort_unless($export->status === 'ready' && $export->path && $export->expires_at?->isFuture(), 410, __('This export is no longer available.'));
        AuditLog::record(AuditEvents::EXPORT_DOWNLOADED, $export);

        return Storage::disk($export->disk)->download($export->path, 'wingora-export-'.$export->completed_at->format('Y-m-d').'.zip', [
            'Content-Type' => 'application/zip', 'Cache-Control' => 'no-store',
        ]);
    }

    public function deletion(Request $request): JsonResponse
    {
        $this->owner($request);

        return response()->json(['data' => [
            'request' => ($r = $this->scheduled()) ? $this->presentDeletion($r) : null,
            'legal_hold' => (bool) Tenancy::current()->audit_legal_hold,
            'waiting_days' => DeletionRequest::WAITING_DAYS,
        ]]);
    }

    public function requestDeletion(Request $request, Subscriptions $subscriptions): JsonResponse
    {
        $this->owner($request);
        $tenant = Tenancy::current();
        $request->validate(['confirm_name' => ['required', 'string', 'max:160']]);
        if (Str::lower(trim($request->string('confirm_name'))) !== Str::lower(trim($tenant->name))) {
            throw ValidationException::withMessages(['confirm_name' => __('Type the organization name exactly as shown to confirm.')]);
        }
        $this->confirmMfa($request);
        abort_if($tenant->audit_legal_hold, 409, __('This organization is on legal hold, so it cannot be deleted. Contact Wingora support.'));
        abort_if($this->scheduled() !== null, 409, __('Deletion is already scheduled.'));

        $deletion = new DeletionRequest;
        $deletion->forceFill(['requested_by' => $request->user()->id, 'status' => 'scheduled', 'scheduled_for' => now()->addDays(DeletionRequest::WAITING_DAYS)])->save();
        AuditLog::record(AuditEvents::DELETION_REQUESTED, $deletion, new: ['scheduled_for' => $deletion->scheduled_for->toIso8601String()]);

        // Stop future charges; the paid period still runs until the deletion date or its end.
        $subscription = Subscription::forTenant($tenant);
        if ($subscription && $subscription->status !== 'canceled' && ! $subscription->cancel_at_period_end && $subscription->provider !== 'manual') {
            rescue(fn () => $subscriptions->cancel($subscription));
        }

        return response()->json(['data' => $this->presentDeletion($deletion)], 201);
    }

    public function cancelDeletion(Request $request): JsonResponse
    {
        $this->owner($request);
        // Locked so it serializes with the purge job's claim: whichever commits first wins.
        $deletion = DB::transaction(function () use ($request) {
            $deletion = DeletionRequest::query()->whereIn('status', ['scheduled', 'purging'])->latest('id')->lockForUpdate()->first() ?? abort(409, __('No deletion is scheduled.'));
            abort_if($deletion->status === 'purging', 409, __('Deletion has already started and can no longer be cancelled.'));
            $deletion->forceFill(['status' => 'canceled', 'canceled_at' => now(), 'canceled_by' => $request->user()->id])->save();
            AuditLog::record(AuditEvents::DELETION_CANCELED, $deletion);

            return $deletion;
        });

        return response()->json(['data' => $this->presentDeletion($deletion)]);
    }

    private function owner(Request $request): void
    {
        abort_unless($request->user()->hasPermission(Permission::AccountManage), 403);
    }

    private function confirmMfa(Request $request): void
    {
        $request->validate(['code' => ['nullable', 'string', 'max:20'], 'recovery_code' => ['nullable', 'string', 'max:40']]);
        $user = $request->user();
        abort_unless($user->hasMfaEnabled(), 403, __('Set up two-step verification before doing this.'));
        if (! $this->twoFactor->verify($user, $request->input('code'), $request->input('recovery_code'))) {
            throw ValidationException::withMessages(['code' => __('The verification code is not valid.')]);
        }
    }

    private function scheduled(): ?DeletionRequest
    {
        return DeletionRequest::query()->whereIn('status', ['scheduled', 'purging'])->latest('id')->first();
    }

    private function presentGrant(SupportGrant $g): array
    {
        return ['id' => $g->public_id, 'reason' => $g->reason, 'include_payroll' => $g->include_payroll, 'active' => $g->isActive(),
            'granted_by' => $g->grantor?->name, 'created_at' => $g->created_at?->toIso8601String(), 'expires_at' => $g->expires_at->toIso8601String(),
            'revoked_at' => $g->revoked_at?->toIso8601String(), 'last_used_at' => $g->last_used_at?->toIso8601String()];
    }

    private function presentExport(DataExport $e): array
    {
        return ['id' => $e->public_id, 'status' => $e->status === 'ready' && $e->expires_at?->isPast() ? 'expired' : $e->status, 'size' => $e->size,
            'error' => $e->error, 'created_at' => $e->created_at?->toIso8601String(), 'completed_at' => $e->completed_at?->toIso8601String(),
            'expires_at' => $e->expires_at?->toIso8601String()];
    }

    private function presentDeletion(DeletionRequest $r): array
    {
        return ['id' => $r->public_id, 'status' => $r->status, 'scheduled_for' => $r->scheduled_for->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(), 'canceled_at' => $r->canceled_at?->toIso8601String()];
    }
}

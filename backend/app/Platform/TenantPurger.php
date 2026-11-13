<?php

namespace App\Platform;

use App\Audit\AuditEvents;
use App\Billing\Stripe\StripeClient;
use App\Models\AuditLog;
use App\Models\DataExport;
use App\Models\DeletionRequest;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Permanently removes an organization's data and files once its deletion request is due. Kept: the anonymized tenant
 * row, the subscription and invoices (App Wings' financial records), processed webhook ids, the deletion request, and
 * a platform audit entry. Organizations on legal hold are never purged. Once a purge starts (`purging`) it cannot be
 * cancelled, and a failed step is retried on the next run.
 */
final class TenantPurger
{
    public function __construct(private readonly StripeClient $stripe) {}

    private const KEEP = ['tenants', 'subscriptions', 'invoices', 'billing_events', 'deletion_requests', 'audit_chains'];

    /** @return int number of organizations purged */
    public function purgeDue(): int
    {
        $purged = 0;
        DeletionRequest::query()->withoutGlobalScopes()->whereIn('status', ['scheduled', 'purging'])->where('scheduled_for', '<=', now())->orderBy('id')
            ->each(function (DeletionRequest $request) use (&$purged) {
                $tenant = $this->claim($request);
                if ($tenant && $this->purge($tenant, $request)) {
                    $purged++;
                }
            });

        return $purged;
    }

    /**
     * Locks and rechecks the request and tenant, then marks the request `purging` so it can no longer be cancelled.
     * A cancellation or legal hold committed before this point always wins.
     */
    private function claim(DeletionRequest $request): ?Tenant
    {
        return DB::transaction(function () use ($request) {
            $locked = DeletionRequest::query()->withoutGlobalScopes()->whereKey($request->id)->lockForUpdate()->first();
            $tenant = Tenant::query()->whereKey($request->tenant_id)->lockForUpdate()->first();
            if (! $locked || ! in_array($locked->status, ['scheduled', 'purging'], true) || $locked->scheduled_for->isFuture() || ! $tenant || $tenant->audit_legal_hold) {
                return null;
            }
            $locked->status === 'scheduled' && $locked->forceFill(['status' => 'purging'])->saveQuietly();

            return $tenant;
        });
    }

    /** @return bool false when the purge must be retried on the next run */
    private function purge(Tenant $tenant, DeletionRequest $request): bool
    {
        if (! $this->stopBilling($tenant)) {
            return false;
        }
        $files = StoredFile::query()->withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)->get(['disk', 'path'])
            ->concat(DataExport::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('path')->get(['disk', 'path']));
        // Files go first, while their records still exist, so a failed delete is retried on the next run.
        $failed = $files->reject(fn ($file) => rescue(fn () => Storage::disk($file->disk)->delete($file->path), false, false) === true);
        if ($failed->isNotEmpty()) {
            Log::warning('tenant.purge_files_failed', ['tenant' => $tenant->public_id, 'files' => $failed->count()]);

            return false;
        }
        $userIds = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('id');
        $emails = User::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->pluck('email');
        $counts = [];

        Schema::withoutForeignKeyConstraints(fn () => DB::transaction(function () use ($tenant, $userIds, $emails, &$counts) {
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
            DB::table('role_user')->whereIn('user_id', $userIds)->delete();
            DB::table(config('session.table', 'sessions'))->whereIn('user_id', $userIds)->delete();
            DB::table('password_reset_tokens')->whereIn('email', $emails)->delete();
            foreach (Schema::getTableListing(schemaQualified: false) as $table) {
                if (! in_array($table, self::KEEP, true) && Schema::hasColumn($table, 'tenant_id')) {
                    $deleted = DB::table($table)->where('tenant_id', $tenant->id)->delete();
                    $deleted && $counts[$table] = $deleted;
                }
            }
            // The organization's audit entries are gone; anchor its chains at their head so verification still passes.
            DB::table('audit_chains')->where('tenant_id', $tenant->id)->update(['anchor_sequence' => DB::raw('last_sequence'), 'anchor_hash' => DB::raw('last_hash'), 'updated_at' => now()]);
            $tenant->forceFill(['name' => 'Deleted organization', 'slug' => 'deleted-'.Str::lower($tenant->public_id), 'status' => 'deleted'])->saveQuietly();
        }));
        DeletionRequest::query()->withoutGlobalScopes()->whereKey($request->id)->update(['status' => 'completed', 'completed_at' => now(), 'requested_by' => null, 'canceled_by' => null]);
        AuditLog::record(AuditEvents::DELETION_COMPLETED, metadata: ['tenant' => $tenant->public_id, 'rows' => array_sum($counts), 'files' => $files->count()], category: null, tenantId: null);

        return true;
    }

    /** Ends a live Stripe subscription now, so a deleted organization is never charged again. */
    private function stopBilling(Tenant $tenant): bool
    {
        $subscription = Subscription::forTenant($tenant);
        if (! $subscription || $subscription->provider !== 'stripe' || $subscription->status === 'canceled' || ! $subscription->stripe_subscription_id) {
            return true;
        }
        $path = 'subscriptions/'.$subscription->stripe_subscription_id;
        $state = rescue(fn () => $this->stripe->delete($path), null, false) ?? rescue(fn () => $this->stripe->get($path), null, false);
        if (($state['status'] ?? null) !== 'canceled') {
            Log::warning('tenant.purge_billing_not_stopped', ['tenant' => $tenant->public_id]);

            return false;
        }
        $subscription->forceFill(['status' => 'canceled', 'canceled_at' => now(), 'cancel_at_period_end' => false, 'version' => $subscription->version + 1])->saveQuietly();

        return true;
    }
}

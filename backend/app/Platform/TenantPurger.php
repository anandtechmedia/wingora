<?php

namespace App\Platform;

use App\Audit\AuditEvents;
use App\Models\AuditLog;
use App\Models\DataExport;
use App\Models\DeletionRequest;
use App\Models\StoredFile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Permanently removes an organization's data and files once its deletion request is due. Kept: the anonymized tenant
 * row, the subscription and invoices (App Wings' financial records), processed webhook ids, the deletion request, and
 * a platform audit entry. Organizations on legal hold are never purged.
 */
final class TenantPurger
{
    private const KEEP = ['tenants', 'subscriptions', 'invoices', 'billing_events', 'deletion_requests', 'audit_chains'];

    /** @return int number of organizations purged */
    public function purgeDue(): int
    {
        $purged = 0;
        DeletionRequest::query()->withoutGlobalScopes()->where('status', 'scheduled')->where('scheduled_for', '<=', now())->orderBy('id')
            ->each(function (DeletionRequest $request) use (&$purged) {
                $tenant = Tenant::query()->find($request->tenant_id);
                if (! $tenant || $tenant->audit_legal_hold) {
                    return;
                }
                $this->purge($tenant, $request);
                $purged++;
            });

        return $purged;
    }

    public function purge(Tenant $tenant, DeletionRequest $request): void
    {
        $files = StoredFile::query()->withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)->get(['disk', 'path'])
            ->concat(DataExport::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereNotNull('path')->get(['disk', 'path']));
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
            $tenant->forceFill(['name' => 'Deleted organization', 'slug' => 'deleted-'.Str::lower($tenant->public_id), 'status' => 'deleted'])->saveQuietly();
        }));
        DeletionRequest::query()->withoutGlobalScopes()->whereKey($request->id)->update(['status' => 'completed', 'completed_at' => now(), 'requested_by' => null, 'canceled_by' => null]);

        foreach ($files as $file) {
            rescue(fn () => Storage::disk($file->disk)->delete($file->path), report: false);
        }
        AuditLog::record(AuditEvents::DELETION_COMPLETED, metadata: ['tenant' => $tenant->public_id, 'rows' => array_sum($counts), 'files' => $files->count()], category: null, tenantId: null);
    }
}

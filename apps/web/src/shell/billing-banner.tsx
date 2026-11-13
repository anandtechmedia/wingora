import { Link } from 'react-router';
import { canAccessModule, findModule } from '@wingora/permissions';
import type { MessageKey } from '@wingora/localization';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';

/** Shown on every page while the organization is read-only or a payment has failed. The server enforces both states. */
export function BillingBanner() {
  const { me, permissions } = useSession();
  const { t } = useI18n();
  const access = me?.billing?.access;
  if (!access || (access.mode === 'full' && access.reason !== 'payment_failed')) return null;
  const billing = findModule('billing');
  return (
    <div role="status" className="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-md border border-warning-line bg-warning-subtle px-4 py-3 text-sm text-warning-fg">
      <span>{t(`shell.billing.${access.reason ?? 'canceled'}` as MessageKey)}</span>
      {billing && canAccessModule(permissions, billing) && <Link to="/billing" className="font-medium underline">{t('shell.billing.open')}</Link>}
    </div>
  );
}

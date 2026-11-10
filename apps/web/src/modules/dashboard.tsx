import { Link } from 'react-router';
import { navigationFor } from '@wingora/permissions';
import { Badge, Card, ChevronRightIcon, EmptyState, InboxIcon } from '@/components/ui';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { moduleIcons, moduleLabel } from '@/shell/module-meta';
import { PageHeader } from '@/shell/page-header';
import { useWorkspace } from '@/shell/workspace';

export default function Dashboard() {
  const { t } = useI18n();
  const { me, permissions } = useSession();
  const { organization, entity } = useWorkspace();
  const modules = navigationFor(permissions).flatMap((g) => g.modules).filter((m) => m.id !== 'dashboard');

  return (
    <>
      <PageHeader title={t('dashboard.greeting', { name: me?.user.name.split(' ')[0] ?? '' })}
        description={t('dashboard.context', { organization: organization.name, entity: entity?.name ?? t('shell.allEntities') })}
        actions={<Badge tone="warning" dot>{t('shell.prototype')}</Badge>} />
      <div className="grid gap-6 xl:grid-cols-3">
        <Card headingLevel={2} title={t('dashboard.modules')} description={t('dashboard.modulesDescription')} className="xl:col-span-2">
          <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {modules.map((m) => {
              const Icon = moduleIcons[m.id];
              return (
                <li key={m.id}>
                  <Link to={`/${m.path}`} className="group flex items-center gap-3 rounded-lg border border-line bg-surface p-3 text-sm font-medium text-fg hover:border-line-strong hover:bg-surface-muted">
                    <span className="inline-flex size-9 items-center justify-center rounded-md bg-accent-subtle text-accent-fg"><Icon size={18} /></span>
                    <span className="flex-1">{t(moduleLabel(m.id))}</span>
                    <ChevronRightIcon className="text-fg-muted rtl:rotate-180" />
                  </Link>
                </li>
              );
            })}
          </ul>
        </Card>
        <Card headingLevel={2} title={t('dashboard.activity')}>
          <EmptyState icon={<InboxIcon size={22} />} title={t('dashboard.activityEmpty')} description={t('dashboard.activityEmptyDescription')} />
        </Card>
      </div>
    </>
  );
}

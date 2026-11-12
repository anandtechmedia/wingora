import type { ModuleId } from '@wingora/permissions';
import { Card, EmptyState } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';
import { moduleIcons, moduleLabel } from '@/shell/module-meta';
import { PageHeader } from '@/shell/page-header';

export default function ComingSoon({ moduleId }: { moduleId: ModuleId }) {
  const { t } = useI18n();
  const Icon = moduleIcons[moduleId];
  const name = t(moduleLabel(moduleId));
  return (
    <>
      <PageHeader title={name} />
      <Card padding="none">
        <EmptyState icon={<Icon size={22} />} title={t('module.comingSoonTitle', { module: name })} description={t('module.comingSoonDescription')} />
      </Card>
    </>
  );
}

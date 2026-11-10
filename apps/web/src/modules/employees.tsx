import { Card, EmptyState, UsersIcon } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';
import { PageHeader } from '@/shell/page-header';

/** No employee API is consumed yet, so the directory shows its empty state rather than invented data. */
export default function Employees() {
  const { t } = useI18n();
  return (
    <>
      <PageHeader title={t('nav.module.employees')} />
      <Card padding="none">
        <EmptyState icon={<UsersIcon size={22} />} title={t('module.employeesEmptyTitle')} description={t('module.employeesEmptyDescription')} />
      </Card>
    </>
  );
}

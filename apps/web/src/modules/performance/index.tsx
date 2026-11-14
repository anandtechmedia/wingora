import { Permission as P } from '@wingora/permissions';
import { Tabs } from '@/components/ui';
import { PageHeader } from '@/shell/page-header';
import { useCan } from '@/modules/recruitment/shared';
import { HistoryTab } from './history';
import { Meetings } from './meetings';
import { Objectives } from './objectives';
import { Cycles, FeedbackInbox, NoAccess, Reviews } from './reviews';
import { usePerfT } from './shared';

export default function Performance() {
  const { pt, t } = usePerfT();
  const can = useCan();
  const any = can(P.PerformanceView, P.PerformanceReview, P.PerformanceManage);
  const tabs = [
    any && { id: 'objectives', label: pt('objectives'), content: <Objectives /> },
    any && { id: 'reviews', label: pt('reviews'), content: <Reviews /> },
    any && { id: 'feedback', label: pt('feedback360'), content: <FeedbackInbox /> },
    any && { id: 'meetings', label: pt('meetings'), content: <Meetings /> },
    any && { id: 'history', label: pt('history'), content: <HistoryTab /> },
    can(P.PerformanceConfigure, P.PerformanceManage) && { id: 'cycles', label: pt('cycles'), content: <Cycles /> },
  ].filter((x): x is { id: string; label: string; content: React.ReactElement } => Boolean(x));
  return (
    <>
      <PageHeader title={t('nav.module.performance')} description={pt('subtitle')} />
      {tabs.length > 0 ? <Tabs label={t('nav.module.performance')} tabs={tabs} /> : <NoAccess />}
    </>
  );
}

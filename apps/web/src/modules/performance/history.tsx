import { useCallback, useState } from 'react';
import { Permission as P } from '@wingora/permissions';
import { usePerformanceSource, type Person } from '@/api/performance';
import { DataTable, Select } from '@/components/ui';
import { ResourceView, useResource } from '@/modules/employees/shared';
import { useCan } from '@/modules/recruitment/shared';
import { ProgressBar, Section, StatusBadge, usePerfT } from './shared';

export function HistoryTab() {
  const { pt, date } = usePerfT();
  const source = usePerformanceSource();
  const can = useCan();
  const manages = can(P.PerformanceReview, P.PerformanceManage);
  const [employee, setEmployee] = useState('');
  const people = useResource(useCallback((s: AbortSignal) => (manages ? source.people('', s) : Promise.resolve([] as Person[])), [source, manages]));
  const history = useResource(useCallback((s: AbortSignal) => source.history(employee || null, s), [source, employee]));
  return (
    <div className="space-y-4">
      {manages && <div className="max-w-sm"><Select label={pt('employee')} value={employee} onChange={(e) => setEmployee(e.target.value)}
        options={[{ value: '', label: pt('myself') }, ...(people.data ?? []).map((p) => ({ value: p.id, label: p.name }))]} /></div>}
      <ResourceView resource={history} empty={pt('noHistory')}>
        {(h) => (
          <div className="space-y-6">
            <dl className="grid gap-3 sm:grid-cols-4">
              {(['objectives_completed', 'objectives_active', 'meetings_completed', 'feedback_received'] as const).map((k) => (
                <div key={k} className="rounded-lg border border-line bg-surface p-4"><dt className="text-xs text-fg-muted">{pt(`summary.${k}`)}</dt><dd className="text-2xl font-semibold tabular-nums text-fg">{h.summary[k]}</dd></div>
              ))}
            </dl>
            <Section title={pt('reviews')}>
              {h.reviews.length === 0 ? <p className="text-sm text-fg-muted">{pt('noCompletedReviews')}</p> : (
                <DataTable caption={pt('reviews')} hideCaption rows={h.reviews} getRowId={(r) => r.id} columns={[
                  { id: 'cycle', header: pt('cycle'), primary: true, cell: (r) => r.cycle.name },
                  { id: 'period', header: pt('period'), hideBelow: 'md', cell: (r) => `${date(r.cycle.starts_on)} – ${date(r.cycle.ends_on)}` },
                  { id: 'rating', header: pt('rating'), cell: (r) => (r.rating ? `${r.rating} / ${r.cycle.rating_scale}` : '—') },
                  { id: 'manager', header: pt('manager'), hideBelow: 'md', cell: (r) => r.manager?.name ?? '—' },
                  { id: 'status', header: pt('statusLabel'), cell: (r) => <StatusBadge status={r.status} /> },
                ]} />
              )}
            </Section>
            <Section title={pt('objectives')}>
              {h.objectives.length === 0 ? <p className="text-sm text-fg-muted">{pt('noObjectives')}</p> : (
                <DataTable caption={pt('objectives')} hideCaption rows={h.objectives} getRowId={(o) => o.id} columns={[
                  { id: 'title', header: pt('objective'), primary: true, cell: (o) => o.title },
                  { id: 'progress', header: pt('progress'), cell: (o) => <ProgressBar value={o.progress} label={pt('progressOf', { name: o.title })} /> },
                  { id: 'due', header: pt('due'), hideBelow: 'md', cell: (o) => date(o.due_on) },
                  { id: 'status', header: pt('statusLabel'), cell: (o) => <StatusBadge status={o.status} /> },
                ]} />
              )}
            </Section>
          </div>
        )}
      </ResourceView>
    </div>
  );
}

import type { MessageKey } from '@wingora/localization';
import { Badge } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';

type Tone = 'neutral' | 'warning' | 'success' | 'danger' | 'accent' | 'info';
const tones: Record<string, Tone> = {
  draft: 'neutral', active: 'accent', completed: 'success', cancelled: 'neutral', open: 'accent', closed: 'neutral',
  pending_self: 'warning', pending_manager: 'warning', acknowledged: 'success', requested: 'warning', submitted: 'success', declined: 'neutral', scheduled: 'accent',
};

export function usePerfT() {
  const { t, locale } = useI18n();
  const pt = (key: string, params?: Record<string, string | number>) => t(`performance.${key}` as MessageKey, params);
  const date = (iso: string | null | undefined) => (iso ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(new Date(iso.length === 10 ? `${iso}T00:00:00` : iso)) : '—');
  const when = (iso: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
  return { pt, t, date, when };
}

export function StatusBadge({ status }: { status: string }) {
  const { pt } = usePerfT();
  return <Badge size="sm" dot tone={tones[status] ?? 'neutral'}>{pt(`status.${status}`)}</Badge>;
}

export function ProgressBar({ value, label }: { value: number; label: string }) {
  return (
    <div className="flex items-center gap-2">
      <div role="progressbar" aria-label={label} aria-valuenow={value} aria-valuemin={0} aria-valuemax={100} className="h-1.5 w-24 overflow-hidden rounded-full bg-surface-muted">
        <div className="h-full rounded-full bg-accent" style={{ width: `${value}%` }} />
      </div>
      <span className="text-xs tabular-nums text-fg-muted">{value}%</span>
    </div>
  );
}

export function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-2">
      <h3 className="text-sm font-semibold text-fg">{title}</h3>
      {children}
    </section>
  );
}

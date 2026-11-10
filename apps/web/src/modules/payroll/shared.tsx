import { useState } from 'react';
import type { MessageKey } from '@wingora/localization';
import type { LineSource, Money, ReimbursementStatus, RunStatus } from '@/api/payroll';
import { Badge, EmptyState, useToast } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';
import { errorMessage } from '@/modules/employees/shared';

/** Translator for the `payroll.*` catalog plus exact money display (the decimal string comes from the server). */
export function usePayrollT() {
  const { t, locale } = useI18n();
  const pt = (key: string, params?: Record<string, string | number>) => t(`payroll.${key}` as MessageKey, params);
  const money = (m: Money | null | undefined) => (m ? new Intl.NumberFormat(locale, { style: 'currency', currency: m.currency }).format(Number(m.amount)) : '—');
  return { pt, t, locale, money };
}

const sourceTones: Record<LineSource, 'neutral' | 'warning' | 'accent'> = { calculated: 'neutral', manual: 'warning', provider: 'accent' };
/** Where a figure came from: calculated by Wingora, entered by staff, or supplied by a provider. */
export function SourceBadge({ source }: { source: LineSource }) {
  const { pt } = usePayrollT();
  return <Badge tone={sourceTones[source]} variant="outline" size="sm">{pt(`source.${source}`)}</Badge>;
}

const runTones: Record<RunStatus, 'neutral' | 'warning' | 'success' | 'accent'> = { draft: 'neutral', in_review: 'warning', approved: 'success', published: 'accent' };
export function RunStatusBadge({ status }: { status: RunStatus }) {
  const { pt } = usePayrollT();
  return <Badge tone={runTones[status]} dot size="sm">{pt(`status.${status}`)}</Badge>;
}

const claimTones: Record<ReimbursementStatus, 'neutral' | 'warning' | 'success' | 'danger' | 'accent'> = { pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'neutral', paid: 'accent' };
export function ClaimStatusBadge({ status }: { status: ReimbursementStatus }) {
  const { pt } = usePayrollT();
  return <Badge tone={claimTones[status]} dot size="sm">{pt(`claimStatus.${status}`)}</Badge>;
}

export function Disclaimer() {
  const { pt } = usePayrollT();
  return <p role="note" className="rounded-md border border-line bg-surface p-3 text-sm text-fg-muted">{pt('disclaimer')}</p>;
}

export function PayrollNotConnected() {
  const { pt } = usePayrollT();
  return <EmptyState title={pt('notConnectedTitle')} description={pt('notConnectedDescription')} />;
}

/** Runs a one-off action with a busy flag and a success or error toast. */
export function useAction() {
  const { pt } = usePayrollT();
  const { toast } = useToast();
  const [busy, setBusy] = useState<string | null>(null);
  const run = async (key: string, fn: () => Promise<unknown>, done?: string) => {
    setBusy(key);
    try {
      await fn();
      if (done) toast({ title: done, tone: 'success' });
      return true;
    } catch (e) {
      toast({ title: errorMessage(e, pt('actionFailed')), tone: 'danger' });
      return false;
    } finally {
      setBusy(null);
    }
  };
  return { busy, run };
}

export function FileField({ label, accept, onFile }: { label: string; accept: string; onFile: (f: File | null) => void }) {
  return (
    <label className="block text-sm font-medium text-fg sm:col-span-2">
      {label}
      <input type="file" accept={accept} className="mt-1 block w-full text-sm" onChange={(e) => onFile(e.target.files?.[0] ?? null)} />
    </label>
  );
}

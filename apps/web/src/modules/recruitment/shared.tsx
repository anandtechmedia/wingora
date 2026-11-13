import { useState } from 'react';
import type { MessageKey } from '@wingora/localization';
import type { ApplicationStatus, OfferStatus, OpeningStatus, TaskStatus } from '@/api/recruitment';
import { Badge, EmptyState, useToast } from '@/components/ui';
import { useSession } from '@/auth/session';
import { useI18n } from '@/i18n/i18n';
import { errorMessage } from '@/modules/employees/shared';

/** Translators for the `recruitment.*` and `onboarding.*` catalogs. */
export function useRecruitmentT() {
  const { t, locale } = useI18n();
  const rt = (key: string, params?: Record<string, string | number>) => t(`recruitment.${key}` as MessageKey, params);
  const ot = (key: string, params?: Record<string, string | number>) => t(`onboarding.${key}` as MessageKey, params);
  /** An instant shown in the time zone it was scheduled in. */
  const when = (iso: string, timeZone?: string) => new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone }).format(new Date(iso));
  return { rt, ot, t, locale, when };
}

export function useCan() {
  const { permissions } = useSession();
  return (...p: string[]) => p.some((x) => permissions.has(x));
}

type Tone = 'neutral' | 'warning' | 'success' | 'danger' | 'accent';
const openingTones: Record<OpeningStatus, Tone> = { draft: 'neutral', published: 'success', closed: 'neutral' };
const appTones: Record<ApplicationStatus, Tone> = { active: 'accent', rejected: 'danger', withdrawn: 'neutral', hired: 'success' };
const offerTones: Record<OfferStatus, Tone> = { draft: 'neutral', approved: 'accent', sent: 'warning', accepted: 'success', declined: 'danger', withdrawn: 'neutral' };
const taskTones: Record<TaskStatus, Tone> = { pending: 'neutral', submitted: 'warning', completed: 'success', returned: 'danger', skipped: 'neutral' };

export function OpeningStatusBadge({ status }: { status: OpeningStatus }) {
  const { rt } = useRecruitmentT();
  return <Badge tone={openingTones[status]} dot size="sm">{rt(`openingStatus.${status}`)}</Badge>;
}
export function ApplicationStatusBadge({ status }: { status: ApplicationStatus }) {
  const { rt } = useRecruitmentT();
  return <Badge tone={appTones[status]} dot size="sm">{rt(`applicationStatus.${status}`)}</Badge>;
}
export function OfferStatusBadge({ status }: { status: OfferStatus }) {
  const { rt } = useRecruitmentT();
  return <Badge tone={offerTones[status]} variant="outline" size="sm">{rt(`offerStatus.${status}`)}</Badge>;
}
export function TaskStatusBadge({ status, overdue }: { status: TaskStatus; overdue?: boolean }) {
  const { ot } = useRecruitmentT();
  return <span className="inline-flex flex-wrap gap-1"><Badge tone={taskTones[status]} dot size="sm">{ot(`taskStatus.${status}`)}</Badge>
    {overdue && <Badge tone="danger" variant="outline" size="sm">{ot('overdue')}</Badge>}</span>;
}
export function AiBadge() {
  const { rt } = useRecruitmentT();
  return <Badge tone="accent" variant="outline" size="sm">{rt('aiLabel')}</Badge>;
}

export function RecruitmentNotConnected() {
  const { rt } = useRecruitmentT();
  return <EmptyState title={rt('notConnectedTitle')} description={rt('notConnectedDescription')} />;
}

/** Runs a one-off action with a busy key and a success or error toast. */
export function useAction() {
  const { rt } = useRecruitmentT();
  const { toast } = useToast();
  const [busy, setBusy] = useState<string | null>(null);
  const run = async (key: string, fn: () => Promise<unknown>, done?: string) => {
    setBusy(key);
    try {
      await fn();
      if (done) toast({ title: done, tone: 'success' });
      return true;
    } catch (e) {
      toast({ title: errorMessage(e, rt('actionFailed')), tone: 'danger' });
      return false;
    } finally {
      setBusy(null);
    }
  };
  return { busy, run };
}

/** Save handler for dialogs: keeps the error inside the dialog instead of a toast. */
export function useSave() {
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const save = async (fn: () => Promise<unknown>) => {
    setBusy(true);
    setError(null);
    try {
      await fn();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  return { error, busy, save };
}

export const localTimeZone = () => Intl.DateTimeFormat().resolvedOptions().timeZone;
export const todayIso = () => new Date().toISOString().slice(0, 10);

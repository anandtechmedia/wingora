import { useState } from 'react';
import type { MessageKey } from '@wingora/localization';
import type { RequestStatus } from '@/api/time';
import { Badge, Button, Modal } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';
import { errorMessage, FormError, validationErrors } from '@/modules/employees/shared';

/** Translator for the shared `time.*` catalog used by Attendance and Leave. */
export function useTimeT() {
  const { t, locale } = useI18n();
  const tt = (key: string, params?: Record<string, string | number>) => t(`time.${key}` as MessageKey, params);
  return { tt, t, locale };
}

const tones: Record<RequestStatus, 'warning' | 'success' | 'danger' | 'neutral'> = { pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'neutral' };
export function RequestStatusBadge({ status }: { status: RequestStatus }) {
  const { tt } = useTimeT();
  return <Badge tone={tones[status]} dot size="sm">{tt(`status.${status}`)}</Badge>;
}

/** 135 → "2h 15m"; null → "—". */
export function formatMinutes(minutes: number | null | undefined): string {
  if (minutes == null) return '—';
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return h ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m`;
}

/** Calendar date in the browser's zone (`YYYY-MM-DD`); the server decides the employee's own local date. */
export function isoDate(d: Date): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
export const daysAgo = (n: number) => isoDate(new Date(Date.now() - n * 86_400_000));

export function TextArea({ label, value, onChange, error, required }: { label: string; value: string; onChange: (v: string) => void; error?: string; required?: boolean }) {
  return (
    <label className="block text-sm font-medium text-fg sm:col-span-2">
      {label}{required && <span aria-hidden="true" className="text-danger-fg"> *</span>}
      <textarea value={value} onChange={(e) => onChange(e.target.value)} rows={3} required={required} aria-invalid={error ? true : undefined}
        className="mt-1 block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-fg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus" />
      {error && <span className="mt-1 block text-xs text-danger-fg">{error}</span>}
    </label>
  );
}

/** Approve (optional note) or reject (note required, enforced by the server too). */
export function DecisionDialog({ approve, subject, onClose, onDecide }: {
  approve: boolean; subject: string; onClose: () => void; onDecide: (note: string | null) => Promise<unknown>;
}) {
  const { tt, t } = useTimeT();
  const [note, setNote] = useState('');
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const submit = async () => {
    setBusy(true);
    setError(null);
    try {
      await onDecide(note.trim() || null);
      onClose();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };
  const fields = validationErrors(error);
  return (
    <Modal open onClose={onClose} size="sm" title={tt(approve ? 'approveTitle' : 'rejectTitle')} description={subject}
      footer={<><Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        <Button variant={approve ? 'primary' : 'danger'} loading={busy} onClick={submit}>{tt(approve ? 'approve' : 'reject')}</Button></>}>
      <div className="grid gap-3">
        <TextArea label={tt(approve ? 'noteOptional' : 'reasonForRejection')} value={note} onChange={setNote} required={!approve} error={fields.note} />
        <FormError message={Object.keys(fields).length ? null : error ? errorMessage(error, tt('actionFailed')) : null} />
      </div>
    </Modal>
  );
}

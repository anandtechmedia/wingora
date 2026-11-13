import { useState } from 'react';
import { useEmployeeSource, type ImportResult } from '@/api/employees';
import { Button, Modal, useToast } from '@/components/ui';
import { useI18n } from '@/i18n/i18n';
import { errorMessage, validationErrors } from './shared';

/** Dry run first; the real import only runs once every row is valid (the server rejects partial files anyway). */
export function ImportDialog({ open, onClose, onImported }: { open: boolean; onClose: () => void; onImported: () => void }) {
  const { t } = useI18n();
  const source = useEmployeeSource();
  const { toast } = useToast();
  const [file, setFile] = useState<File | null>(null);
  const [result, setResult] = useState<ImportResult | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function run(dryRun: boolean) {
    if (!file) return;
    setBusy(true);
    setError(null);
    try {
      const r = await source.importCsv(file, dryRun);
      setResult(r);
      if (!dryRun) {
        toast({ title: t('employees.importDone', { count: r.created }), tone: 'success' });
        onImported();
      }
    } catch (e) {
      setError(validationErrors(e).file ?? errorMessage(e, t('employees.importFailed')));
    } finally {
      setBusy(false);
    }
  }

  const ready = result?.dry_run && result.invalid === 0 && result.valid > 0;
  return (
    <Modal open={open} onClose={onClose} title={t('employees.importTitle')} description={t('employees.importDescription')}
      footer={<>
        <Button variant="secondary" onClick={onClose}>{t('ui.cancel')}</Button>
        {ready
          ? <Button loading={busy} onClick={() => run(false)}>{t('employees.importRun', { count: result.valid })}</Button>
          : <Button loading={busy} disabled={!file} onClick={() => run(true)}>{t('employees.importCheck')}</Button>}
      </>}>
      <div className="space-y-4">
        <label className="block text-sm font-medium text-fg">
          {t('employees.file')}
          <input type="file" accept=".csv,text/csv" className="mt-1 block w-full text-sm"
            onChange={(e) => { setFile(e.target.files?.[0] ?? null); setResult(null); setError(null); }} />
        </label>
        {error && <p role="alert" className="text-sm text-danger-fg">{error}</p>}
        {result && (
          <div role="status" className="space-y-2 text-sm">
            <p>{t('employees.importSummary', { valid: result.valid, rows: result.rows })}</p>
            {result.errors.length > 0 && (
              <ul className="max-h-60 space-y-1 overflow-auto rounded-md border border-line p-3">
                {result.errors.map((row) => (
                  <li key={row.line}><span className="font-medium">{t('employees.importLine', { line: row.line })}</span>: {row.errors.join(' ')}</li>
                ))}
              </ul>
            )}
          </div>
        )}
      </div>
    </Modal>
  );
}

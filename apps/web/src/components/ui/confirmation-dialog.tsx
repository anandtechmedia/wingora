import { useRef, useState, type ReactNode } from 'react';
import { useI18n } from '@/i18n/i18n';
import { Button } from './button';
import { Modal } from './modal';

export interface ConfirmationDialogProps {
  open: boolean;
  title: ReactNode;
  description: ReactNode;
  onConfirm: () => void | Promise<void>;
  onCancel: () => void;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: 'danger' | 'primary';
  children?: ReactNode;
}

/**
 * Required before destructive actions. Uses role="alertdialog"; for destructive actions focus starts on
 * Cancel so Enter never confirms by accident. Async confirms show a busy state and keep the dialog open.
 */
export function ConfirmationDialog({ open, title, description, onConfirm, onCancel, confirmLabel, cancelLabel, tone = 'danger', children }: ConfirmationDialogProps) {
  const { t } = useI18n();
  const cancelRef = useRef<HTMLButtonElement>(null);
  const confirmRef = useRef<HTMLButtonElement>(null);
  const [busy, setBusy] = useState(false);

  const confirm = async () => {
    setBusy(true);
    try {
      await onConfirm();
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open={open} onClose={busy ? () => undefined : onCancel} title={title} description={description} size="sm" role="alertdialog"
      closeOnOverlayClick={false} hideCloseButton initialFocus={tone === 'danger' ? cancelRef : confirmRef}
      footer={(
        <>
          <Button ref={cancelRef} variant="secondary" onClick={onCancel} disabled={busy}>{cancelLabel ?? t('ui.cancel')}</Button>
          <Button ref={confirmRef} variant={tone === 'danger' ? 'danger' : 'primary'} onClick={confirm} loading={busy}>
            {confirmLabel ?? (tone === 'danger' ? t('ui.delete') : t('ui.confirm'))}
          </Button>
        </>
      )}>
      {children}
    </Modal>
  );
}

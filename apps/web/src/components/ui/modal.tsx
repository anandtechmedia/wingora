import { useEffect, useId, useRef, type ReactNode, type RefObject } from 'react';
import { cn } from '@/lib/cn';
import { Portal } from '@/lib/portal';
import { useFocusTrap } from '@/lib/use-focus-trap';
import { useScrollLock } from '@/lib/use-scroll-lock';
import { useI18n } from '@/i18n/i18n';
import { IconButton } from './button';
import { XIcon } from './icons';

export interface OverlayBaseProps {
  open: boolean;
  onClose: () => void;
  title: ReactNode;
  description?: ReactNode;
  children?: ReactNode;
  footer?: ReactNode;
  /** Element to focus when opened; defaults to the first focusable element. */
  initialFocus?: RefObject<HTMLElement | null>;
  closeOnOverlayClick?: boolean;
  hideCloseButton?: boolean;
  role?: 'dialog' | 'alertdialog';
}

export interface ModalProps extends OverlayBaseProps {
  size?: 'sm' | 'md' | 'lg';
}

const widths = { sm: 'sm:max-w-(--container-modal-sm)', md: 'sm:max-w-(--container-modal-md)', lg: 'sm:max-w-(--container-modal-lg)' } as const;

/** Stack of open dialogs so Escape only closes the top-most one. */
const openDialogs: string[] = [];

/** Shared dialog mechanics: portal, focus trap and restore, Escape to close, scroll lock, labelled by its title. */
export function useDialog({ open, onClose, initialFocus }: Pick<OverlayBaseProps, 'open' | 'onClose' | 'initialFocus'>) {
  const panelRef = useRef<HTMLDivElement>(null);
  const titleId = useId();
  const descriptionId = useId();
  useFocusTrap(panelRef, open, initialFocus);
  useScrollLock(open);
  useEffect(() => {
    if (!open) return;
    openDialogs.push(titleId);
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && openDialogs[openDialogs.length - 1] === titleId) {
        event.stopPropagation();
        onClose();
      }
    };
    document.addEventListener('keydown', onKeyDown);
    return () => {
      document.removeEventListener('keydown', onKeyDown);
      openDialogs.splice(openDialogs.indexOf(titleId), 1);
    };
  }, [open, onClose, titleId]);
  return { panelRef, titleId, descriptionId };
}

export function Modal({ open, onClose, title, description, children, footer, size = 'md', initialFocus, closeOnOverlayClick = true, hideCloseButton, role = 'dialog' }: ModalProps) {
  const { t } = useI18n();
  const { panelRef, titleId, descriptionId } = useDialog({ open, onClose, initialFocus });
  if (!open) return null;

  return (
    <Portal>
      <div className="fixed inset-0 z-(--z-modal) flex items-end justify-center sm:items-center sm:p-6">
        <div className="absolute inset-0 bg-overlay" aria-hidden="true" onClick={closeOnOverlayClick ? onClose : undefined} />
        <div ref={panelRef} role={role} aria-modal="true" aria-labelledby={titleId} aria-describedby={description ? descriptionId : undefined}
          tabIndex={-1}
          className={cn('relative flex max-h-[92dvh] w-full flex-col rounded-t-xl bg-surface shadow-xl sm:rounded-(--modal-radius)', widths[size])}>
          <header className="flex items-start justify-between gap-4 px-(--modal-padding) pt-(--modal-padding) pb-4">
            <div className="flex flex-col gap-1">
              <h2 id={titleId} className="text-lg font-semibold tracking-tight text-fg">{title}</h2>
              {description && <p id={descriptionId} className="text-sm text-fg-secondary">{description}</p>}
            </div>
            {!hideCloseButton && <IconButton label={t('ui.close')} icon={<XIcon />} size="sm" onClick={onClose} className="-me-2 -mt-1" />}
          </header>
          {children && <div className="overflow-y-auto px-(--modal-padding) pb-(--modal-padding) text-sm text-fg">{children}</div>}
          {footer && (
            <footer className="flex flex-col-reverse gap-2 border-t border-line px-(--modal-padding) py-4 sm:flex-row sm:justify-end">{footer}</footer>
          )}
        </div>
      </div>
    </Portal>
  );
}

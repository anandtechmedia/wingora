import { cn } from '@/lib/cn';
import { Portal } from '@/lib/portal';
import { useI18n } from '@/i18n/i18n';
import { IconButton } from './button';
import { XIcon } from './icons';
import { useDialog, type OverlayBaseProps } from './modal';

export interface DrawerProps extends OverlayBaseProps {
  /** Logical side: `end` is the right edge in LTR and the left edge in RTL. */
  side?: 'start' | 'end';
  size?: 'sm' | 'md' | 'lg';
}

const widths = { sm: 'max-w-(--container-drawer-sm)', md: 'max-w-(--container-drawer-md)', lg: 'max-w-(--container-drawer-lg)' } as const;

/** Side panel for details and secondary tasks; also used for mobile navigation. */
export function Drawer({ open, onClose, title, description, children, footer, side = 'end', size = 'md', initialFocus, closeOnOverlayClick = true, hideCloseButton }: DrawerProps) {
  const { t } = useI18n();
  const { panelRef, titleId, descriptionId } = useDialog({ open, onClose, initialFocus });
  if (!open) return null;

  return (
    <Portal>
      <div className="fixed inset-0 z-(--z-drawer)">
        <div className="absolute inset-0 bg-overlay" aria-hidden="true" onClick={closeOnOverlayClick ? onClose : undefined} />
        <div ref={panelRef} role="dialog" aria-modal="true" aria-labelledby={titleId} aria-describedby={description ? descriptionId : undefined}
          tabIndex={-1}
          className={cn('absolute inset-y-0 flex w-full flex-col bg-surface shadow-xl', widths[size], side === 'end' ? 'end-0' : 'start-0')}>
          <header className="flex items-start justify-between gap-4 border-b border-line px-6 py-4">
            <div className="flex flex-col gap-1">
              <h2 id={titleId} className="text-lg font-semibold tracking-tight text-fg">{title}</h2>
              {description && <p id={descriptionId} className="text-sm text-fg-secondary">{description}</p>}
            </div>
            {!hideCloseButton && <IconButton label={t('ui.close')} icon={<XIcon />} size="sm" onClick={onClose} className="-me-2" />}
          </header>
          <div className="flex-1 overflow-y-auto px-6 py-5 text-sm text-fg">{children}</div>
          {footer && <footer className="flex justify-end gap-2 border-t border-line px-6 py-4">{footer}</footer>}
        </div>
      </div>
    </Portal>
  );
}

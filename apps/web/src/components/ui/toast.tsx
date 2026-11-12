import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { components } from '@wingora/design-tokens';
import { cn } from '@/lib/cn';
import { useI18n } from '@/i18n/i18n';
import { AlertCircleIcon, AlertTriangleIcon, CheckCircleIcon, InfoIcon, XIcon } from './icons';

export type ToastTone = 'success' | 'info' | 'warning' | 'danger';

export interface ToastOptions {
  title: string;
  description?: string;
  tone?: ToastTone;
  /** Milliseconds; `null` keeps it until dismissed. Danger toasts persist by default (WCAG 2.2.1). */
  duration?: number | null;
  action?: { label: string; onClick: () => void };
}

interface ToastRecord extends ToastOptions {
  id: string;
}

interface ToastApi {
  toast: (options: ToastOptions) => string;
  dismiss: (id: string) => void;
}

const ToastContext = createContext<ToastApi | null>(null);

const icons = { success: CheckCircleIcon, info: InfoIcon, warning: AlertTriangleIcon, danger: AlertCircleIcon } as const;
const accents = { success: 'text-success-solid', info: 'text-info-solid', warning: 'text-warning-solid', danger: 'text-danger-solid' } as const;
let counter = 0;

/**
 * Notifications. Two always-mounted live regions: polite for success/info/warning, assertive for danger.
 * Auto-dismiss pauses while hovered or focused.
 */
export function ToastProvider({ children }: { children: ReactNode }) {
  const { t } = useI18n();
  const [toasts, setToasts] = useState<ToastRecord[]>([]);
  const dismiss = useCallback((id: string) => setToasts((all) => all.filter((x) => x.id !== id)), []);
  const toast = useCallback((options: ToastOptions) => {
    const id = `toast-${++counter}`;
    setToasts((all) => [...all.slice(-4), { id, ...options }]);
    return id;
  }, []);
  const api = useMemo(() => ({ toast, dismiss }), [toast, dismiss]);

  const region = (assertive: boolean) => (
    <ol aria-live={assertive ? 'assertive' : 'polite'} aria-relevant="additions" className="flex flex-col gap-2">
      {toasts.filter((x) => (x.tone === 'danger') === assertive).map((x) => <ToastItem key={x.id} toast={x} onDismiss={dismiss} dismissLabel={t('ui.dismiss')} />)}
    </ol>
  );

  return (
    <ToastContext.Provider value={api}>
      {children}
      <section aria-label={t('ui.notifications')}
        className="pointer-events-none fixed inset-x-4 bottom-4 z-(--z-toast) flex flex-col gap-2 sm:inset-x-auto sm:end-6 sm:bottom-6 sm:w-(--container-toast)">
        {region(false)}
        {region(true)}
      </section>
    </ToastContext.Provider>
  );
}

function ToastItem({ toast, onDismiss, dismissLabel }: { toast: ToastRecord; onDismiss: (id: string) => void; dismissLabel: string }) {
  const tone = toast.tone ?? 'info';
  const Icon = icons[tone];
  const duration = toast.duration === undefined ? (tone === 'danger' ? null : Number(components.toast['duration-ms'])) : toast.duration;
  const [paused, setPaused] = useState(false);
  const remaining = useRef(duration ?? 0);
  const itemRef = useRef<HTMLLIElement>(null);

  useEffect(() => {
    const el = itemRef.current;
    if (!el) return;
    const pause = () => setPaused(true);
    const resume = () => setPaused(false);
    const events: Array<[string, () => void]> = [['mouseenter', pause], ['mouseleave', resume], ['focusin', pause], ['focusout', resume]];
    events.forEach(([name, fn]) => el.addEventListener(name, fn));
    return () => events.forEach(([name, fn]) => el.removeEventListener(name, fn));
  }, []);

  useEffect(() => {
    if (duration === null || paused) return;
    const started = Date.now();
    const timer = setTimeout(() => onDismiss(toast.id), remaining.current);
    return () => {
      clearTimeout(timer);
      remaining.current -= Date.now() - started;
    };
  }, [duration, paused, onDismiss, toast.id]);

  return (
    <li ref={itemRef} role={tone === 'danger' ? 'alert' : 'status'}
      className="pointer-events-auto flex items-start gap-3 rounded-(--toast-radius) border border-line bg-surface p-4 shadow-(--toast-shadow)">
      <Icon size={18} className={cn('mt-px shrink-0', accents[tone])} />
      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
        <p className="text-sm font-semibold text-fg">{toast.title}</p>
        {toast.description && <p className="text-sm text-fg-secondary">{toast.description}</p>}
        {toast.action && (
          <button type="button" onClick={() => { toast.action!.onClick(); onDismiss(toast.id); }}
            className="mt-1 self-start text-sm font-medium text-fg-link hover:underline">{toast.action.label}</button>
        )}
      </div>
      <button type="button" onClick={() => onDismiss(toast.id)} aria-label={dismissLabel}
        className="-me-1 -mt-1 inline-flex size-7 shrink-0 items-center justify-center rounded-md text-fg-muted hover:bg-surface-muted hover:text-fg">
        <XIcon />
      </button>
    </li>
  );
}

export function useToast(): ToastApi {
  const ctx = useContext(ToastContext);
  if (!ctx) throw new Error('useToast must be used inside <ToastProvider>.');
  return ctx;
}

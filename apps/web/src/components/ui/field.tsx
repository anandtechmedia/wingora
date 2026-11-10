import { useId, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useI18n } from '@/i18n/i18n';
import { AlertCircleIcon } from './icons';

export interface FieldBaseProps {
  label: ReactNode;
  /** Visually hides the label while keeping it available to assistive technology. */
  hideLabel?: boolean;
  hint?: ReactNode;
  error?: ReactNode;
  required?: boolean;
  optional?: boolean;
  id?: string;
}

export interface FieldControlProps {
  id: string;
  'aria-describedby'?: string;
  'aria-invalid'?: true;
  'aria-required'?: true;
  required?: boolean;
}

/** Wires label, hint and error to the control by id. Errors are announced and never rely on colour alone. */
export function Field({ label, hideLabel, hint, error, required, optional, id: idProp, className, children }: FieldBaseProps & {
  className?: string;
  children: (control: FieldControlProps) => ReactNode;
}) {
  const { t } = useI18n();
  const generated = useId();
  const id = idProp ?? `field-${generated}`;
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [errorId, hintId].filter(Boolean).join(' ') || undefined;

  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <label htmlFor={id} className={cn('text-sm font-medium text-fg', hideLabel && 'sr-only')}>
        {label}
        {required && <span className="ms-0.5 text-danger-fg" aria-hidden="true">*</span>}
        {optional && !required && <span className="ms-1.5 text-xs font-normal text-fg-muted">({t('ui.optional')})</span>}
      </label>
      {children({ id, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined, required })}
      {hint && !error && <p id={hintId} className="text-xs text-fg-muted">{hint}</p>}
      {error && (
        <p id={errorId} className="flex items-start gap-1 text-xs font-medium text-danger-fg">
          <AlertCircleIcon size={14} className="mt-px shrink-0" />
          <span>{error}</span>
        </p>
      )}
    </div>
  );
}

export type ControlSize = 'sm' | 'md' | 'lg';

/** Shared control styling. 16px text below `sm` prevents iOS zoom on focus. */
export const controlClasses = (size: ControlSize = 'md', invalid = false) =>
  cn(
    'w-full rounded-md border bg-surface text-fg placeholder:text-fg-muted shadow-xs',
    'transition-colors duration-(--duration-fast) ease-standard',
    'focus-visible:border-focus focus-visible:outline-2 focus-visible:outline-offset-0 focus-visible:outline-focus',
    'disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-fg-disabled',
    invalid ? 'border-danger-solid' : 'border-line-control',
    size === 'sm' && 'h-(--control-height-sm) px-2.5 text-md sm:text-xs',
    size === 'md' && 'h-(--control-height-md) px-(--control-padding-x) text-md sm:text-sm',
    size === 'lg' && 'h-(--control-height-lg) px-(--control-padding-x) text-md',
  );

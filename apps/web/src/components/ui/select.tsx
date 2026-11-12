import { forwardRef, type SelectHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';
import { useI18n } from '@/i18n/i18n';
import { controlClasses, Field, type ControlSize, type FieldBaseProps } from './field';
import { ChevronDownIcon } from './icons';

export interface SelectOption {
  value: string;
  label: string;
  disabled?: boolean;
}

export interface SelectProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'size' | 'id'>, FieldBaseProps {
  options: SelectOption[];
  placeholder?: string;
  size?: ControlSize;
  className?: string;
}

/** Native <select>: full keyboard, screen-reader and mobile picker support with no extra JavaScript. */
export const Select = forwardRef<HTMLSelectElement, SelectProps>(function Select(
  { label, hideLabel, hint, error, required, optional, id, options, placeholder, size = 'md', className, ...props },
  ref,
) {
  const { t } = useI18n();
  return (
    <Field label={label} hideLabel={hideLabel} hint={hint} error={error} required={required} optional={optional} id={id} className={className}>
      {(control) => (
        <div className="relative">
          <select ref={ref} {...control} {...props} className={cn(controlClasses(size, Boolean(error)), 'appearance-none pe-9')}>
            {(placeholder !== undefined || props.value === '' || props.defaultValue === undefined) && (
              <option value="" disabled={required}>{placeholder ?? t('ui.selectPlaceholder')}</option>
            )}
            {options.map((o) => <option key={o.value} value={o.value} disabled={o.disabled}>{o.label}</option>)}
          </select>
          <ChevronDownIcon className="pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 text-fg-muted" />
        </div>
      )}
    </Field>
  );
});
